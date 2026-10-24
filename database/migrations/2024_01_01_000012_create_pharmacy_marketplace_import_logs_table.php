<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacy_marketplace_import_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_branch_id');
            $table->foreign('seller_branch_id')->references('id')->on('pharmacy_branches')->cascadeOnDelete();
            $table->json('zone_ids');
            $table->string('file_name');
            $table->longText('file_content')->nullable();
            $table->unsignedInteger('processed_rows')->default(0);
            $table->string('status')->default('pending');
            $table->json('error_log')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_marketplace_import_logs');
    }
};