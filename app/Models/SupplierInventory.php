<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierInventory extends Model
{
    protected $table = 'supplier_inventory';

    protected $fillable = [
        'supplier_id',
        'drug_id',           // always set — NOT NULL
        'drug_name_raw',     // audit only — what supplier originally typed
        'quantity_available',
        'order_limit',
        'unit_price',
        'public_price',        // ← new
        'pharmacist_price',    // ← new
        'discount_pct',
        'last_updated',
    ];

    protected $casts = [
        'quantity_available' => 'integer',
        'order_limit'        => 'integer',
        'unit_price'         => 'decimal:2',
        'discount_pct'       => 'decimal:2',
        'last_updated'       => 'datetime',
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
        return round($this->unit_price * (1 - $this->discount_pct / 100), 2);
    }

     // Savings per unit vs public price
    public function savingsVsPublic(): float
    {
        return round($this->public_price - $this->effectivePrice(), 2);
    }


    public function displayName(): string
    {
        return $this->drug?->trade_name ?? $this->drug_name_raw ?? '';
    }
}
