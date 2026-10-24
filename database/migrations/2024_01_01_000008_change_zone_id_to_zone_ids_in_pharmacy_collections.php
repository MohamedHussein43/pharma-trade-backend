<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacy_collections', function (Blueprint $table) {
            // Drop FK if it exists — comment this line out if zone_id had no FK constraint
            if ($this->hasForeignKey('pharmacy_collections', 'pharmacy_collections_zone_id_foreign')) {
                $table->dropForeign(['zone_id']);
            }

            $table->dropColumn('zone_id');
            $table->json('zone_ids')->after('seller_branch_id');
        });
    }

    public function down(): void
    {
        Schema::table('pharmacy_collections', function (Blueprint $table) {
            $table->dropColumn('zone_ids');
            $table->unsignedBigInteger('zone_id')->nullable()->after('seller_branch_id');
        });
    }

    private function hasForeignKey(string $table, string $fkName): bool
    {
        $foreignKeys = \Illuminate\Support\Facades\DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_TYPE = 'FOREIGN KEY'
               AND CONSTRAINT_NAME = ?",
            [$table, $fkName]
        );

        return ! empty($foreignKeys);
    }
};
