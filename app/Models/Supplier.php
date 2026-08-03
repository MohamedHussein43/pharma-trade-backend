<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class Supplier extends Model
{
    protected $fillable = [
        'user_id',
    'name',
    'licence_number',
    'min_order_value',
    'min_order_qty',
    'approval_status',
    'reviewed_by',
    'reviewed_at',
    'is_active',
    ];
    protected function casts(): array
    {
        return [
        'is_active'       => 'boolean',
        'reviewed_at'     => 'datetime',
        'min_order_value' => 'decimal:2',
        'min_order_qty'   => 'integer',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
 
    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
 
    public function zones()
    {
        return $this->belongsToMany(
            Zone::class,
            'supplier_zones',
            'supplier_id',
            'zone_id'
        );
    }
 
    public function inventory()
    {
        return $this->hasMany(SupplierInventory::class, 'supplier_id');
    }
 
    public function supplierOrders()
    {
        return $this->hasMany(SupplierOrder::class, 'supplier_id');
    }
}
