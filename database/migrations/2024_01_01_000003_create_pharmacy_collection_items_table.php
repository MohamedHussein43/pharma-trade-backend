<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacy_collection_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')
                  ->constrained('pharmacy_collections')
                  ->cascadeOnDelete();
            $table->foreignId('inventory_id')
                  ->constrained('pharmacy_drug_inventory');
            $table->foreignId('drug_id')->constrained('drugs'); // denormalized for history
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price_snapshot', 10, 2); // copied from inventory at add time
            $table->decimal('line_total', 12, 2);          // quantity × unit_price_snapshot
            $table->timestamps();

            $table->index('collection_id', 'idx_pci_collection');
            $table->index('inventory_id',  'idx_pci_inventory');
            $table->index('drug_id',       'idx_pci_drug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_collection_items');
    }
};
