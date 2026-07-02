<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Drug extends Model
{
     protected $fillable = [
        'name',
        'trade_name',
        'scientific_name',
        'manufacturer',
        'dosage_form',
        'strength',
        'barcode',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ── Relationships ────────────────────────────────────────

    public function supplierInventory()
    {
        return $this->hasMany(SupplierInventory::class, 'drug_id');
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class, 'drug_id');
    }

    public function shortageReports()
    {
        return $this->hasMany(ShortageReport::class, 'drug_id');
    }
}
