<?php

namespace App\Http\Controllers\Api\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\MasterOrder;
use App\Models\SupplierOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderTrackingController extends Controller
{
    // =========================================================
    // GET /api/v1/pharmacy/orders/{id}/track
    // Full order tracking timeline with all status changes
    // and timestamps for each supplier split
    // =========================================================
    public function track(Request $request, int $id): JsonResponse
    {
        $branch = $request->user()->pharmacyBranch;

        $order = MasterOrder::with([
            'supplierOrders.supplier:id,name',
            'supplierOrders.orderItems.drug:id,trade_name,dosage_form,strength',
        ])
        ->where('id', $id)
        ->where('pharmacy_branch_id', $branch->id)
        ->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        // Build timeline events from timestamps
        $timeline = $this->buildTimeline($order);

        return response()->json([
            'message' => 'Order tracking retrieved.',
            'data'    => [
                'id'              => $order->id,
                'order_number'    => $order->order_number,
                'status'          => $order->status,
                'order_mode'      => $order->order_mode,
                'total_value'     => $order->total_value,
                'created_at'      => $order->created_at,
                'timeline'        => $timeline,
                'supplier_splits' => $order->supplierOrders->map(fn($so) => [
                    'id'                    => $so->id,
                    'order_number'          => $so->order_number,
                    'supplier'              => $so->supplier->name,
                    'status'                => $so->status,
                    'subtotal'              => $so->subtotal,
                    'confirmed_at'          => $so->confirmed_at,
                    'shipped_at'            => $so->shipped_at,
                    'delivered_at'          => $so->delivered_at,
                    'pharmacy_confirmed_at' => $so->pharmacy_confirmed_at,
                    'items_count'           => $so->orderItems->count(),
                    'items'                 => $so->orderItems->map(fn($item) => [
                        'drug_name'          => $item->drug?->trade_name ?? $item->drug_name_raw,
                        'dosage_form'        => $item->drug?->dosage_form,
                        'strength'           => $item->drug?->strength,
                        'quantity_requested' => $item->quantity_requested,
                        'quantity_confirmed' => $item->quantity_confirmed,
                        'unit_price'         => $item->unit_price,
                        'line_total'         => $item->line_total,
                        'status'             => $item->status,
                    ]),
                ]),
            ],
        ], 200);
    }

    // =========================================================
    // GET /api/v1/supplier/orders/{id}/track
    // Supplier tracking view — their portion only
    // =========================================================
    public function trackSupplierOrder(Request $request, int $id): JsonResponse
    {
        $supplier     = $request->user()->supplier;
        $supplierOrder = SupplierOrder::with([
            'orderItems.drug:id,trade_name,dosage_form,strength',
            'masterOrder.pharmacyBranch:id,name,address,phone',
        ])
        ->where('id', $id)
        ->where('supplier_id', $supplier->id)
        ->first();

        if (! $supplierOrder) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $timeline = [
            ['status' => 'pending',   'label' => 'Order received',       'timestamp' => $supplierOrder->created_at,   'done' => true],
            ['status' => 'confirmed', 'label' => 'You confirmed',         'timestamp' => $supplierOrder->confirmed_at, 'done' => ! is_null($supplierOrder->confirmed_at)],
            ['status' => 'shipped',   'label' => 'You shipped',           'timestamp' => $supplierOrder->shipped_at,   'done' => ! is_null($supplierOrder->shipped_at)],
            ['status' => 'delivered', 'label' => 'You marked delivered',  'timestamp' => $supplierOrder->delivered_at, 'done' => ! is_null($supplierOrder->delivered_at)],
            ['status' => 'delivery_confirmed', 'label' => 'Pharmacy confirmed receipt', 'timestamp' => $supplierOrder->pharmacy_confirmed_at, 'done' => ! is_null($supplierOrder->pharmacy_confirmed_at)],
        ];

        return response()->json([
            'message' => 'Order tracking retrieved.',
            'data'    => [
                'id'                    => $supplierOrder->id,
                'order_number'          => $supplierOrder->order_number,
                'status'                => $supplierOrder->status,
                'subtotal'              => $supplierOrder->subtotal,
                'commission_value'      => $supplierOrder->commission_value,
                'timeline'              => $timeline,
                'pharmacy_branch'       => [
                    'name'    => $supplierOrder->masterOrder?->pharmacyBranch?->name,
                    'address' => $supplierOrder->masterOrder?->pharmacyBranch?->address,
                    'phone'   => $supplierOrder->masterOrder?->pharmacyBranch?->phone,
                ],
                'items'                 => $supplierOrder->orderItems->map(fn($item) => [
                    'drug_name'          => $item->drug?->trade_name ?? $item->drug_name_raw,
                    'quantity_requested' => $item->quantity_requested,
                    'quantity_confirmed' => $item->quantity_confirmed,
                    'unit_price'         => $item->unit_price,
                    'line_total'         => $item->line_total,
                    'status'             => $item->status,
                ]),
            ],
        ], 200);
    }

    // =========================================================
    // PRIVATE — Build pharmacy-facing tracking timeline
    // =========================================================
    private function buildTimeline(MasterOrder $order): array
    {
        $events = [
            [
                'status'    => 'draft',
                'label'     => 'Order created',
                'timestamp' => $order->created_at,
                'done'      => true,
            ],
            [
                'status'    => 'pending_supplier_confirmation',
                'label'     => 'Sent to suppliers',
                'timestamp' => $order->updated_at,
                'done'      => ! in_array($order->status, ['draft', 'cancelled']),
            ],
        ];

        // Get earliest confirmed_at across all supplier orders
        $confirmedAt = $order->supplierOrders->whereNotNull('confirmed_at')->min('confirmed_at');
        $shippedAt   = $order->supplierOrders->whereNotNull('shipped_at')->min('shipped_at');
        $deliveredAt = $order->supplierOrders->whereNotNull('delivered_at')->min('delivered_at');

        $events[] = [
            'status'    => 'confirmed',
            'label'     => 'Suppliers confirmed',
            'timestamp' => $confirmedAt,
            'done'      => ! is_null($confirmedAt),
        ];

        $events[] = [
            'status'    => 'shipped',
            'label'     => 'Order shipped',
            'timestamp' => $shippedAt,
            'done'      => ! is_null($shippedAt),
        ];

        $events[] = [
            'status'    => 'delivered',
            'label'     => 'Order delivered',
            'timestamp' => $deliveredAt,
            'done'      => ! is_null($deliveredAt),
        ];

        $events[] = [
            'status'    => 'delivery_confirmed',
            'label'     => 'You confirmed receipt',
            'timestamp' => $order->pharmacy_confirmed_at,
            'done'      => ! is_null($order->pharmacy_confirmed_at),
        ];

        return $events;
    }
}
