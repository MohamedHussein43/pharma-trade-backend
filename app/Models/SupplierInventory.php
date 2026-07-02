<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierInventory extends Model
{
    protected $table = 'supplier_inventory';
    protected $fillable = [
        'supplier_id',
        'drug_id',              // nullable now — catalog match optional
        'drug_name_raw',        // always set — exactly what supplier typed
        'is_catalog_matched',   // 1 if drug_id was successfully matched
        'quantity_available',
        'unit_price',
        'discount_pct',
        'last_updated',
    ];

    protected $casts = [
        'quantity_available' => 'integer',
        'unit_price'         => 'decimal:2',
        'discount_pct'       => 'decimal:2',
        'last_updated'       => 'datetime',
        'is_catalog_matched' => 'boolean',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function drug()
    {
        return $this->belongsTo(Drug::class, 'drug_id');
    }

    public function effectivePrice(): float
    {
        return $this->unit_price * (1 - $this->discount_pct / 100);
    }

    public function displayName(): string
    {
        // Prefer the catalog trade name if matched, fall back to raw
        return $this->drug?->trade_name ?? $this->drug_name_raw;
    }
}
