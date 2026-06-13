<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name',
    'licence_number',
    'approval_status',
    'reviewed_by',
    'reviewed_at',
    'is_active',
])]

class Pharmacy extends Model
{
     protected function casts(): array
    {
        return [
        'is_active'   => 'boolean',
        'reviewed_at' => 'datetime',
        ];
    }

    public function branches()
    {
        return $this->hasMany(PharmacyBranch::class, 'pharmacy_id');
    }
 
    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
 
    public function activeBranches()
    {
        return $this->hasMany(PharmacyBranch::class, 'pharmacy_id')
                    ->where('is_active', 1);
    }
}
