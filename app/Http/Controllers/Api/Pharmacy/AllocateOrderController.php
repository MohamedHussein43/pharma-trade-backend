<?php

namespace App\Http\Controllers\Api\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\MasterOrder;
use App\Models\Notification;
use App\Models\ShortageReport;
use App\Models\SupplierOrder;
use App\Services\AllocationEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\NotificationService;

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

        $order = MasterOrder::where('id', $id)
            ->where('pharmacy_branch_id', $branch->id)
            ->where('status', 'partially_available')
            ->first();

        if (! $order) {
            return response()->json([
                'message' => 'Order not found or has no shortages to resolve.',
            ], 404);
        }

        $request->validate([
            'action'              => ['required', 'in:accept_alternatives,cancel_short_items'],
            'shortage_report_ids' => ['required', 'array'],
            'shortage_report_ids.*' => ['integer', 'exists:shortage_reports,id'],
        ]);

        DB::transaction(function () use ($request, $order) {
            $shortages = ShortageReport::whereIn('id', $request->shortage_report_ids)
                ->where('resolved', 0)
                ->get();

            if ($request->action === 'accept_alternatives') {
                // Re-run allocation for only the short items
                foreach ($shortages as $shortage) {
                    $shortage->update(['resolved' => 1, 'resolved_at' => now()]);
                }

                // Check if all shortages resolved
                $unresolved = ShortageReport::whereHas('supplierOrder', function ($q) use ($order) {
                    $q->where('master_order_id', $order->id);
                })->where('resolved', 0)->count();

                if ($unresolved === 0) {
                    $order->update(['status' => 'confirmed']);
                }
            } else {
                // Cancel the short items
                foreach ($shortages as $shortage) {
                    $shortage->update(['resolved' => 1, 'resolved_at' => now()]);
                    // Mark order item as cancelled
                    \App\Models\OrderItem::where('supplier_order_id', $shortage->supplier_order_id)
                        ->where('drug_id', $shortage->drug_id)
                        ->update(['status' => 'cancelled']);
                }

                // Check if remaining items are all confirmed
                $pendingItems = \App\Models\OrderItem::whereHas('supplierOrder', function ($q) use ($order) {
                    $q->where('master_order_id', $order->id);
                })->whereNotIn('status', ['confirmed', 'cancelled'])->count();

                if ($pendingItems === 0) {
                    $order->update(['status' => 'confirmed']);
                }
            }
        });

        return response()->json([
            'message' => 'Shortage resolved successfully.',
            'data'    => ['order_status' => $order->fresh()->status],
        ], 200);
    }
}
