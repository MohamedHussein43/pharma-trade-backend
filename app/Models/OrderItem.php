<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = [
        'master_order_id',
        'supplier_order_id',
        'drug_id',
        'quantity_requested',
        'quantity_confirmed',
        'unit_price',
        'discount_pct',
        'line_total',
        'status',
    ];

    protected $casts = [
        'quantity_requested' => 'integer',
        'quantity_confirmed' => 'integer',
        'unit_price'         => 'decimal:2',
        'discount_pct'       => 'decimal:2',
        'line_total'         => 'decimal:2',
    ];

    // ── Relationships ────────────────────────────────────────

    public function supplierOrder()
    {
        return $this->belongsTo(SupplierOrder::class, 'supplier_order_id');
    }

    public function drug()
    {
        return $this->belongsTo(Drug::class, 'drug_id');
    }
}
