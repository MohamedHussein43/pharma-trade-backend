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

    public function supplierOrder()
    {
        return $this->belongsTo(SupplierOrder::class, 'supplier_order_id');
    }

    public function drug()
    {
        return $this->belongsTo(Drug::class, 'drug_id');
    }
}
