<?php

namespace App\Http\Controllers\Api\Supplier;

use App\Http\Controllers\Controller;
use App\Models\MasterOrder;
use App\Models\Notification;
use App\Models\OrderItem;
use App\Models\ShortageReport;
use App\Models\SupplierOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\FcmService;
use App\Services\WhatsAppService;
use App\Services\NotificationService;



class SupplierOrderController extends Controller
{
    // =========================================================
    // GET /api/v1/supplier/orders
    // Supplier sees all orders assigned to them.
    // =========================================================

    public function __construct(private NotificationService $notifier) {}

    public function index(Request $request): JsonResponse
    {
        $supplier = $request->user()->supplier;
        if (! $supplier) {
            return response()->json(['message' => 'Supplier account not found.'], 404);
        }

        $query = SupplierOrder::with([
            'masterOrder:id,order_number,pharmacy_branch_id',
            'masterOrder.pharmacyBranch:id,name,address,phone',
        ])
        ->where('supplier_id', $supplier->id);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $orders = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'message' => 'Orders retrieved successfully.',
            'data'    => $orders,
        ], 200);
    }

    // =========================================================
    // GET /api/v1/supplier/orders/{id}
    // Full detail of one supplier order with all drug lines.
    // =========================================================
    public function show(Request $request, int $id): JsonResponse
    {
        $supplier = $request->user()->supplier;

        $order = SupplierOrder::with([
            'orderItems.drug:id,name,trade_name,dosage_form,strength',
            'masterOrder.pharmacyBranch:id,name,address,phone',
        ])
        ->where('id', $id)
        ->where('supplier_id', $supplier->id)
        ->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return response()->json([
            'message' => 'Order retrieved successfully.',
            'data'    => $this->formatSupplierOrder($order),
        ], 200);
    }

    // =========================================================
    // POST /api/v1/supplier/orders/{id}/confirm
    // Supplier confirms they can fulfil the order.
    // They confirm each item's quantity — may be less than
    // requested if they are short on specific drugs.
    //
    // Request body:
    //   items: [
    //     { order_item_id: 1, quantity_confirmed: 100 },
    //     { order_item_id: 2, quantity_confirmed: 50  },
    //   ]
    // =========================================================
  
    // ============================================================
