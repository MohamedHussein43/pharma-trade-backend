<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacy_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 50)->unique();
            $table->foreignId('collection_id')->constrained('pharmacy_collections');
            $table->foreignId('buyer_branch_id')->constrained('pharmacy_branches');
            $table->foreignId('seller_branch_id')->constrained('pharmacy_branches'); // denormalized
            $table->foreignId('created_by')->constrained('users');

            $table->enum('status', [
                'pending_collector',
                'pending_seller',
                'accepted',
                'ready_for_pickup',
                'completed',
                'cancelled',
                'rejected',
            ])->default('pending_collector');

            // Financial snapshots — set at order creation, never recalculated
            $table->decimal('total_amount', 12, 2);      // collection.total_price at order time
            $table->decimal('commission_pct', 5, 2)->default(1.00);
            $table->decimal('commission_amount', 10, 2);
            $table->decimal('seller_net_amount', 12, 2);

            // Collector info
            $table->string('collector_name', 200)->nullable();
            $table->string('collector_phone', 30)->nullable();
            $table->timestamp('collector_confirmed_at')->nullable();

            // Cancellation
            $table->text('cancel_reason')->nullable();
            $table->text('notes')->nullable();

            // Timestamp trail
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index('buyer_branch_id',  'idx_po_buyer');
            $table->index('seller_branch_id', 'idx_po_seller');
            $table->index('status',           'idx_po_status');
            $table->index('collection_id',    'idx_po_collection');
            $table->index('created_at',       'idx_po_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_orders');
    }
};
