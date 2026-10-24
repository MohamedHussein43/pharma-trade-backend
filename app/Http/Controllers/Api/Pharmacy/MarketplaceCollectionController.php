<?php

namespace App\Http\Controllers\Api\Pharmacy;

use App\Http\Requests\Pharmacy\StoreCollectionRequest;
use App\Http\Requests\Pharmacy\AddCollectionItemsRequest;
use App\Http\Requests\Pharmacy\SetFeaturedItemsRequest;
use App\Models\PharmacyCollection;
use App\Models\PharmacyCollectionItem;
use App\Models\PharmacyDrugInventory;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class MarketplaceCollectionController extends BaseMarketplaceController
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}
    /**
     * GET /pharmacy/marketplace/my-collections
     * Seller's own collections list.
     */
    public function index(Request $request): JsonResponse
    {
        $branch = $this->sellerBranch();

        $collections = PharmacyCollection::with('items.drug')
            ->forSeller($branch->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($collections);
    }

    /**
     * POST /pharmacy/marketplace/my-collections
     * Create a new collection (starts as draft).
     */
    public function store(StoreCollectionRequest $request): JsonResponse
    {
        $branch = $this->sellerBranch();
        $data   = $request->validated();

        // Fetch zone IDs from pharmacy_branch_zones — never from the request
        $zoneIds = DB::table('pharmacy_branch_zones')
            ->where('branch_id', $branch->id)
            ->pluck('zone_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if (empty($zoneIds)) {
            return response()->json([
                'message' => 'الفرع غير مرتبط بأي منطقة. يرجى التواصل مع الإدارة.',
            ], 422);
        }

        $collection = PharmacyCollection::create([
            'seller_branch_id' => $branch->id,
            'zone_ids'         => $zoneIds,
            'name'             => $data['name'],
            'description'      => $data['description'] ?? null,
            'total_price'      => 0, // recalculated after items are added
            'notes'            => $data['notes'] ?? null,
            'status'           => PharmacyCollection::STATUS_DRAFT,
        ]);

        return response()->json($collection, 201);
    }

    /**
     * GET /pharmacy/marketplace/my-collections/{collection}
     */
    public function show(PharmacyCollection $collection): JsonResponse
    {
        $this->authorizeOwnership($collection);

        return response()->json($collection->load('items.drug', 'items.inventory'));
    }

    /**
     * POST /pharmacy/marketplace/my-collections/{collection}/items
     * Add (or replace) items in a draft collection.
     */
    public function addItems(AddCollectionItemsRequest $request, PharmacyCollection $collection): JsonResponse
    {
        $this->authorizeOwnership($collection);

        if (! $collection->isEditable()) {
            return response()->json([
                'message' => 'لا يمكن تعديل مجموعة بعد نشرها.',
            ], 422);
        }

        $branch = $this->sellerBranch();
        $items  = $request->validated()['items'];
        $collection->update(['featured_item_ids' => null]);

        DB::transaction(function () use ($collection, $items, $branch) {
            // Remove existing items and rebuild — simplest idempotent approach
            PharmacyCollectionItem::where('collection_id', $collection->id)->delete();

            foreach ($items as $item) {
                $inventory = PharmacyDrugInventory::where('id', $item['inventory_id'])
                    ->where('seller_branch_id', $branch->id)
                    ->firstOrFail();

                if ($inventory->quantity_available < $item['quantity']) {
                    throw new \RuntimeException(
                        "المخزون غير كافٍ للصنف: {$inventory->drug->trade_name}"
                    );
                }

                $unitPrice       = (float) $inventory->unit_price;  // sourced from inventory, never from request
                $discountPct     = (float) ($item['discount_pct'] ?? 0);
                $pharmacistPrice = round($unitPrice * (1 - $discountPct / 100), 2);

                PharmacyCollectionItem::create([
                    'collection_id'          => $collection->id,
                    'inventory_id'           => $inventory->id,
                    'drug_id'                => $inventory->drug_id,
                    'quantity'               => $item['quantity'],
                    'unit_price_snapshot'    => $inventory->unit_price,   // original inventory price
                    'unit_price'             => $unitPrice,               // seller-set price
                    'discount_pct'           => $discountPct,
                    'pharmacist_price'       => $pharmacistPrice,         // after discount
                    'line_total'             => round($item['quantity'] * $unitPrice, 2),
                    'pharmacist_line_total'  => round($item['quantity'] * $pharmacistPrice, 2),
                ]);
            }

            // Recalculate collection total from pharmacist prices
            $collection->recalculateTotalPrice();
        });

        return response()->json($collection->fresh()->load('items.drug'), 200);
    }

    /**
     * POST /pharmacy/marketplace/my-collections/{collection}/publish
     * Move collection from draft → active (visible to buyers).
     */
    public function publish(PharmacyCollection $collection): JsonResponse
    {
        $this->authorizeOwnership($collection);

        if (! $collection->canBePublished()) {
            return response()->json([
                'message' => 'لا يمكن نشر المجموعة — تأكد من أنها في حالة مسودة وتحتوي على أصناف.',
            ], 422);
        }

        $collection->update(['status' => PharmacyCollection::STATUS_ACTIVE]);

        // Notify all pharmacies in the same zone that a new collection is available
        $this->notificationService->p2pCollectionAvailable($collection->fresh());

        return response()->json($collection->fresh(), 200);
    }

    /**
     * POST /pharmacy/marketplace/my-collections/{collection}/deactivate
     * Pull a collection back to draft (only if still active — not ordered).
     */
    public function deactivate(PharmacyCollection $collection): JsonResponse
    {
        $this->authorizeOwnership($collection);

        if ($collection->status !== PharmacyCollection::STATUS_ACTIVE) {
            return response()->json([
                'message' => 'يمكن سحب المجموعات النشطة فقط.',
            ], 422);
        }

        $collection->update(['status' => PharmacyCollection::STATUS_DRAFT]);

        return response()->json($collection->fresh(), 200);
    }

    /**
     * DELETE /pharmacy/marketplace/my-collections/{collection}
     * Hard-delete a draft collection.
     */
    public function destroy(PharmacyCollection $collection): JsonResponse
    {
        $this->authorizeOwnership($collection);

        if (! $collection->isEditable()) {
            return response()->json([
                'message' => 'لا يمكن حذف مجموعة منشورة أو مكتملة.',
            ], 422);
        }

        $collection->delete();

        return response()->json(['message' => 'تم حذف المجموعة.']);
    }

    private function authorizeOwnership(PharmacyCollection $collection): void
    {
        $branch = $this->sellerBranch();

        if ($collection->seller_branch_id !== $branch->id) {
            abort(403, 'غير مصرح.');
        }
    }

    public function setFeaturedItems(SetFeaturedItemsRequest $request, PharmacyCollection $collection): JsonResponse
    {
        $this->authorizeOwnership($collection);

        if ($collection->isTerminal()) {
            return response()->json([
                'message' => 'لا يمكن تعديل مجموعة مكتملة أو ملغاة.',
            ], 422);
        }

        // Ensure all submitted IDs belong to this collection
        $validIds = $collection->items()->whereIn('id', $request->item_ids)->pluck('id')->all();

        if (count($validIds) !== count($request->item_ids)) {
            return response()->json([
                'message' => 'بعض العناصر المحددة لا تنتمي إلى هذه المجموعة.',
            ], 422);
        }

        $collection->update(['featured_item_ids' => $validIds]);

        return response()->json([
            'message'       => 'تم تحديد العناصر المميزة.',
            'featured_items' => $collection->featuredItems()->with('drug')->get(),
        ]);
    }
}
