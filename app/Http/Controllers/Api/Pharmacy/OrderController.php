<?php

namespace App\Http\Controllers\Api\Pharmacy;


use App\Http\Controllers\Controller;
use App\Models\Drug;
use App\Models\MasterOrder;
use App\Models\OrderItem;
use App\Models\SupplierInventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class OrderController extends Controller
{
    // =========================================================
    // GET /api/v1/pharmacy/orders
    // Returns paginated list of orders for this branch.
    // =========================================================
    public function index(Request $request): JsonResponse
    {
        $branch = $request->user()->pharmacyBranch;
        if (! $branch) {
            return response()->json(['message' => 'Branch not found.'], 404);
        }

        $query = MasterOrder::where('pharmacy_branch_id', $branch->id)
            ->withCount('supplierOrders');

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
    // GET /api/v1/pharmacy/orders/{id}
    // Full order detail with all supplier splits and items.
    // =========================================================
    public function show(Request $request, int $id): JsonResponse
    {
        $branch = $request->user()->pharmacyBranch;

        $order = MasterOrder::with([
            'supplierOrders.supplier:id,name',
            'supplierOrders.orderItems.drug:id,name,trade_name,dosage_form,strength',
        ])
        ->where('id', $id)
        ->where('pharmacy_branch_id', $branch->id)
        ->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        return response()->json([
            'message' => 'Order retrieved successfully.',
            'data'    => $this->formatOrder($order),
        ], 200);
    }

    // =========================================================
    // POST /api/v1/pharmacy/orders
    // Creates a new draft master order.
    // Items can be added immediately OR via a separate call.
    // =========================================================
    public function store(Request $request): JsonResponse
    {
        $branch = $request->user()->pharmacyBranch;
        if (! $branch) {
            return response()->json(['message' => 'Branch not found.'], 404);
        }

        $request->validate([
            'order_mode'       => ['required', 'in:specific_supplier,best_discount'],
            'notes'            => ['nullable', 'string', 'max:500'],
            'items'            => ['nullable', 'array'],
            'items.*.drug_id'  => ['required_with:items', 'integer', 'exists:drugs,id'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
        ], [
            'items.*.drug_id.exists'  => 'One or more selected drugs do not exist.',
            'items.*.quantity.min'    => 'Quantity must be at least 1.',
        ]);

        $order = DB::transaction(function () use ($request, $branch) {
            $order = MasterOrder::create([
                'pharmacy_branch_id' => $branch->id,
                'created_by'         => $request->user()->id,
                'order_number'       => $this->generateOrderNumber(),
                'order_mode'         => $request->order_mode,
                'status'             => 'draft',
                'total_value'        => 0,
                'notes'              => $request->notes,
            ]);

            // Add items if provided with the create call
            if ($request->filled('items')) {
                foreach ($request->items as $item) {
                    OrderItem::create([
                        'master_order_id'    => $order->id,
                        'drug_id'            => $item['drug_id'],
                        'quantity_requested' => $item['quantity'],
                        'status'             => 'pending',
                    ]);
                }
            }

            return $order;
        });

        return response()->json([
            'message' => 'Order created successfully.',
            'data'    => [
                'id'           => $order->id,
                'order_number' => $order->order_number,
                'status'       => $order->status,
                'order_mode'   => $order->order_mode,
            ],
        ], 201);
    }

    // =========================================================
    // POST /api/v1/pharmacy/orders/{id}/items
    // Add drug lines to a draft order one at a time.
    // =========================================================
    public function addItem(Request $request, int $id): JsonResponse
    {
        $branch = $request->user()->pharmacyBranch;
        $order  = MasterOrder::where('id', $id)
            ->where('pharmacy_branch_id', $branch->id)
            ->where('status', 'draft')
            ->first();

        if (! $order) {
            return response()->json([
                'message' => 'Draft order not found. Only draft orders can be edited.',
            ], 404);
        }

        $request->validate([
            'drug_id'  => ['required', 'integer', 'exists:drugs,id'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        // ── Quantity validation against available stock ───────
        // Get total available stock for this drug across ALL
        // eligible suppliers in the pharmacy's zone
        $branchZoneIds = $branch->zones()->pluck('zones.id')->toArray();

        $eligibleSupplierIds = \App\Models\Supplier::where('is_active', 1)
            ->where('approval_status', 'approved')
            ->whereHas('zones', fn($q) => $q->whereIn('zones.id', $branchZoneIds))
            ->pluck('id')
            ->toArray();

        $totalAvailableStock = \App\Models\SupplierInventory::whereIn('supplier_id', $eligibleSupplierIds)
            ->where('drug_id', $request->drug_id)
            ->sum('quantity_available');

        // Get quantity already in this draft order for this drug
        $alreadyInOrder = OrderItem::whereNull('supplier_order_id')
            ->where('master_order_id', $order->id)
            ->where('drug_id', $request->drug_id)
            ->value('quantity_requested') ?? 0;

        // Get the minimum order_limit set by any supplier for
        // this drug (most restrictive limit wins)
        $limitedSupplier = \App\Models\SupplierInventory::whereIn('supplier_id', $eligibleSupplierIds)
            ->where('drug_id', $request->drug_id)
            ->whereNotNull('order_limit')
            ->orderBy('order_limit', 'asc')
            ->first();

        $totalAfterAdd = $alreadyInOrder + $request->quantity;

        if ($limitedSupplier) {
            $effectiveLimit  = $limitedSupplier->order_limit;

            if ($totalAfterAdd > $effectiveLimit) {
                $drug = Drug::find($request->drug_id);
                return response()->json([
                    'message' => "Order limit exceeded for '{$drug->trade_name}'.",
                    'errors'  => [
                        'quantity' => [
                            "This drug has a maximum order limit of {$effectiveLimit} units per order. " .
                            "You already have {$alreadyInOrder} in this order. " .
                            "Maximum you can still add: " . max(0, $effectiveLimit - $alreadyInOrder) . " units.",
                        ],
                    ],
                    'data' => [
                        'drug_id'          => $request->drug_id,
                        'drug_name'        => $drug->trade_name ?? $drug->name,
                        'order_limit'      => $effectiveLimit,
                        'already_in_order' => $alreadyInOrder,
                        'max_can_add'      => max(0, $effectiveLimit - $alreadyInOrder),
                    ],
                ], 422);
            }
        }

        if ($totalAvailableStock <= 0) {
            $drug = Drug::find($request->drug_id);
            return response()->json([
                'message' => "'{$drug->trade_name}' is currently out of stock across all suppliers in your zone.",
                'errors'  => [
                    'quantity' => ["This drug has no available stock."],
                ],
            ], 422);
        }

        if ($totalAfterAdd > $totalAvailableStock) {
            $drug = Drug::find($request->drug_id);
            return response()->json([
                'message' => "Requested quantity exceeds available stock for '{$drug->trade_name}'.",
                'errors'  => [
                    'quantity' => [
                        "Available stock: {$totalAvailableStock} units. " .
                        "You already have {$alreadyInOrder} in this order. " .
                        "Maximum you can add: " . max(0, $totalAvailableStock - $alreadyInOrder) . " units.",
                    ],
                ],
                'data' => [
                    'drug_id'               => $request->drug_id,
                    'drug_name'             => $drug->trade_name ?? $drug->name,
                    'total_available_stock' => $totalAvailableStock,
                    'already_in_order'      => $alreadyInOrder,
                    'max_can_add'           => max(0, $totalAvailableStock - $alreadyInOrder),
                ],
            ], 422);
        }

        // ── Add or increment item ─────────────────────────────
        $existing = OrderItem::whereNull('supplier_order_id')
            ->where('master_order_id', $order->id)
            ->where('drug_id', $request->drug_id)
            ->first();

        if ($existing) {
            $existing->update([
                'quantity_requested' => $existing->quantity_requested + $request->quantity,
            ]);
            $item = $existing;
        } else {
            $item = OrderItem::create([
                'master_order_id'    => $order->id,
                'supplier_order_id'  => null,
                'drug_id'            => $request->drug_id,
                'drug_name_raw'      => Drug::find($request->drug_id)?->trade_name,
                'quantity_requested' => $request->quantity,
                'quantity_confirmed' => 0,
                'unit_price'         => 0,
                'discount_pct'       => 0,
                'line_total'         => 0,
                'status'             => 'pending',
            ]);
        }

        $drug = Drug::find($request->drug_id);

        return response()->json([
            'message' => 'Item added to order.',
            'data'    => [
                'id'                    => $item->id,
                'drug_id'               => $item->drug_id,
                'drug_name'             => $drug->trade_name ?? $drug->name,
                'quantity_requested'    => $item->quantity_requested,
                'total_available_stock' => $totalAvailableStock,
            ],
        ], 200);
    }

    // =========================================================
    // DELETE /api/v1/pharmacy/orders/{id}/items/{item_id}
    // Remove a line from a draft order.
    // =========================================================
    public function removeItem(Request $request, int $id, int $itemId): JsonResponse
    {
        $branch = $request->user()->pharmacyBranch;
        $order  = MasterOrder::where('id', $id)
            ->where('pharmacy_branch_id', $branch->id)
            ->where('status', 'draft')
            ->first();

        if (! $order) {
            return response()->json([
                'message' => 'Draft order not found.',
            ], 404);
        }

        $item = OrderItem::where('id', $itemId)
            ->where('master_order_id', $order->id)
            ->whereNull('supplier_order_id')
            ->first();

        if (! $item) {
            return response()->json(['message' => 'Item not found.'], 404);
        }

        $item->delete();

        return response()->json(['message' => 'Item removed from order.'], 200);
    }

    // =========================================================
    // POST /api/v1/pharmacy/orders/{id}/upload
    // Upload Excel shortage list to populate a draft order.
    // Each row becomes an order item.
    // =========================================================
    public function uploadItems(Request $request, int $id): JsonResponse
    {
        $branch = $request->user()->pharmacyBranch;
        $order  = MasterOrder::where('id', $id)
            ->where('pharmacy_branch_id', $branch->id)
            ->where('status', 'draft')
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Draft order not found.'], 404);
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
        ]);

        $fullPath = $request->file('file')->getRealPath();
        $sheets   = Excel::toArray([], $fullPath);
        $rows     = $sheets[0] ?? [];

        if (empty($rows)) {
            return response()->json(['message' => 'File is empty.'], 422);
        }

        $headers = array_map(
            fn($h) => strtolower(trim(str_replace([' ', '-'], '_', (string)$h))),
            $rows[0]
        );

        $dataRows = array_filter(
            array_slice($rows, 1),
            fn($row) => count(array_filter(array_map('strval', $row))) > 0
        );

        $added   = 0;
        $skipped = 0;
        $errors  = [];

        foreach (array_values($dataRows) as $index => $row) {
            $rowNum = $index + 2;
            $data   = [];
            foreach ($headers as $colIndex => $header) {
                $data[$header] = isset($row[$colIndex]) ? trim((string)$row[$colIndex]) : null;
            }

            $drugName = $data['drug_name'] ?? $data['name'] ?? $data['medicine'] ?? null;
            $quantity = $data['quantity'] ?? $data['qty'] ?? '1';

            if (empty($drugName)) {
                $errors[] = "Row {$rowNum}: Drug name is empty.";
                $skipped++;
                continue;
            }

            if (! is_numeric($quantity) || (int)$quantity < 1) {
                $errors[] = "Row {$rowNum}: Invalid quantity for '{$drugName}'.";
                $skipped++;
                continue;
            }

            // Find drug in master catalog
            $drug = Drug::where('is_active', 1)
                ->where(function ($q) use ($drugName) {
                    $q->whereRaw('LOWER(trade_name) = ?', [strtolower($drugName)])
                      ->orWhereRaw('LOWER(name) = ?',       [strtolower($drugName)])
                      ->orWhereRaw('LOWER(trade_name) LIKE ?', [strtolower($drugName) . '%']);
                })
                ->first();

            if (! $drug) {
                $errors[] = "Row {$rowNum}: '{$drugName}' not found in the drug catalog — skipped.";
                $skipped++;
                continue;
            }

            // Add or increment existing item
            $existing = OrderItem::where('master_order_id', $order->id)
                ->where('drug_id', $drug->id)
                ->whereNull('supplier_order_id')
                ->first();

            if ($existing) {
                $existing->update([
                    'quantity_requested' => $existing->quantity_requested + (int)$quantity,
                ]);
            } else {
                OrderItem::create([
                    'master_order_id'    => $order->id,
                    'drug_id'            => $drug->id,
                    'quantity_requested' => (int)$quantity,
                    'status'             => 'pending',
                ]);
            }

            $added++;
        }

        return response()->json([
            'message' => 'Shortage list uploaded.',
            'data'    => [
                'items_added'   => $added,
                'items_skipped' => $skipped,
                'errors'        => $errors,
            ],
        ], 200);
    }

    // =========================================================
    // PATCH /api/v1/pharmacy/orders/{id}/cancel
    // Cancel a draft or pending order.
    // =========================================================
    public function cancel(Request $request, int $id): JsonResponse
    {
        $branch = $request->user()->pharmacyBranch;
        $order  = MasterOrder::where('id', $id)
            ->where('pharmacy_branch_id', $branch->id)
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $cancellable = ['draft', 'pending_supplier_confirmation', 'partially_available'];
        if (! in_array($order->status, $cancellable)) {
            return response()->json([
                'message' => "Cannot cancel an order with status '{$order->status}'.",
            ], 422);
        }

        $order->update(['status' => 'cancelled']);

        return response()->json([
            'message' => 'Order cancelled successfully.',
            'data'    => ['id' => $order->id, 'status' => 'cancelled'],
        ], 200);
    }

    // =========================================================
    // PRIVATE
    // =========================================================
    private function generateOrderNumber(): string
    {
        $year   = date('Y');
        $latest = MasterOrder::whereYear('created_at', $year)->count() + 1;
        return 'ORD-' . $year . '-' . str_pad($latest, 5, '0', STR_PAD_LEFT);
    }

    private function formatOrder(MasterOrder $order): array
    {
        return [
            'id'            => $order->id,
            'order_number'  => $order->order_number,
            'order_mode'    => $order->order_mode,
            'status'        => $order->status,
            'total_value'   => $order->total_value,
            'notes'         => $order->notes,
            'created_at'    => $order->created_at,
            'supplier_orders' => $order->supplierOrders->map(fn($so) => [
                'id'           => $so->id,
                'order_number' => $so->order_number,
                'supplier'     => $so->supplier,
                'status'       => $so->status,
                'subtotal'     => $so->subtotal,
                'items'        => $so->orderItems->map(fn($item) => [
                    'id'                 => $item->id,
                    'drug_name'          => $item->drug?->trade_name ?? $item->drug_name_raw,
                    'quantity_requested' => $item->quantity_requested,
                    'quantity_confirmed' => $item->quantity_confirmed,
                    'unit_price'         => $item->unit_price,
                    'discount_pct'       => $item->discount_pct,
                    'line_total'         => $item->line_total,
                    'status'             => $item->status,
                ]),
            ]),
        ];
    }
}
