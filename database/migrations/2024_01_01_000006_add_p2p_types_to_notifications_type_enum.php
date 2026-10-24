<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * MySQL ENUM modification: we must list ALL existing values plus the new ones.
     * Adjust the existing values below to match your current notifications.type enum exactly.
     */
    public function up(): void
    {
        DB::statement("
            ALTER TABLE notifications
            MODIFY COLUMN type ENUM(
                'new_order',
                'order_confirmed',
                'order_shipped',
                'order_delivered',
                'order_cancelled',
                'shortage_reported',
                'shortage_resolved',
                'shortage_accepted',
                'order_cancelled_by_pharmacy',
                'registration_submitted',
                'registration_approved',
                'registration_declined',
                'general',
                -- P2P marketplace (new)
                'p2p_order_received',
                'p2p_order_accepted',
                'p2p_order_rejected',
                'p2p_order_ready',
                'p2p_order_completed',
                'p2p_order_cancelled',
                'p2p_collection_available'
            ) NOT NULL DEFAULT 'general'
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE notifications
            MODIFY COLUMN type ENUM(
                'new_order',
                'order_confirmed',
                'order_shipped',
                'order_delivered',
                'order_cancelled',
                'shortage_reported',
                'shortage_resolved',
                'shortage_accepted',
                'order_cancelled_by_pharmacy',
                'registration_submitted',
                'registration_approved',
                'registration_declined',
                'general'
            ) NOT NULL DEFAULT 'general'
        ");
    }
};
