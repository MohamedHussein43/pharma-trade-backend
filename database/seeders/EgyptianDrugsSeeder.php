<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class EgyptianDrugsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
        public function run(): void
    {
        $path = storage_path('app/egyptian-drugs.xlsx');

        if (! file_exists($path)) {
            $this->command->error('File not found: ' . $path);
            $this->command->info('Place the Excel file at: ' . $path);
            return;
        }

        $this->command->info('Loading spreadsheet...');
        $spreadsheet = IOFactory::load($path);
        $sheet       = $spreadsheet->getActiveSheet();
        $rows        = $sheet->toArray(null, true, true, true);

        if (count($rows) < 2) {
            $this->command->error('No data rows found.');
            return;
        }

        // Normalize headers from row 1
        $headerRow = array_shift($rows);
        $headers   = array_map(fn($h) => strtolower(trim((string) $h)), array_values($headerRow));

        $total    = count($rows);
        $inserted = 0;
        $updated  = 0;
        $chunk    = [];
        $chunkSize = 200;

        $this->command->info("Processing {$total} rows...");
        $bar = $this->command->getOutput()->createProgressBar($total);
        $bar->start();

        foreach ($rows as $row) {
            $values = array_values($row);
            while (count($values) < count($headers)) $values[] = null;
            $data = array_combine($headers, array_slice($values, 0, count($headers)));

            $nameEn = trim((string) ($data['commercial_name_en'] ?? ''));
            $nameAr = trim((string) ($data['commercial_name_ar'] ?? ''));

            if ($nameEn === '' && $nameAr === '') {
                $bar->advance();
                continue;
            }

            // Match on trade_name (= commercial_name_en) or name
            $existing = DB::table('drugs')
                ->where('trade_name', $nameEn)
                ->orWhere('name', $nameEn)
                ->first();

            $payload = array_filter([
                'commercial_name_ar' => $nameAr ?: null,
                'scientific_name'    => trim((string) ($data['scientific_name'] ?? '')) ?: null,
                'manufacturer'       => trim((string) ($data['manufacturer']    ?? '')) ?: null,
                'updated_at'         => now(),
            ], fn($v) => $v !== null);

            if ($existing) {
                DB::table('drugs')->where('id', $existing->id)->update($payload);
                $updated++;
            } else {
                DB::table('drugs')->insert(array_merge($payload, [
                    'name'       => $nameEn ?: $nameAr,
                    'trade_name' => $nameEn ?: null,
                    'is_active'  => true,
                    'created_at' => now(),
                ]));
                $inserted++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info("Done. Inserted: {$inserted}, Updated: {$updated}.");
    }
}
