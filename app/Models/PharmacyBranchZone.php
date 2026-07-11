<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PharmacyBranchZone extends Model
{
    protected $table = 'pharmacy_branch_zones';

    protected $fillable = [
        'branch_id',
        'zone_id',
    ];

    public function branch()
    {
        return $this->belongsTo(PharmacyBranch::class, 'branch_id');
    }

    public function zone()
    {
        return $this->belongsTo(Zone::class, 'zone_id');
    }
}
