<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;




class PharmacyBranch extends Model
{
    protected $fillable = [
    'pharmacy_id',
    'zone_id',
    'user_id',
    'name',
    'licence_number',
    'address',
    'phone',
    'approval_status',
    'reviewed_by',
    'reviewed_at',
    'is_active',
];
     protected function casts(): array
    {
        return [
        'is_active'   => 'boolean',
        'reviewed_at' => 'datetime',
        ];
    }

    public function pharmacy()
    {
        return $this->belongsTo(Pharmacy::class, 'pharmacy_id');
    }
 
    public function zone()
    {
        return $this->belongsTo(Zone::class, 'zone_id');
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
}
