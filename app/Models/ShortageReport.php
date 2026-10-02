<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShortageReport extends Model
{
    protected $fillable = [
        'supplier_order_id',
        'drug_id',
        'quantity_short',
        'notes',
        'resolved',
        'resolved_at',
    ];

    protected $casts = [
        'resolved'     => 'boolean',
        'resolved_at'  => 'datetime',
        'quantity_short' => 'integer',
    ];

    // ── Relationships ────────────────────────────────────────

    public function orderItem()
    {
        return $this->belongsTo(\App\Models\OrderItem::class, 'order_item_id');
    }

    public function drug()
    {
        return $this->belongsTo(\App\Models\Drug::class, 'drug_id');
    }

    public function supplierOrder()
    {
        return $this->belongsTo(\App\Models\SupplierOrder::class, 'supplier_order_id');
    }
}
