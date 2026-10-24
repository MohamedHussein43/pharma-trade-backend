<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PharmacyOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'order_number'        => $this->order_number,
            'status'              => $this->status,
            'collection_id'       => $this->collection_id,
            'buyer_branch_id'     => $this->buyer_branch_id,
            'seller_branch_id'    => $this->seller_branch_id,

            // Financials
            'total_amount'        => $this->total_amount,
            'commission_pct'      => $this->commission_pct,
            'commission_amount'   => $this->commission_amount,
            'seller_net_amount'   => $this->seller_net_amount,

            // Collector
            'collector_name'      => $this->collector_name,
            'collector_phone'     => $this->collector_phone,
            'collector_confirmed_at' => $this->collector_confirmed_at?->toDateTimeString(),

            // Timestamps
            'accepted_at'         => $this->accepted_at?->toDateTimeString(),
            'ready_at'            => $this->ready_at?->toDateTimeString(),
            'completed_at'        => $this->completed_at?->toDateTimeString(),
            'created_at'          => $this->created_at?->toDateTimeString(),

            // Relationships
            'collection'          => $this->whenLoaded('collection', fn () =>
                new PharmacyCollectionResource($this->collection)
            ),
            'buyer_branch'        => $this->whenLoaded('buyerBranch', fn () => [
                'id'   => $this->buyerBranch->id,
                'name' => $this->buyerBranch->name,
            ]),
            'seller_branch'       => $this->whenLoaded('sellerBranch', fn () => [
                'id'   => $this->sellerBranch->id,
                'name' => $this->sellerBranch->name,
            ]),
        ];
    }
}
