<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacy_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_branch_id')->constrained('pharmacy_branches')->cascadeOnDelete();
            $table->foreignId('zone_id')->constrained('zones');
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->decimal('total_price', 12, 2);
            $table->enum('status', ['draft', 'active', 'ordered', 'completed', 'suspended'])
                  ->default('draft');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('seller_branch_id', 'idx_pc_seller');
            $table->index('zone_id', 'idx_pc_zone');
            $table->index('status', 'idx_pc_status');
            $table->index(['zone_id', 'status'], 'idx_pc_zone_status'); // main buyer browse query
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_collections');
    }
};
