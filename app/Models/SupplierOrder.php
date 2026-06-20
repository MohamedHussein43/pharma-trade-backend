<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierOrder extends Model
{
    protected $fillable = [
        'master_order_id',
        'supplier_id',
        'order_number',
        'status',
        'subtotal',
        'commission_pct',
        'commission_value',
        'notes',
        'confirmed_at',
        'shipped_at',
        'delivered_at',
    ];

    protected $casts = [
        'subtotal'         => 'decimal:2',
        'commission_pct'   => 'decimal:2',
        'commission_value' => 'decimal:2',
        'confirmed_at'     => 'datetime',
        'shipped_at'       => 'datetime',
        'delivered_at'     => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────

    public function masterOrder()
    {
        return $this->belongsTo(MasterOrder::class, 'master_order_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class, 'supplier_order_id');
    }

    public function shortageReports()
    {
        return $this->hasMany(ShortageReport::class, 'supplier_order_id');
    }
}
