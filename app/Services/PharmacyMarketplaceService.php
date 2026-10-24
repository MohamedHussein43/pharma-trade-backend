<?php

namespace App\Services;

use App\Models\PharmacyBranch;
use App\Models\PharmacyCollection;
use App\Models\PharmacyCollectionItem;
use App\Models\PharmacyDrugInventory;
use App\Models\PharmacyOrder;
use Illuminate\Support\Facades\DB;

class PharmacyMarketplaceService
{
    /**
     * Place an order for a collection.
     * Atomically: lock collection, deduct stock from each inventory row, create order.
     */
    public function placeOrder(
        PharmacyCollection $collection,
        PharmacyBranch $buyerBranch,
        int $createdByUserId
    ): PharmacyOrder {
        return DB::transaction(function () use ($collection, $buyerBranch, $createdByUserId) {

            // Re-fetch with a row-level lock — prevents double-ordering
            $collection = PharmacyCollection::lockForUpdate()
                ->where('id', $collection->id)
                ->where('status', PharmacyCollection::STATUS_ACTIVE)
                ->firstOrFail();

            // Buyer cannot order their own collection
            if ($collection->seller_branch_id === $buyerBranch->id) {
                throw new \InvalidArgumentException('لا يمكنك طلب مجموعتك الخاصة.');
            }

            // Zone check — buyer branch must share at least one zone with the collection
            $buyerZoneIds = \Illuminate\Support\Facades\DB::table('pharmacy_branch_zones')
                ->where('branch_id', $buyerBranch->id)
                ->pluck('zone_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $sharesZone = ! empty(array_intersect($buyerZoneIds, $collection->zone_ids ?? []));

            if (! $sharesZone) {
                throw new \InvalidArgumentException('هذه المجموعة غير متاحة في منطقتك.');
            }

            // Load collection items (with inventory locked)
            $items = PharmacyCollectionItem::lockForUpdate()
                ->where('collection_id', $collection->id)
                ->with('inventory')
                ->get();

            if ($items->isEmpty()) {
                throw new \InvalidArgumentException('المجموعة لا تحتوي على أصناف.');
            }

            // Deduct stock from each inventory row
            foreach ($items as $item) {
                $inventory = PharmacyDrugInventory::lockForUpdate()
                    ->find($item->inventory_id);

                if (! $inventory || $inventory->quantity_available < $item->quantity) {
                    $drugName = $inventory?->drug?->trade_name ?? "صنف #{$item->drug_id}";
                    throw new \RuntimeException("المخزون غير كافٍ للصنف: {$drugName}");
                }

                $inventory->deductStock($item->quantity);
            }

            // Lock collection
            $collection->update(['status' => PharmacyCollection::STATUS_ORDERED]);

            // Calculate commission
            $total          = $collection->total_price;
            $commissionPct  = 1.00;
            $commissionAmt  = round($total * $commissionPct / 100, 2);
            $sellerNet      = round($total - $commissionAmt, 2);

            // Create order
            return PharmacyOrder::create([
                'order_number'     => PharmacyOrder::generateOrderNumber(),
                'collection_id'    => $collection->id,
                'buyer_branch_id'  => $buyerBranch->id,
                'seller_branch_id' => $collection->seller_branch_id,
                'created_by'       => $createdByUserId,
                'status'           => PharmacyOrder::STATUS_PENDING_COLLECTOR,
                'total_amount'     => $total,
                'commission_pct'   => $commissionPct,
                'commission_amount'=> $commissionAmt,
                'seller_net_amount'=> $sellerNet,
            ]);
        });
    }

    /**
     * Restore collection to active and restore all stock.
     * Called on cancellation or rejection at any stage.
     */
    public function restoreCollection(PharmacyOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $collection = PharmacyCollection::lockForUpdate()->find($order->collection_id);

            if (! $collection) {
                return;
            }

            // Restore stock for each collection item
            $items = PharmacyCollectionItem::where('collection_id', $collection->id)
                ->with('inventory')
                ->get();

            foreach ($items as $item) {
                $inventory = PharmacyDrugInventory::lockForUpdate()->find($item->inventory_id);
                if ($inventory) {
                    $inventory->restoreStock($item->quantity);
                }
            }

            // Restore collection to active so buyers can order it again
            $collection->update(['status' => PharmacyCollection::STATUS_ACTIVE]);
        });
    }

    /**
     * Mark collection as completed (terminal — no restore).
     */
    public function completeCollection(PharmacyOrder $order): void
    {
        PharmacyCollection::where('id', $order->collection_id)
            ->update(['status' => PharmacyCollection::STATUS_COMPLETED]);
    }
}
