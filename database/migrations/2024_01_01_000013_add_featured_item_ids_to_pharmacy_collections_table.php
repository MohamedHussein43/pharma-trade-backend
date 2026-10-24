<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacy_collections', function (Blueprint $table) {
            $table->json('featured_item_ids')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('pharmacy_collections', function (Blueprint $table) {
            $table->dropColumn('featured_item_ids');
        });
    }
};