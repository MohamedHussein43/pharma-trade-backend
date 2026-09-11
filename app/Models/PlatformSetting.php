<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $fillable = [
        'enable_banned_drug_check',
        'fcm_enabled',
        'whatsapp_enabled',
        'updated_by',
    ];

    protected $casts = [
        'enable_banned_drug_check' => 'boolean',
        'fcm_enabled'              => 'boolean',
        'whatsapp_enabled'         => 'boolean',
    ];

    // =========================================================
    // Get the single settings row (always id=1)
    // =========================================================
    public static function current(): self
    {
        return static::firstOrCreate(
            ['id' => 1],
            [
                'enable_banned_drug_check' => 0,
                'fcm_enabled'              => 1,
                'whatsapp_enabled'         => 1,
            ]
        );
    }

    public static function isFcmEnabled(): bool
    {
        return (bool) static::current()->fcm_enabled;
    }

    public static function isWhatsAppEnabled(): bool
    {
        return (bool) static::current()->whatsapp_enabled;
    }

    public static function isBannedDrugCheckEnabled(): bool
    {
        return (bool) static::current()->enable_banned_drug_check;
    }
}
