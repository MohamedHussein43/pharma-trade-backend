<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name',
    'governorate',
    'is_active',
])]

class Zone extends Model
{
     protected function casts(): array
    {
        return [
          //  'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function pharmacyBranches()
    {
        return $this->hasMany(PharmacyBranch::class, 'zone_id');
    }
 
    public function supplierZones()
    {
        return $this->hasMany(SupplierZone::class, 'zone_id');
    }
 
    public function registrationRequests()
    {
        return $this->hasMany(RegistrationRequest::class, 'zone_id');
    }
    
}
