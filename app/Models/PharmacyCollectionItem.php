<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PharmacyCollectionItem extends Model
{
    protected $fillable = [
        'collection_id',
        'inventory_id',
        'drug_id',
        'quantity',
        'unit_price_snapshot',   // original price from inventory (informational)
        'unit_price',            // seller-set price for this collection
        'discount_pct',          // discount percentage (0–100)
        'pharmacist_price',      // unit_price after discount — computed on backend
        'line_total',            // quantity × unit_price          (before discount)
        'pharmacist_line_total', // quantity × pharmacist_price    (after discount)
    ];

    protected $casts = [
        'quantity'               => 'integer',
        'unit_price_snapshot'    => 'decimal:2',
        'unit_price'             => 'decimal:2',
        'discount_pct'           => 'decimal:2',
        'pharmacist_price'       => 'decimal:2',
        'line_total'             => 'decimal:2',
        'pharmacist_line_total'  => 'decimal:2',
    ];

    // ─── Relations ────────────────────────────────────────────────────────────

    public function collection(): BelongsTo
    {
        return $this->belongsTo(PharmacyCollection::class);
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(PharmacyDrugInventory::class, 'inventory_id');
    }

    public function drug(): BelongsTo
    {
        return $this->belongsTo(Drug::class);
    }
}
