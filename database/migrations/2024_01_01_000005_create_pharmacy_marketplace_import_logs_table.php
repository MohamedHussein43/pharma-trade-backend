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
            $table->foreignId('branch_id')->constrained('pharmacy_branches')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->string('file_name', 255);
            $table->longText('file_content')->nullable(); // base64 for Railway ephemeral FS
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('success_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->enum('status', ['processing', 'completed', 'failed'])->default('processing');
            $table->json('error_log')->nullable(); // [{row_number, drug, errors[]}]
            $table->timestamps();

            $table->index('branch_id', 'idx_pmil_branch');
            $table->index('status',    'idx_pmil_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_marketplace_import_logs');
    }
};
