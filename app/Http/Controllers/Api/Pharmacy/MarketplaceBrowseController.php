<?php

namespace App\Http\Controllers\Api\Pharmacy;

use App\Models\PharmacyCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MarketplaceBrowseController extends BaseMarketplaceController
{
    /**
     * GET /pharmacy/marketplace/browse
     * Active collections visible in any of the buyer's zones.
     */
    public function index(Request $request): JsonResponse
    {
        $branch = $this->buyerBranch();

        // Get all zone IDs this buyer branch belongs to
        $zoneIds = DB::table('pharmacy_branch_zones')
            ->where('branch_id', $branch->id)
            ->pluck('zone_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (empty($zoneIds)) {
            return response()->json(['data' => [], 'message' => 'الفرع غير مرتبط بأي منطقة.']);
        }

        // A collection is visible if its zone_ids overlaps with any of the buyer's zones.
        // We build: WHERE JSON_CONTAINS(zone_ids, z1) OR JSON_CONTAINS(zone_ids, z2) ...
        $collections = PharmacyCollection::with(['sellerBranch', 'featuredItems.drug'])//PharmacyCollection::with('items.drug', 'sellerBranch')
            ->active()
            ->where(function ($query) use ($zoneIds) {
                foreach ($zoneIds as $zoneId) {
                    $query->orWhereRaw('JSON_CONTAINS(zone_ids, ?)', [json_encode($zoneId)]);
                }
            })
            ->where('seller_branch_id', '!=', $branch->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($collections);
    }

    /**
     * GET /pharmacy/marketplace/browse/{collection}
     * Single collection detail — only if active and shares a zone with the buyer.
     */
    public function show(PharmacyCollection $collection): JsonResponse
    {
        //return response()->json('test');
        $branch = $this->buyerBranch();

        // Buyer's zones
        $buyerZoneIds = DB::table('pharmacy_branch_zones')
            ->where('branch_id', $branch->id)
            ->pluck('zone_id')
            ->map(fn ($id) => (int) $id)
            ->all();
            $sharesZone = ! empty(array_intersect($buyerZoneIds, $collection->zone_ids ?? []));
            //return response()->json($branch->id);

        if (
            $collection->status !== PharmacyCollection::STATUS_ACTIVE ||
            ! $sharesZone ||
            $collection->sellerBranch->id === $branch->id
        ) {
            return response()->json($collection->load('items.drug', 'sellerBranch'));
            //abort(404);
        }

        return response()->json($collection->load('items.drug', 'sellerBranch', 'featuredItems.drug'));
    }
}
