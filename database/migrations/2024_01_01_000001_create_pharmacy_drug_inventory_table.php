<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacy_drug_inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_branch_id')->constrained('pharmacy_branches')->cascadeOnDelete();
            $table->foreignId('drug_id')->constrained('drugs');
            $table->foreignId('zone_id')->constrained('zones');
            $table->unsignedInteger('quantity_available')->default(0);
            $table->decimal('unit_price', 10, 2);
            $table->date('expiry_date')->nullable();
            $table->string('batch_number', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['seller_branch_id', 'drug_id'], 'uq_pdi_branch_drug');
            $table->index('zone_id', 'idx_pdi_zone');
            $table->index('drug_id', 'idx_pdi_drug');
            $table->index('seller_branch_id', 'idx_pdi_seller');
            $table->index(['zone_id', 'is_active'], 'idx_pdi_zone_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_drug_inventory');
    }
};
