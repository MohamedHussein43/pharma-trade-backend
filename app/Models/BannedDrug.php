<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BannedDrug extends Model
{
    protected $fillable = [
        'drug_name',
        'reason',
        'added_by',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function addedBy()
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
