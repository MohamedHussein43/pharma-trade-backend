<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PharmacyCollection extends Model
{
    protected $fillable = [
        'seller_branch_id',
        'zone_ids',
        'name',
        'description',
        'total_price',  // stored as a cached sum, recalculated whenever items change
        'status',
        'notes',
        'featured_item_ids',
    ];

    protected $casts = [
        'zone_ids'    => 'array',
        'total_price' => 'decimal:2',
        'featured_item_ids' => 'array',
    ];

    /**
     * Recalculate and persist total_price from current items.
     * Call this after any add/replace of collection items.
     */
    public function recalculateTotalPrice(): void
    {
        $total = $this->items()->sum('pharmacist_line_total');
        $this->update(['total_price' => $total]);
    }

    // ─── Status constants ─────────────────────────────────────────────────────

    const STATUS_DRAFT     = 'draft';
    const STATUS_ACTIVE    = 'active';
    const STATUS_ORDERED   = 'ordered';
    const STATUS_COMPLETED = 'completed';
    const STATUS_SUSPENDED = 'suspended';

    // ─── Relations ────────────────────────────────────────────────────────────

    public function sellerBranch(): BelongsTo
    {
        return $this->belongsTo(PharmacyBranch::class, 'seller_branch_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PharmacyCollectionItem::class, 'collection_id');
    }

    public function order(): HasOne
    {
        return $this->hasOne(PharmacyOrder::class, 'collection_id');
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Filter collections visible in a given zone.
     * Uses MySQL JSON_CONTAINS since zone_ids is a JSON array.
     */
    public function scopeForZone($query, int $zoneId)
    {
        return $query->whereRaw('JSON_CONTAINS(zone_ids, ?)', [json_encode($zoneId)]);
    }

    /**
     * Check whether this collection is visible in a given zone.
     */
    public function coversZone(int $zoneId): bool
    {
        return in_array($zoneId, $this->zone_ids ?? [], true);
    }

    public function scopeForSeller($query, int $branchId)
    {
        return $query->where('seller_branch_id', $branchId);
    }

    public function featuredItems(): HasMany
    {
        return $this->hasMany(PharmacyCollectionItem::class, 'collection_id')
            ->when(
                $this->featured_item_ids,
                fn ($q) => $q->whereIn('id', $this->featured_item_ids)
            );
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['completed', 'cancelled']);
    }

    // ─── State helpers ────────────────────────────────────────────────────────

    public function isDraft(): bool     { return $this->status === self::STATUS_DRAFT; }
    public function isActive(): bool    { return $this->status === self::STATUS_ACTIVE; }
    public function isOrdered(): bool   { return $this->status === self::STATUS_ORDERED; }
    public function isCompleted(): bool { return $this->status === self::STATUS_COMPLETED; }

    /**
     * A collection can only be edited (name/price/items) while in draft.
     */
    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * Can be published (draft → active) only when it has at least one item.
     */
    public function canBePublished(): bool
    {
        return $this->isDraft() && $this->items()->exists();
    }
}
