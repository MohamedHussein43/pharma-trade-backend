<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name',
    'email',
    'phone',
    'password',
    'role',
    'status',
    'is_active',
    'device_token',
    'last_login_at'
])]
#[Hidden([
    'password',
    'remember_token'
])]


class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens,   HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
          //  'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function pharmacy()
{
    return $this->hasOne(Pharmacy::class);
}

public function pharmacyBranch()
{
    return $this->hasOne(PharmacyBranch::class, 'user_id');
    }

public function supplier()
{
    return $this->hasOne(Supplier::class);
}

public function notifications()
    {
        return $this->hasMany(Notification::class, 'user_id');
    }
 
    // ── Helpers ──────────────────────────────────────────────
 
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
 
    public function isPharmacy(): bool
    {
        return $this->role === 'pharmacy';
    }
 
    public function isSupplier(): bool
    {
        return $this->role === 'supplier';
    }
 
    public function isActive(): bool
    {
        return $this->is_active && $this->status === 'active';
    }


    
public function reviewedRequests()
        {
            return $this->hasMany(RegistrationRequest::class, 'reviewed_by');
        }
public function registrationRequests()
{
    return $this->hasMany(RegistrationRequest::class);
}
}
