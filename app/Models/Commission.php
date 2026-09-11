<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Commission extends Model
{
    protected $fillable = [
        'supplier_order_id',
        'master_order_id',
        'supplier_id',
        'pharmacy_branch_id',
        'order_number',
        'subtotal',
        'commission_pct',
        'commission_value',
        'status',
        'period_month',
        'period_year',
        'paid_at',
        'notes',
    ];

    protected $casts = [
        'subtotal'         => 'decimal:2',
        'commission_pct'   => 'decimal:2',
        'commission_value' => 'decimal:2',
        'paid_at'          => 'datetime',
    ];

    public function supplierOrder()  { return $this->belongsTo(SupplierOrder::class); }
    public function masterOrder()    { return $this->belongsTo(MasterOrder::class); }
    public function supplier()       { return $this->belongsTo(Supplier::class); }
    public function pharmacyBranch() { return $this->belongsTo(PharmacyBranch::class); }
}

