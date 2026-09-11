<?php

namespace App\Http\Controllers\Api\Pharmacy;

use App\Http\Controllers\Controller;
use App\Models\MasterOrder;
use App\Models\Notification;
use App\Models\Supplier;
use App\Models\SupplierInventory;
use App\Models\SupplierOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\NotificationService;

class PharmacyController extends Controller
{
    // =========================================================
    // GET /api/v1/pharmacy/branch
    // =========================================================
    public function __construct(private NotificationService $notifier) {}
    
    public function branch(Request $request): JsonResponse
    {
        $user   = $request->user();
        $branch = $user->pharmacyBranch()
            ->with([
                'pharmacy:id,name,licence_number',
                'zones:id,name,governorate',
            ])
            ->first();

        if (! $branch) {
            return response()->json([
                'message' => 'Branch not found for this user.',
            ], 404);
        }

        return response()->json([
            'message' => 'Branch profile retrieved successfully.',
            'data'    => [
                'id'              => $branch->id,
                'name'            => $branch->name,
                'address'         => $branch->address,
                'phone'           => $branch->phone,
                'licence_number'  => $branch->licence_number,
                'is_active'       => $branch->is_active,
                'approval_status' => $branch->approval_status,
                'zones'           => $branch->zones->map(fn($z) => [
                    'id'          => $z->id,
                    'name'        => $z->name,
                    'governorate' => $z->governorate,
                ]),
                'pharmacy'        => $branch->pharmacy,
            ],
        ], 200);
    }

    // =========================================================
    // GET /api/v1/pharmacy/suppliers
    // =========================================================
    public function suppliers(Request $request): JsonResponse
    {
        $user   = $request->user();
        $branch = $user->pharmacyBranch;

        if (! $branch) {
            return response()->json(['message' => 'Branch not found.'], 404);
        }

        $branchZoneIds = $branch->zones()->pluck('zones.id')->toArray();

        if (empty($branchZoneIds)) {
            return response()->json([
                'message' => 'Your branch has no zones assigned.',
                'data'    => [],
            ], 200);
        }

        $suppliers = Supplier::with(['zones:id,name,governorate'])
            ->where('is_active', 1)
            ->where('approval_status', 'approved')
            ->whereHas('zones', function ($q) use ($branchZoneIds) {
                $q->whereIn('zones.id', $branchZoneIds);
            })
            ->get()
            ->map(function ($supplier) {
                $inventoryCount = SupplierInventory::where('supplier_id', $supplier->id)
                    ->where('quantity_available', '>', 0)
                    ->count();
                $lastUpdate = SupplierInventory::where('supplier_id', $supplier->id)
                    ->max('last_updated');

                return [
                    'id'                    => $supplier->id,
                    'name'                  => $supplier->name,
                    'min_order_value'       => $supplier->min_order_value,
                    'min_order_qty'         => $supplier->min_order_qty,
                    'zones'                 => $supplier->zones,
                    'inventory_count'       => $inventoryCount,
                    'last_inventory_update' => $lastUpdate,
                ];
            });

        return response()->json([
            'message' => 'Suppliers retrieved successfully.',
            'data'    => $suppliers,
        ], 200);
    }

