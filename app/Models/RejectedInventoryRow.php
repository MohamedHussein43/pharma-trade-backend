<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RejectedInventoryRow extends Model
{
    protected $fillable = [
        'supplier_id',
        'upload_log_id',
        'drug_name_raw',
        'banned_drug_id',
        'quantity',
        'price',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'price'    => 'decimal:2',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function uploadLog()
    {
        return $this->belongsTo(InventoryUploadLog::class, 'upload_log_id');
    }

    public function bannedDrug()
    {
        return $this->belongsTo(BannedDrug::class, 'banned_drug_id');
    }
}
