<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryUploadLog extends Model
{
       protected $fillable = [
        'supplier_id',
        'uploaded_by',
        'file_name',
        'total_rows',
        'success_rows',
        'failed_rows',
        'status',
        'error_log',
    ];

    protected $casts = [
        'total_rows'   => 'integer',
        'success_rows' => 'integer',
        'failed_rows'  => 'integer',
    ];

    // ── Relationships ────────────────────────────────────────

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    // ── Helpers ──────────────────────────────────────────────

    public function errors(): array
    {
        return $this->error_log ? json_decode($this->error_log, true) : [];
    }

    public function successRate(): float
    {
        if ($this->total_rows === 0) return 0;
        return round(($this->success_rows / $this->total_rows) * 100, 1);
    }
}
