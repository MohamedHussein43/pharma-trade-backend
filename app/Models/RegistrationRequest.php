<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistrationRequest extends Model
{
    protected $fillable = [
        'user_id',
        'entity_type',
        'entity_id',
        'pharmacy_id',
        'applicant_name',
        'business_name',
        'licence_number',
        'phone',
        'address',
        'zone_id',
        'meta',
        'status',
        'reviewed_by',
        'reviewed_at',
        'decline_reason',
    ];
 
    protected $casts = [
        'reviewed_at' => 'datetime',
        'entity_id'   => 'integer',
        'pharmacy_id' => 'integer',
        'zone_id'     => 'integer',
        'meta'        => 'array',
    ];
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
 
    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
 
    public function zone()
    {
        return $this->belongsTo(Zone::class, 'zone_id');
    }
 
    public function licenceImages()
    {
        return $this->hasMany(LicenceImage::class, 'registration_request_id');
    }
 
    public function primaryImage()
    {
        return $this->hasOne(LicenceImage::class, 'registration_request_id')
                    ->where('is_primary', 1);
    }
 
    // ── Helpers ──────────────────────────────────────────────
 
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
 
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
 
    public function isDeclined(): bool
    {
        return $this->status === 'declined';
    }
}
