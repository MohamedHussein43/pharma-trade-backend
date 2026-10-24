<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PharmacyOrder extends Model
{
    protected $fillable = [
        'order_number',
        'collection_id',
        'buyer_branch_id',
        'seller_branch_id',
        'created_by',
        'status',
        'total_amount',
        'commission_pct',
        'commission_amount',
        'seller_net_amount',
        'collector_name',
        'collector_phone',
        'collector_confirmed_at',
        'cancel_reason',
        'notes',
        'accepted_at',
        'ready_at',
        'completed_at',
    ];

    protected $casts = [
        'total_amount'           => 'decimal:2',
        'commission_pct'         => 'decimal:2',
        'commission_amount'      => 'decimal:2',
        'seller_net_amount'      => 'decimal:2',
        'collector_confirmed_at' => 'datetime',
        'accepted_at'            => 'datetime',
        'ready_at'               => 'datetime',
        'completed_at'           => 'datetime',
    ];

    // ─── Status constants ─────────────────────────────────────────────────────

    const STATUS_PENDING_COLLECTOR = 'pending_collector';
    const STATUS_PENDING_SELLER    = 'pending_seller';
    const STATUS_ACCEPTED          = 'accepted';
    const STATUS_READY_FOR_PICKUP  = 'ready_for_pickup';
    const STATUS_COMPLETED         = 'completed';
    const STATUS_CANCELLED         = 'cancelled';
    const STATUS_REJECTED          = 'rejected';

    /**
     * Valid transitions: [from => [to => who]]
     * 'buyer' | 'seller' | 'admin'
     */
    const TRANSITIONS = [
        'pending_collector' => [
            'pending_seller' => 'buyer',
            'cancelled'      => 'buyer',
        ],
        'pending_seller' => [
            'accepted'  => 'seller',
            'rejected'  => 'seller',
            'cancelled' => 'buyer',
        ],
        'accepted' => [
            'ready_for_pickup' => 'seller',
            'cancelled'        => 'seller',
        ],
        'ready_for_pickup' => [
            'completed' => 'seller',
        ],
    ];

    // ─── Relations ────────────────────────────────────────────────────────────

    public function collection(): BelongsTo
    {
        return $this->belongsTo(PharmacyCollection::class);
    }

    public function buyerBranch(): BelongsTo
    {
        return $this->belongsTo(PharmacyBranch::class, 'buyer_branch_id');
    }

    public function sellerBranch(): BelongsTo
    {
        return $this->belongsTo(PharmacyBranch::class, 'seller_branch_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ─── State helpers ────────────────────────────────────────────────────────

    public function canTransitionTo(string $newStatus, string $role): bool
    {
        return isset(self::TRANSITIONS[$this->status][$newStatus])
            && (self::TRANSITIONS[$this->status][$newStatus] === $role
                || $role === 'admin');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['completed', 'cancelled', 'rejected']);
    }

    /**
     * Whether cancellation/rejection should restore the collection to active.
     */
    public function shouldRestoreCollection(): bool
    {
        return in_array($this->status, ['cancelled', 'rejected']);
    }

    // ─── Order number generator ───────────────────────────────────────────────

    public static function generateOrderNumber(): string
    {
        return 'P2P-' . strtoupper(substr(uniqid(), -6)) . '-' . date('ymd');
    }
}
