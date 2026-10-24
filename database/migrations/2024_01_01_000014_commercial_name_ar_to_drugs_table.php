<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('drugs', 'commercial_name_ar')) {
            Schema::table('drugs', function (Blueprint $table) {
                $table->string('commercial_name_ar')->nullable()->after('trade_name');
                $table->text('scientific_name')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('drugs', function (Blueprint $table) {
            if (Schema::hasColumn('drugs', 'commercial_name_ar')) {
                $table->dropColumn('commercial_name_ar');
            }
        });
    }
};