    // =========================================================
    // GET /api/v1/pharmacy/suppliers/{id}/inventory
    // Returns what a specific supplier has in stock.
    // Used when browsing before placing an order.
    // =========================================================
    public function supplierInventory(Request $request, int $supplierId): JsonResponse
    {
        $user   = $request->user();
        $branch = $user->pharmacyBranch;

        if (! $branch) {
            return response()->json(['message' => 'Branch not found.'], 404);
        }

        $branchZoneIds = $branch->zones()->pluck('zones.id')->toArray();

        $supplier = Supplier::where('id', $supplierId)
            ->where('is_active', 1)
            ->whereHas('zones', function ($q) use ($branchZoneIds) {
                $q->whereIn('zones.id', $branchZoneIds);
            })
            ->first();

        if (! $supplier) {
            return response()->json([
                'message' => 'Supplier not found or does not serve your zone.',
            ], 404);
        }

        $query = SupplierInventory::with('drug:id,name,trade_name,dosage_form,strength')
            ->where('supplier_id', $supplierId)
            ->where('quantity_available', '>', 0);  // ← ALWAYS enforced

        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('drug_name_raw', 'LIKE', "%{$term}%")
                  ->orWhereHas('drug', function ($q2) use ($term) {
                      $q2->where('trade_name', 'LIKE', "%{$term}%")
                         ->orWhere('name',      'LIKE', "%{$term}%");
                  });
            });
        }

        $inventory = $query
            ->orderBy('discount_pct', 'desc')  // highest discount first
            ->paginate($request->get('per_page', 30));

        $inventory->getCollection()->transform(fn($item) => [
            'id'                 => $item->id,
            'drug_id'            => $item->drug_id,
            'drug_name'          => $item->drug?->trade_name ?? $item->drug_name_raw,
            'drug_name_raw'      => $item->drug_name_raw,
            'dosage_form'        => $item->drug?->dosage_form,
            'strength'           => $item->drug?->strength,
            'quantity_available' => $item->quantity_available,
            'order_limit'        => $item->order_limit,
            'unit_price'         => $item->unit_price,
            'public_price'       => $item->public_price,
            'pharmacist_price'   => $item->pharmacist_price,
            'discount_pct'       => $item->discount_pct,
            'effective_price'    => round(
                (float)$item->pharmacist_price * (1 - (float)$item->discount_pct / 100), 2
            ),
            'savings_per_unit'   => round(
                (float)$item->public_price - ((float)$item->pharmacist_price * (1 - (float)$item->discount_pct / 100)), 2
            ),
            'last_updated'       => $item->last_updated,
        ]);

        return response()->json([
            'message' => 'Supplier inventory retrieved successfully.',
            'data'    => [
                'supplier'  => [
                    'id'              => $supplier->id,
                    'name'            => $supplier->name,
                    'min_order_value' => $supplier->min_order_value,
                    'min_order_qty'   => $supplier->min_order_qty,
                ],
                'inventory' => $inventory,
            ],
        ], 200);
    }
    // =========================================================
    // GET /api/v1/pharmacy/suppliers/drugs
    // NEW ENDPOINT
    //
    // Returns ALL drugs available from ALL suppliers in the
    // pharmacy's zone, deduplicated by drug_id, with the best
    // available price across all suppliers shown.
    //
    // Purpose: pharmacy browses the full catalog of what is
    // actually available to them and adds drugs one by one to
    // a draft order — without needing to know which supplier
    // has what.
    //
    // Query params:
    //   ?search=       — filter by drug name
    //   ?per_page=     — default 30
    //   ?supplier_id=  — filter to one specific supplier
    // =========================================================
     public function availableDrugs(Request $request): JsonResponse
    {
        $user   = $request->user();
        $branch = $user->pharmacyBranch;

        if (! $branch) {
            return response()->json(['message' => 'Branch not found.'], 404);
        }

        $branchZoneIds = $branch->zones()->pluck('zones.id')->toArray();

        if (empty($branchZoneIds)) {
            return response()->json([
                'message' => 'Your branch has no zones assigned.',
                'data'    => [],
            ], 200);
        }

        // Get eligible supplier IDs in this pharmacy's zone
        $suppliersQuery = Supplier::where('is_active', 1)
            ->where('approval_status', 'approved')
            ->whereHas('zones', function ($q) use ($branchZoneIds) {
                $q->whereIn('zones.id', $branchZoneIds);
            });

        if ($request->filled('supplier_id')) {
            $suppliersQuery->where('id', (int)$request->supplier_id);
        }

        $eligibleSupplierIds = $suppliersQuery->pluck('id')->toArray();

        if (empty($eligibleSupplierIds)) {
            return response()->json([
                'message' => 'No suppliers found in your zone.',
                'data'    => [],
            ], 200);
        }

        // Build query
        $query = SupplierInventory::with([
            'drug:id,name,trade_name,scientific_name,dosage_form,strength',
            'supplier:id,name,min_order_value,min_order_qty',
        ])
        ->whereIn('supplier_id', $eligibleSupplierIds)
        ->whereNotNull('drug_id')
        ->where('quantity_available', '>', 0);  // ← ALWAYS enforced, no toggle

        // Search by drug name
        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('drug_name_raw', 'LIKE', "%{$term}%")
                  ->orWhereHas('drug', function ($q2) use ($term) {
                      $q2->where('trade_name',        'LIKE', "%{$term}%")
                         ->orWhere('name',            'LIKE', "%{$term}%")
                         ->orWhere('scientific_name', 'LIKE', "%{$term}%");
                  });
            });
        }

        // Fetch all rows for PHP-level grouping
        $allRows = $query->get();

        if ($allRows->isEmpty()) {
            return response()->json([
                'message' => 'No drugs available in your zone.',
                'data'    => [
                    'data'          => [],
                    'total'         => 0,
                    'per_page'      => (int)$request->get('per_page', 30),
                    'current_page'  => 1,
                    'last_page'     => 1,
                    'has_next_page' => false,
                ],
            ], 200);
        }

        // Map to response format
        $mapped = $allRows->map(fn($item) => [
            'inventory_id'       => $item->id,
            'drug_id'            => $item->drug_id,
            'drug_name'          => $item->drug?->trade_name ?? $item->drug_name_raw,
            'drug_name_raw'      => $item->drug_name_raw,
            'scientific_name'    => $item->drug?->scientific_name,
            'dosage_form'        => $item->drug?->dosage_form,
            'strength'           => $item->drug?->strength,
            'unit_price'         => (float)$item->unit_price,
            'public_price'       => (float)$item->public_price,
            'pharmacist_price'   => (float)$item->pharmacist_price,
            'discount_pct'       => (float)$item->discount_pct,
            'effective_price'    => round(
                (float)$item->pharmacist_price * (1 - (float)$item->discount_pct / 100), 2
            ),
            'savings_per_unit'   => round(
                (float)$item->public_price - ((float)$item->pharmacist_price * (1 - (float)$item->discount_pct / 100)), 2
            ),
            'quantity_available' => $item->quantity_available,
            'order_limit'        => $item->order_limit,
            'last_updated'       => $item->last_updated,
            'supplier'           => [
                'id'              => $item->supplier->id,
                'name'            => $item->supplier->name,
                'min_order_value' => $item->supplier->min_order_value,
                'min_order_qty'   => $item->supplier->min_order_qty,
            ],
        ]);

        // Group by drug_name → within each group sort by discount DESC
        // → flatten → alphabetical by drug name
        $sorted = $mapped
            ->groupBy('drug_name')
            ->map(fn($group) => $group->sortByDesc('discount_pct')->values())
            ->sortKeys()
            ->flatten(1)
            ->values();

        // Paginate the final sorted flat list
        $perPage     = max(1, (int)$request->get('per_page', 30));
        $currentPage = max(1, (int)$request->get('page', 1));
        $total       = $sorted->count();
        $lastPage    = max(1, (int)ceil($total / $perPage));
        $offset      = ($currentPage - 1) * $perPage;
        $items       = $sorted->slice($offset, $perPage)->values();

        return response()->json([
            'message' => 'Available drugs retrieved successfully.',
            'data'    => [
                'data'          => $items,
                'total'         => $total,
                'per_page'      => $perPage,
                'current_page'  => $currentPage,
                'last_page'     => $lastPage,
                'has_next_page' => $currentPage < $lastPage,
            ],
        ], 200);
    }

    // =========================================================
    // POST /api/v1/pharmacy/orders/{id}/confirm-delivery
    // NEW ENDPOINT
    //
    // Pharmacy confirms they received the order physically.
    // Status moves from delivered → delivery_confirmed.
    //
    // This is the final step in the order lifecycle.
    // Notifications sent to:
    //   - Pharmacy (confirmation receipt)
    //   - All involved suppliers (their order is fully closed)
    //
    // Note: actual FCM push sending is Sprint 4.
    //       This method creates the notification DB rows now.
    // =========================================================
    public function confirmDelivery(Request $request, int $id): JsonResponse
    {
        $user   = $request->user();
        $branch = $user->pharmacyBranch;

        $order = MasterOrder::with([
            'supplierOrders.supplier.user',
        ])
        ->where('id', $id)
        ->where('pharmacy_branch_id', $branch->id)
        ->where('status', 'delivered')
        ->first();

        if (! $order) {
            return response()->json([
                'message' => 'Order not found or not in delivered status. Only delivered orders can be confirmed.',
            ], 404);
        }

        DB::transaction(function () use ($order, $user) {

            // Update master order
            $order->update([
                'status'                  => 'delivery_confirmed',
                'pharmacy_confirmed_at'   => now(),
            ]);

            // Update all supplier orders under this master order
            SupplierOrder::where('master_order_id', $order->id)
                ->where('status', 'delivered')
                ->update([
                    'status'                => 'delivery_confirmed',
                    'pharmacy_confirmed_at' => now(),
                ]);

            // Notify pharmacy (the confirming user)
            // Sprint 4 will send actual FCM push using user.device_token
            /*Notification::create([
                'user_id'         => $user->id,
                'title'           => 'Delivery confirmed',
                'body'            => "You confirmed receipt of order {$order->order_number}. The order is now closed.",
                'type'            => 'order_delivered',
                'channel'         => 'push',
                'notifiable_type' => 'MasterOrder',
                'notifiable_id'   => $order->id,
                'is_read'         => 0,
            ]);*/
            $this->notifier->deliveryConfirmedForAll($order, $user);

            // Notify each supplier — their order is fully closed
            /*foreach ($order->supplierOrders as $supplierOrder) {
                if (! $supplierOrder->supplier?->user) continue;

                Notification::create([
                    'user_id'         => $supplierOrder->supplier->user->id,
                    'title'           => 'Order delivery confirmed by pharmacy',
                    'body'            => "Pharmacy confirmed receipt of order {$supplierOrder->order_number}. Order is now closed.",
                    'type'            => 'order_delivered',
                    'channel'         => 'push',
                    'notifiable_type' => 'SupplierOrder',
                    'notifiable_id'   => $supplierOrder->id,
                    'is_read'         => 0,
                ]);
            }*/
        });

        return response()->json([
            'message' => 'Delivery confirmed successfully. Order is now closed.',
            'data'    => [
                'id'                    => $order->id,
                'order_number'          => $order->order_number,
                'status'                => 'delivery_confirmed',
                'pharmacy_confirmed_at' => now(),
            ],
        ], 200);
    }
}
