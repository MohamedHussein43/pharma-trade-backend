<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LicenceImage extends Model
{
    protected $fillable = [
        'registration_request_id',
        'file_path',
        'file_name',
        'file_content', 
        'mime_type',
        'file_size_kb',
        'is_primary',
        'uploaded_at',
    ];

        protected $hidden = [
        'file_content',    // ← hide from JSON by default (too large)
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
 
        // Helper to get image as base64 data URL
    public function toDataUrl(): ?string
    {
        if (! empty($this->file_content)) {
            return "data:{$this->mime_type};base64,{$this->file_content}";
        }
        return null;
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
