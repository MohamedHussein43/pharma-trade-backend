<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterOrder extends Model
{
    protected $fillable = [
        'pharmacy_branch_id',
        'created_by',
        'order_number',
        'order_mode',
        'status',
        'total_value',
        'notes',
    ];

    protected $casts = [
        'total_value' => 'decimal:2',
    ];

    // ── Relationships ────────────────────────────────────────

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class, 'master_order_id');
    }

    public function pharmacyBranch()
    {
        return $this->belongsTo(PharmacyBranch::class, 'pharmacy_branch_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function supplierOrders()
    {
        return $this->hasMany(SupplierOrder::class, 'master_order_id');
    }

    // ── Helpers ──────────────────────────────────────────────

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'pending_supplier_confirmation']);
    }

    public function isCancellable(): bool
    {
        return ! in_array($this->status, ['delivered', 'cancelled']);
    }
}
