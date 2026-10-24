<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacy_collection_items', function (Blueprint $table) {
            // Seller-set price for this collection (may differ from inventory unit_price)
            $table->decimal('unit_price', 10, 2)->after('unit_price_snapshot');

            // Discount percentage applied by the seller (0–100)
            $table->decimal('discount_pct', 5, 2)->default(0)->after('unit_price');

            // unit_price after applying the discount — computed on the backend
            $table->decimal('pharmacist_price', 10, 2)->after('discount_pct');

            // quantity × pharmacist_price — the buyer actually pays this per line
            $table->decimal('pharmacist_line_total', 12, 2)->after('line_total');
        });
    }

    public function down(): void
    {
        Schema::table('pharmacy_collection_items', function (Blueprint $table) {
            $table->dropColumn([
                'unit_price',
                'discount_pct',
                'pharmacist_price',
                'pharmacist_line_total',
            ]);
        });
    }
};
