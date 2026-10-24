<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * total_price is now a cached sum of items' pharmacist_line_total,
     * recalculated on the backend whenever items change.
     * Make it nullable so a draft with no items yet is valid.
     */
    public function up(): void
    {
        Schema::table('pharmacy_collections', function (Blueprint $table) {
            $table->decimal('total_price', 12, 2)->nullable()->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('pharmacy_collections', function (Blueprint $table) {
            $table->decimal('total_price', 12, 2)->nullable(false)->change();
        });
    }
};
