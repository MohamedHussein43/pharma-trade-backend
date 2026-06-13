<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LicenceImage extends Model
{
    protected $fillable = [
        'registration_request_id',
        'file_path',
        'file_name',
        'mime_type',
        'file_size_kb',
        'is_primary',
        'uploaded_at',
    ];
 
    protected $casts = [
        'is_primary'  => 'boolean',
        'uploaded_at' => 'datetime',
        'file_size_kb'=> 'integer',
    ];
 
    // ── Relationships ────────────────────────────────────────
 
    public function registrationRequest()
    {
        return $this->belongsTo(RegistrationRequest::class, 'registration_request_id');
    }
 
    // ── Helpers ──────────────────────────────────────────────
 
    /**
     * Generate a temporary signed URL for admin review.
     * Valid for 30 minutes — never expose the raw file_path.
     */
    public function temporaryUrl(int $minutes = 30): string
    {
        return Storage::disk('private')->temporaryUrl(
            $this->file_path,
            now()->addMinutes($minutes)
        );
    }
}
