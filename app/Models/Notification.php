<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'body',
        'type',
        'channel',
        'notifiable_type',
        'notifiable_id',
        'is_read',
        'read_at',
    ];
 
    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];
 
    // ── Relationships ────────────────────────────────────────
 
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
 
    /**
     * Polymorphic link — points to the related model
     * (RegistrationRequest, MasterOrder, SupplierOrder, etc.)
     * Used by the Flutter app for deep-linking on tap.
     */
    public function notifiable()
    {
        return $this->morphTo();
    }
 
    // ── Helpers ──────────────────────────────────────────────
 
    public function markAsRead(): void
    {
        $this->update([
            'is_read' => true,
            'read_at' => now(),
        ]);
    }
}
