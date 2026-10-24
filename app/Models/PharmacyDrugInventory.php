<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PharmacyDrugInventory extends Model
{
    protected $table = 'pharmacy_drug_inventory';

    protected $fillable = [
        'seller_branch_id',
        'drug_id',
        'zone_ids',          // JSON array: [1, 3, 7]
        'quantity_available',
        'unit_price',
        'expiry_date',
        'batch_number',
        'is_active',
    ];

    protected $casts = [
        'zone_ids'           => 'array',   // automatically encode/decode JSON
        'quantity_available' => 'integer',
        'unit_price'         => 'decimal:2',
        'expiry_date'        => 'date',
        'is_active'          => 'boolean',
    ];

    // ─── Relations ────────────────────────────────────────────────────────────

    public function sellerBranch(): BelongsTo
    {
        return $this->belongsTo(PharmacyBranch::class, 'seller_branch_id');
    }

    public function drug(): BelongsTo
    {
        return $this->belongsTo(Drug::class);
    }

    public function collectionItems(): HasMany
    {
        return $this->hasMany(PharmacyCollectionItem::class, 'inventory_id');
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForBranch($query, int $branchId)
    {
        return $query->where('seller_branch_id', $branchId);
    }

    /**
     * Filter inventory rows that include a specific zone.
     * Uses MySQL JSON_CONTAINS for efficient lookup.
     */
    public function scopeForZone($query, int $zoneId)
    {
        return $query->whereRaw('JSON_CONTAINS(zone_ids, ?)', [json_encode($zoneId)]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Deduct stock. Auto-deactivates when quantity reaches 0.
     */
    public function deductStock(int $qty): void
    {
        $this->decrement('quantity_available', $qty);
        $this->refresh();
        if ($this->quantity_available <= 0) {
            $this->update(['quantity_available' => 0, 'is_active' => false]);
        }
    }

    /**
     * Restore stock. Re-activates if it was deactivated due to zero stock.
     */
    public function restoreStock(int $qty): void
    {
        $this->increment('quantity_available', $qty);
        if (! $this->is_active) {
            $this->update(['is_active' => true]);
        }
    }

    /**
     * Check whether this inventory item covers a given zone.
     */
    public function coversZone(int $zoneId): bool
    {
        return in_array($zoneId, $this->zone_ids ?? [], true);
    }
}
