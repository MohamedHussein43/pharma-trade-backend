<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
     private const NEW_TYPE = 'drugs_missing_english';

    public function up(): void
    {
        $values = $this->currentEnumValues();

        if (in_array(self::NEW_TYPE, $values, true)) {
            return; // already added — safe to re-run
        }

        $values[] = self::NEW_TYPE;
        $this->setEnum($values);
    }

    public function down(): void
    {
        DB::table('notifications')->where('type', self::NEW_TYPE)->update(['type' => 'general']);
        $this->setEnum(array_values(array_diff($this->currentEnumValues(), [self::NEW_TYPE])));
    }

    private function currentEnumValues(): array
    {
        $col = DB::selectOne("
            SELECT COLUMN_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'notifications'
              AND COLUMN_NAME  = 'type'
        ");

        preg_match('/^enum\((.*)\)$/i', $col->COLUMN_TYPE, $m);

        return str_getcsv($m[1], ',', "'", '');
    }

    private function setEnum(array $values): void
    {
        $list = implode(',', array_map(
            fn ($v) => "'" . str_replace("'", "''", $v) . "'",
            $values
        ));

        DB::statement("ALTER TABLE notifications MODIFY COLUMN type ENUM({$list}) NOT NULL DEFAULT 'general'");
    }
};
