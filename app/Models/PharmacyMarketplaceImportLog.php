<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PharmacyMarketplaceImportLog extends Model
{
    protected $fillable = [
        'seller_branch_id',
        'zone_ids',
        'file_name',
        'file_content',
        'processed_rows',
        'status',
        'error_log',
    ];

    protected $casts = [
        'zone_ids'       => 'array',
        'processed_rows' => 'integer',
        'success_rows' => 'integer',
        'failed_rows'  => 'integer',
        'error_log'    => 'array',
    ];

    // Never return the base64 file content in API responses
    protected $hidden = ['file_content'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(PharmacyBranch::class, 'branch_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
     public function sellerBranch(): BelongsTo
    {
        return $this->belongsTo(PharmacyBranch::class, 'seller_branch_id');
    }
}

