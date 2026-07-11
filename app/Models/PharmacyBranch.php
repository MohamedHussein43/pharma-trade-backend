<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;




class PharmacyBranch extends Model
{
     protected $fillable = [
        'pharmacy_id',
        'user_id',
        'name',
        'licence_number',
        'address',
        'phone',
        'approval_status',
        'reviewed_by',
        'reviewed_at',
        'is_active',
        // zone_id removed — now managed via pharmacy_branch_zones pivot
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'reviewed_at' => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────

    public function pharmacy()
    {
        return $this->belongsTo(Pharmacy::class, 'pharmacy_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function masterOrders()
    {
        return $this->hasMany(MasterOrder::class, 'pharmacy_branch_id');
    }

    // Many-to-many relationship with zones
    public function zones()
    {
        return $this->belongsToMany(
            Zone::class,
            'pharmacy_branch_zones',
            'branch_id',
            'zone_id'
        );
    }

    // Convenience: get array of zone IDs
    public function zoneIds(): array
    {
        return $this->zones()->pluck('zones.id')->toArray();
    }
}
