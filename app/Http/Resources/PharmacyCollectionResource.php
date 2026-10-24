<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PharmacyCollectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'description'      => $this->description,
            'total_price'      => $this->total_price,
            'notes'            => $this->notes,
            'status'           => $this->status,
            'zone_id'          => $this->zone_id,
            'featured_items' => $this->featuredItems->map(fn ($item) => [
                    'id'               => $item->id,
                    'drug_name'        => $item->drug->trade_name ?? null,
                    'pharmacist_price' => $item->pharmacist_price,
                    'discount_pct'     => $item->discount_pct,
                ]),
            'seller_branch_id' => $this->seller_branch_id,
            'seller_branch'    => $this->whenLoaded('sellerBranch', fn () => [
                'id'   => $this->sellerBranch->id,
                'name' => $this->sellerBranch->name,
            ]),
            'items'            => $this->whenLoaded('items', fn () =>
                $this->items->map(fn ($item) => [
                    'id'                  => $item->id,
                    'drug_id'             => $item->drug_id,
                    'drug_name'           => $item->drug?->trade_name,
                    'quantity'            => $item->quantity,
                    'unit_price_snapshot' => $item->unit_price_snapshot,
                    'line_total'          => $item->line_total,
                ])
            ),
            'items_count'      => $this->whenCounted('items'),
            'created_at'       => $this->created_at?->toDateTimeString(),
            'updated_at'       => $this->updated_at?->toDateTimeString(),
        ];
    }
}