// PATCH — Replace the confirm() method in
// app/Http/Controllers/Api/Supplier/SupplierOrderController.php
//
// Fixes:
//   1. Validate that ALL items in the body belong to this order
//   2. Validate that ALL order items are covered in the body
//   3. Deduct confirmed quantities from supplier_inventory
//   4. Set master_order_id on order_items created by allocation
// ============================================================

    public function confirm(Request $request, int $id): JsonResponse
    {
        $supplier = $request->user()->supplier;

        $order = SupplierOrder::with('orderItems')
            ->where('id', $id)
            ->where('supplier_id', $supplier->id)
            ->where('status', 'pending')
            ->first();

        if (! $order) {
            return response()->json([
                'message' => 'Order not found or already confirmed.',
            ], 404);
        }

        $request->validate([
            'items'                      => ['required', 'array', 'min:1'],
            'items.*.order_item_id'      => ['required', 'integer'],
            'items.*.quantity_confirmed' => ['required', 'integer', 'min:0'],
        ]);

        // ── Fix 1: Validate all submitted items belong to this order ──
        $orderItemIds = $order->orderItems->pluck('id')->toArray();
        $submittedIds = collect($request->items)->pluck('order_item_id')->toArray();

        // Check for items in body that don't belong to this order
        $invalidIds = array_diff($submittedIds, $orderItemIds);
        if (! empty($invalidIds)) {
            return response()->json([
                'message' => 'Some items in your request do not belong to this order.',
                'errors'  => [
                    'items' => [
                        'Invalid order item IDs: ' . implode(', ', $invalidIds) .
                        '. Valid IDs for this order are: ' . implode(', ', $orderItemIds),
                    ],
                ],
            ], 422);
        }

        // ── Fix 2: Validate all order items are covered in the body ──
        $missingIds = array_diff($orderItemIds, $submittedIds);
        if (! empty($missingIds)) {
            $missingDrugs = $order->orderItems
                ->whereIn('id', $missingIds)
                ->map(fn($i) => $i->drug?->trade_name ?? $i->drug_name_raw ?? "item #{$i->id}")
                ->implode(', ');

            return response()->json([
                'message' => 'Your confirmation is missing some order items. All items must be confirmed.',
                'errors'  => [
                    'items' => [
                        "Missing items: {$missingDrugs}. " .
                        'Please include all ' . count($orderItemIds) . ' items in your confirmation.',
                    ],
                ],
            ], 422);
        }

        // ── All validations passed — process confirmation ─────────────
        DB::transaction(function () use ($request, $order, $supplier) {
            $subtotal      = 0;
            $hasShortage   = false;
            $shortageItems = [];

            foreach ($request->items as $itemData) {
                $item = $order->orderItems->firstWhere('id', $itemData['order_item_id']);
                if (! $item) continue;

                $confirmed = (int)$itemData['quantity_confirmed'];
                $short     = $item->quantity_requested - $confirmed;

                // Line total uses confirmed quantity
                $lineTotal = round(
                    $confirmed * $item->unit_price * (1 - $item->discount_pct / 100), 2
                );

                // ── Fix 3: Update supplier_inventory stock ────────────
                // Deduct confirmed quantity from supplier's available stock
                if ($confirmed > 0 && $item->drug_id) {
                    \App\Models\SupplierInventory::where('supplier_id', $supplier->id)
                        ->where('drug_id', $item->drug_id)
                        ->decrement('quantity_available', $confirmed);
                }

                // ── Fix 4: Set master_order_id on the order item ──────
                $item->update([
                    'master_order_id'    => $order->master_order_id,
                    'quantity_confirmed' => $confirmed,
                    'line_total'         => $lineTotal,
                    'status'             => $confirmed >= $item->quantity_requested
                        ? 'confirmed'
                        : ($confirmed > 0 ? 'confirmed' : 'short'),
                ]);

                $subtotal += $lineTotal;

                // Track shortages
                if ($short > 0) {
                    $hasShortage     = true;
                    $shortageItems[] = [
                        'supplier_order_id' => $order->id,
                        'drug_id'           => $item->drug_id,
                        'drug_name_raw'     => $item->drug_name_raw,
                        'quantity_short'    => $short,
                        'resolved'          => 0,
                    ];
                }
            }

            // Update supplier order totals and status
            $commissionValue = round($subtotal * $order->commission_pct / 100, 2);
            $order->update([
                'status'            => $hasShortage ? 'partially_available' : 'confirmed',
                'subtotal'          => $subtotal,
                'commission_value'  => $commissionValue,
                'confirmed_at'      => now(),
            ]);

            // Create shortage reports
            foreach ($shortageItems as $shortage) {
                \App\Models\ShortageReport::create($shortage);
            }

            // Update master order status
            $this->updateMasterOrderStatus($order->master_order_id, $hasShortage);

            // Notify pharmacy
            //$this->notifyPharmacy($order, $hasShortage);
            //$this->notifier->orderConfirmedForPharmacy($order, $hasShortage);
        });

        $order->refresh();
        $this->notifier->orderConfirmedForPharmacy($order, $order->status === 'partially_available');

        return response()->json([
            'message' => 'Order confirmed successfully.',
            'data'    => [
                'id'       => $order->id,
                'status'   => $order->status,
                'subtotal' => $order->subtotal,
            ],
        ], 200);
    }


    // =========================================================
    // POST /api/v1/supplier/orders/{id}/report-shortage
    // Supplier reports they cannot fulfil specific items
    // AFTER confirming (e.g. found stock issue at warehouse).
    // =========================================================
    public function reportShortage(Request $request, int $id): JsonResponse
    {
        $supplier = $request->user()->supplier;

        $order = SupplierOrder::where('id', $id)
            ->where('supplier_id', $supplier->id)
            ->whereIn('status', ['confirmed', 'partially_available'])
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found or not confirmable.'], 404);
        }

        $request->validate([
            'items'                       => ['required', 'array', 'min:1'],
            'items.*.order_item_id'       => ['required', 'integer', 'exists:order_items,id'],
            'items.*.quantity_short'      => ['required', 'integer', 'min:1'],
            'items.*.notes'               => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($request, $order) {
            foreach ($request->items as $itemData) {
                $item = OrderItem::where('id', $itemData['order_item_id'])
                    ->where('supplier_order_id', $order->id)
                    ->first();

                if (! $item) continue;

                // Update the item status
                $item->update([
                    'status'             => 'short',
                    'quantity_confirmed' => max(0, $item->quantity_confirmed - $itemData['quantity_short']),
                ]);

                // Create or update shortage report
                ShortageReport::updateOrCreate(
                    [
                        'supplier_order_id' => $order->id,
                        'drug_id'           => $item->drug_id,
                    ],
                    [
                        'quantity_short' => $itemData['quantity_short'],
                        'notes'          => $itemData['notes'] ?? null,
                        'resolved'       => 0,
                    ]
                );
            }

            $order->update(['status' => 'partially_available']);
            $this->updateMasterOrderStatus($order->master_order_id, true);
            $this->notifyPharmacyShortage($order);
        });

        $this->notifier->orderConfirmedForPharmacy($order->fresh(), true);
        return response()->json([
            'message' => 'Shortage reported successfully. The pharmacy has been notified.',
            'data'    => ['order_status' => 'partially_available'],
        ], 200);
    }

    // =========================================================
    // POST /api/v1/supplier/orders/{id}/ship
    // Supplier marks the order as shipped.
    // =========================================================
    public function ship(Request $request, int $id): JsonResponse
    {
        $supplier = $request->user()->supplier;

        $order = SupplierOrder::where('id', $id)
            ->where('supplier_id', $supplier->id)
            ->where('status', 'confirmed')
            ->first();

        if (! $order) {
            return response()->json([
                'message' => 'Order not found or not in confirmed state.',
            ], 404);
        }

        $order->update([
            'status'     => 'shipped',
            'shipped_at' => now(),
        ]);

        // Update master order if all supplier orders are shipped
        $allShipped = SupplierOrder::where('master_order_id', $order->master_order_id)
            ->whereNotIn('status', ['shipped', 'delivered', 'delivery_confirmed', 'cancelled'])
            ->doesntExist();

        if ($allShipped) {
            MasterOrder::where('id', $order->master_order_id)
                ->update(['status' => 'shipped']);
        }

        // Notify pharmacy
        $masterOrder = MasterOrder::with('pharmacyBranch.user')
            ->find($order->master_order_id);

        if ($masterOrder?->pharmacyBranch?->user) {
            /*Notification::create([
                'user_id'         => $masterOrder->pharmacyBranch->user->id,
                'title'           => 'Order shipped',
                'body'            => "Order {$order->order_number} has been shipped.",
                'type'            => 'order_shipped',
                'channel'         => 'push',
                'notifiable_type' => 'SupplierOrder',
                'notifiable_id'   => $order->id,
                'is_read'         => 0,
            ]);*/
            $this->notifier->orderShippedForPharmacy($order);
        }

        return response()->json([
            'message' => 'Order marked as shipped.',
            'data'    => [
                'id'         => $order->id,
                'status'     => 'shipped',
                'shipped_at' => $order->shipped_at,
            ],
        ], 200);
    }

    // =========================================================
    // PATCH /api/v1/supplier/orders/{id}/deliver
    // Marks a supplier order as delivered.
    // =========================================================
    public function deliver(Request $request, int $id): JsonResponse
    {
        $supplier = $request->user()->supplier;

        $order = SupplierOrder::where('id', $id)
            ->where('supplier_id', $supplier->id)
            ->where('status', 'shipped')
            ->first();

        if (! $order) {
            return response()->json([
                'message' => 'Order not found or not in shipped state.',
            ], 404);
        }

        $order->update([
            'status'       => 'delivered',
            'delivered_at' => now(),
        ]);

        // Check if pharmacy already confirmed → close fully
        $masterOrder              = MasterOrder::find($order->master_order_id);
        $pharmacyAlreadyConfirmed = ! is_null($masterOrder?->pharmacy_confirmed_at);

        $allSuppliersDone = SupplierOrder::where('master_order_id', $order->master_order_id)
            ->whereNotIn('status', ['delivered', 'delivery_confirmed', 'cancelled'])
            ->doesntExist();

        if ($pharmacyAlreadyConfirmed && $allSuppliersDone) {
            MasterOrder::where('id', $order->master_order_id)
                ->update(['status' => 'delivery_confirmed']);
        } elseif ($allSuppliersDone) {
            MasterOrder::where('id', $order->master_order_id)
                ->update(['status' => 'delivered']);
        }

        // Notify pharmacy
        $masterOrder = MasterOrder::with('pharmacyBranch.user')
            ->find($order->master_order_id);

        if ($masterOrder?->pharmacyBranch?->user) {
            /*Notification::create([
                'user_id'         => $masterOrder->pharmacyBranch->user->id,
                'title'           => 'Order delivered',
                'body'            => "Order {$order->order_number} has been delivered.",
                'type'            => 'order_delivered',
                'channel'         => 'push',
                'notifiable_type' => 'SupplierOrder',
                'notifiable_id'   => $order->id,
                'is_read'         => 0,
            ]);*/
            $this->notifier->orderDeliveredForPharmacy($order);
        }

        return response()->json([
            'message' => 'Order marked as delivered.',
            'data'    => ['id' => $order->id, 'status' => 'delivered', 'delivered_at' => $order->delivered_at],
        ], 200);
    }

    // =========================================================
    // PRIVATE — Update master order status based on
    // the collective state of all its supplier orders
    // =========================================================
    private function updateMasterOrderStatus(int $masterOrderId, bool $hasShortage): void
    {
        $supplierOrders = SupplierOrder::where('master_order_id', $masterOrderId)->get();

        $statuses   = $supplierOrders->pluck('status')->toArray();
        $allConfirmed = collect($statuses)->every(fn($s) => in_array($s, ['confirmed', 'cancelled']));
        $anyPartial   = in_array('partially_available', $statuses);
        $anyPending   = in_array('pending', $statuses);

        if ($anyPartial || $hasShortage) {
            $newStatus = 'partially_available';
        } elseif ($allConfirmed && ! $anyPending) {
            $newStatus = 'confirmed';
        } else {
            $newStatus = 'pending_supplier_confirmation';
        }

        MasterOrder::where('id', $masterOrderId)->update(['status' => $newStatus]);
    }

    // =========================================================
    // PRIVATE — Notify pharmacy when supplier confirms
    // =========================================================
    private function notifyPharmacy(SupplierOrder $order, bool $hasShortage): void
    {
        $masterOrder = MasterOrder::with('pharmacyBranch.user')->find($order->master_order_id);
        if (! $masterOrder?->pharmacyBranch?->user) return;

        $title = $hasShortage ? 'Partial shortage reported' : 'Order confirmed by supplier';
        $body  = $hasShortage
            ? "Supplier confirmed part of order {$order->order_number} with some shortages."
            : "Supplier confirmed order {$order->order_number} in full.";

        Notification::create([
            'user_id'         => $masterOrder->pharmacyBranch->user->id,
            'title'           => $title,
            'body'            => $body,
            'type'            => $hasShortage ? 'shortage_reported' : 'order_confirmed',
            'channel'         => 'push',
            'notifiable_type' => 'MasterOrder',
            'notifiable_id'   => $order->master_order_id,
            'is_read'         => 0,
        ]);
    }

    // =========================================================
    // PRIVATE — Notify pharmacy of shortage after confirmation
    // =========================================================
    private function notifyPharmacyShortage(SupplierOrder $order): void
    {
        $masterOrder = MasterOrder::with('pharmacyBranch.user')->find($order->master_order_id);
        if (! $masterOrder?->pharmacyBranch?->user) return;

        Notification::create([
            'user_id'         => $masterOrder->pharmacyBranch->user->id,
            'title'           => 'Shortage reported on your order',
            'body'            => "Supplier reported a shortage on order {$order->order_number}. Please review.",
            'type'            => 'shortage_reported',
            'channel'         => 'push',
            'notifiable_type' => 'MasterOrder',
            'notifiable_id'   => $order->master_order_id,
            'is_read'         => 0,
        ]);
    }

    // =========================================================
    // PRIVATE — Format supplier order for response
    // =========================================================
    private function formatSupplierOrder(SupplierOrder $order): array
    {
        return [
            'id'              => $order->id,
            'order_number'    => $order->order_number,
            'status'          => $order->status,
            'subtotal'        => $order->subtotal,
            'commission_pct'  => $order->commission_pct,
            'commission_value'=> $order->commission_value,
            'confirmed_at'    => $order->confirmed_at,
            'shipped_at'      => $order->shipped_at,
            'delivered_at'    => $order->delivered_at,
            'pharmacy_branch' => $order->masterOrder?->pharmacyBranch ? [
                'name'    => $order->masterOrder->pharmacyBranch->name,
                'address' => $order->masterOrder->pharmacyBranch->address,
                'phone'   => $order->masterOrder->pharmacyBranch->phone,
            ] : null,
            'items' => $order->orderItems->map(fn($item) => [
                'id'                 => $item->id,
                'drug_name'          => $item->drug?->trade_name ?? $item->drug_name_raw,
                'dosage_form'        => $item->drug?->dosage_form,
                'strength'           => $item->drug?->strength,
                'quantity_requested' => $item->quantity_requested,
                'quantity_confirmed' => $item->quantity_confirmed,
                'unit_price'         => $item->unit_price,
                'discount_pct'       => $item->discount_pct,
                'line_total'         => $item->line_total,
                'status'             => $item->status,
            ]),
        ];
    }
}