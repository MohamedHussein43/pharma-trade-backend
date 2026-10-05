<?php

namespace App\Http\Controllers\Api\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\MasterOrder;
use App\Models\Notification;
use App\Models\ShortageReport;
use App\Models\SupplierOrder;
USE App\Models\OrderItem;
use App\Services\AllocationEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;

class AllocateOrderController extends Controller
{
    public function __construct(
        private AllocationEngine $engine,
        private NotificationService  $notifier
        
        ) {}

    // =========================================================
    // POST /api/v1/pharmacy/orders/{id}/allocate
    // Triggers the allocation engine on a draft order.
    // Returns the allocation recommendation for pharmacy review.
    // =========================================================
    public function allocate(Request $request, int $id): JsonResponse
    {
        $branch = $request->user()->pharmacyBranch;

        $order = MasterOrder::with(['orderItems.drug'])
            ->where('id', $id)
            ->where('pharmacy_branch_id', $branch->id)
            ->where('status', 'draft')
            ->first();

        if (! $order) {
            return response()->json([
                'message' => 'Draft order not found. Only draft orders can be allocated.',
            ], 404);
        }

        if ($order->orderItems->isEmpty()) {
            return response()->json([
                'message' => 'Cannot allocate an empty order. Please add items first.',
            ], 422);
        }

        // Specific supplier mode — supplier_id required
        if ($order->order_mode === 'specific_supplier') {
            $request->validate([
                'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            ]);
            // Store supplier_id temporarily in notes for the engine
            // Sprint 3 note: add dedicated supplier_id column later
            $order->update(['notes' => $request->supplier_id]);
        }

        try {
             // ── Validate stock availability before allocating ─────
        $branchZoneIds = $branch->zones()->pluck('zones.id')->toArray();

        $eligibleSupplierIds = \App\Models\Supplier::where('is_active', 1)
            ->where('approval_status', 'approved')
            ->whereHas('zones', fn($q) => $q->whereIn('zones.id', $branchZoneIds))
            ->pluck('id')
            ->toArray();

        $stockErrors = [];

         foreach ($order->orderItems as $orderItem) {
            if (! $orderItem->drug_id) continue;

            // Check order_limit
            $limitedSupplier = \App\Models\SupplierInventory::whereIn('supplier_id', $eligibleSupplierIds)
                ->where('drug_id', $orderItem->drug_id)
                ->whereNotNull('order_limit')
                ->orderBy('order_limit', 'asc')
                ->first();

            if ($limitedSupplier && $orderItem->quantity_requested > $limitedSupplier->order_limit) {
                $stockErrors[] = "'{$orderItem->drug?->trade_name}': order limit is {$limitedSupplier->order_limit} units but {$orderItem->quantity_requested} were requested.";
                continue;
            }

            // Check available stock
            $availableStock = \App\Models\SupplierInventory::whereIn('supplier_id', $eligibleSupplierIds)
                ->where('drug_id', $orderItem->drug_id)
                ->sum('quantity_available');

            if ($availableStock <= 0) {
                $stockErrors[] = "'{$orderItem->drug?->trade_name}' is out of stock.";
            } elseif ($orderItem->quantity_requested > $availableStock) {
                $stockErrors[] = "'{$orderItem->drug?->trade_name}': requested {$orderItem->quantity_requested} but only {$availableStock} available.";
            }
        }

        if (! empty($stockErrors)) {
            return response()->json([
                'message' => 'Cannot allocate — some items exceed available stock or limit issues.',
                'errors'  => ['stock' => $stockErrors],
            ], 422);
        }
        // ── End stock validation — now run the engine ─────────
        
            $order = $this->engine->allocate($order);

            return response()->json([
                'message' => 'Order allocated successfully. Please review and confirm.',
                'data'    => [
                    'id'              => $order->id,
                    'order_number'    => $order->order_number,
                    'status'          => $order->status,
                    'total_value'     => $order->total_value,
                    'supplier_orders' => $order->supplierOrders->map(fn($so) => [
                        'id'           => $so->id,
                        'supplier'     => ['id' => $so->supplier->id, 'name' => $so->supplier->name],
                        'order_number' => $so->order_number,
                        'subtotal'     => $so->subtotal,
                        'items_count'  => $so->orderItems->count(),
                        'items'        => $so->orderItems->map(fn($item) => [
                            'drug_name'          => $item->drug?->trade_name ?? $item->drug_name_raw,
                            'quantity_requested' => $item->quantity_requested,
                            'unit_price'         => $item->unit_price,
                            'discount_pct'       => $item->discount_pct,
                            'line_total'         => $item->line_total,
                        ]),
                    ]),
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    // =========================================================
    // POST /api/v1/pharmacy/orders/{id}/confirm
    // Pharmacy confirms the allocation after reviewing it.
    // Status moves to pending_supplier_confirmation.
    // Suppliers are already notified during allocation.
    // =========================================================
    public function confirm(Request $request, int $id): JsonResponse
    {
        $branch = $request->user()->pharmacyBranch;

        $order = MasterOrder::where('id', $id)
            ->where('pharmacy_branch_id', $branch->id)
            ->where('status', 'pending_supplier_confirmation')
            ->first();

        if (! $order) {
            return response()->json([
                'message' => 'Order not found or not in a confirmable state.',
            ], 404);
        }

        // Order is already in pending_supplier_confirmation
        // Pharmacy has reviewed the split and confirms it
        return response()->json([
            'message' => 'Order confirmed. Waiting for supplier confirmations.',
            'data'    => [
                'id'           => $order->id,
                'order_number' => $order->order_number,
                'status'       => $order->status,
                'total_value'  => $order->total_value,
            ],
        ], 200);
    }

    // =========================================================
    // POST /api/v1/pharmacy/orders/{id}/resolve-shortage
    // Called after a supplier reports a shortage.
    // Pharmacy reviews alternative recommendations and
    // either accepts or cancels the short items.
    // =========================================================
    public function resolveShortage(Request $request, int $id): JsonResponse
    {
        $branch = $request->user()->pharmacyBranch;

        if (! $branch) {
            return response()->json(['message' => 'Pharmacy branch not found.'], 403);
        }

        $request->validate([
            'action'                => ['required', 'in:accept_alternatives,cancel_short_items'],
            'shortage_report_ids'   => ['sometimes', 'array'],
            'shortage_report_ids.*' => ['integer'],
        ]);

        $order = MasterOrder::with([
            'supplierOrders.orderItems.drug',
            'supplierOrders.shortageReports',   // ← through supplierOrders
        ])
            ->where('id', $id)
            ->where('pharmacy_branch_id', $branch->id)
            ->whereIn('status', [
                'shortage',
                'partially_available',
                'pending_supplier_confirmation',
            ])
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found or not in shortage state.'], 404);
        }

        $action = $request->action;

        DB::transaction(function () use ($request, $order, $action) {

            // Collect all unresolved shortage reports across all supplier orders
            $unresolvedShortages = collect();
            foreach ($order->supplierOrders as $so) {
                $unresolvedShortages = $unresolvedShortages->merge(
                    $so->shortageReports->where('resolved', 0)
                );
            }

            // Filter by submitted IDs if provided
            if ($request->filled('shortage_report_ids')) {
                $unresolvedShortages = $unresolvedShortages
                    ->whereIn('id', $request->shortage_report_ids);
            }

            if ($unresolvedShortages->isEmpty()) {
                return;
            }

            if ($action === 'cancel_short_items') {
                    // Mark all shortage reports resolved
                    foreach ($unresolvedShortages as $shortage) {
                        $shortage->update(['resolved' => 1, 'resolution' => 'cancelled']);
                    }

                    // Cancel all order items and supplier orders
                    foreach ($order->supplierOrders as $so) {
                        $so->orderItems()->update(['status' => 'cancelled', 'line_total' => 0]);
                        $so->update([
                            'status'           => 'cancelled',
                            'subtotal'         => 0,
                            'commission_value' => 0,
                        ]);
                    }

                    // Cancel the master order
                    $order->update([
                        'status'      => 'cancelled',
                        'total_value' => 0,
                    ]);
                } else {
                // ── accept_alternatives: reduce quantity_requested ────────
                // KEY FIX: set quantity_requested = accepted qty so supplier
                // re-confirm() sees short = 0 and doesn't create new shortage

                foreach ($unresolvedShortages as $shortage) {
                    $item = $this->findOrderItem($order, $shortage);

                    if ($item) {
                        $acceptedQty = max(0, $item->quantity_requested - $shortage->quantity_short);

                        $item->update([
                            'quantity_requested' => $acceptedQty,
                            'quantity_confirmed' => $acceptedQty,
                            'line_total'         => round($acceptedQty * $item->unit_price, 2),
                            'status'             => $acceptedQty > 0 ? 'pending' : 'cancelled',
                        ]);
                    }

                    $shortage->update(['resolved' => 1, 'resolution' => 'accepted']);
                }

                // Reset affected supplier orders to pending for re-confirmation
                foreach ($order->supplierOrders as $so) {
                    $freshItems = $so->orderItems()->get();
                    $hasActive  = $freshItems->contains(
                        fn($i) => in_array($i->status, ['pending', 'confirmed'])
                    );

                    if ($hasActive) {
                        $so->update([
                            'status'       => 'pending',
                            'confirmed_at' => null,
                        ]);
                    }
                }

                // Recalculate master order total
                $newTotal = OrderItem::whereHas('supplierOrder', fn($q) =>
                    $q->where('master_order_id', $order->id)
                    ->where('status', '!=', 'cancelled')
                )->whereNotIn('status', ['cancelled'])->sum('line_total');

                $order->update([
                    'status'      => 'pending_supplier_confirmation',
                    'total_value' => round($newTotal, 2),
                ]);
            }
        });

        $order->refresh();

        return response()->json([
            'message' => $action === 'accept_alternatives'
                ? 'Shortage accepted. Supplier will re-confirm the order.'
                : 'Short items cancelled. Order confirmed with available items.',
            'data' => [
                'id'          => $order->id,
                'status'      => $order->status,
                'total_value' => $order->total_value,
            ],
        ]);
    }

    // ── Private helper: find order item matching a shortage report ──
    private function findOrderItem(MasterOrder $order, $shortage): ?\App\Models\OrderItem
    {
        foreach ($order->supplierOrders as $so) {
            $item = $so->orderItems->first(function ($i) use ($shortage) {
                if ($shortage->drug_id && $i->drug_id === $shortage->drug_id) return true;
                if ($shortage->drug_name_raw && $i->drug_name_raw === $shortage->drug_name_raw) return true;
                return false;
            });
            if ($item) return $item;
        }
        return null;
    }
}
