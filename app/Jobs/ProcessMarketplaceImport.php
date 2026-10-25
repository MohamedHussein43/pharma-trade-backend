<?php

namespace App\Jobs;

use App\Models\Drug;
use App\Models\PharmacyDrugInventory;
use App\Models\PharmacyMarketplaceImportLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Services\DrugTransliterator;
use Illuminate\Support\Facades\Log;
use App\Services\ArabicNormalizer;
use App\Services\DrugMatcher;

class ProcessMarketplaceImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private array $drugCache = [];   // ← add this line
    public int $tries   = 1;
    public int $timeout = 300;

    public function __construct(
        private readonly int $logId
    ) {}

    public function handle(): void
    {
        $log = PharmacyMarketplaceImportLog::findOrFail($this->logId);
        $log->update(['status' => 'processing']);

        try {
            $rows   = $this->parseSpreadsheet($log->file_content);
            $errors = [];
            $count  = 0;

            DB::transaction(function () use ($log, $rows, &$errors, &$count) {
                foreach ($rows as $i => $data) {
                    $rowNum = $i + 2;

                    // Skip completely empty rows
                    $nonEmpty = array_filter($data, fn($v) => $v !== null && trim((string) $v) !== '');
                    if (empty($nonEmpty)) {
                        continue;
                    }

                    $drugName = $data['drug_name'] ?? $data['name'] ?? $data['medicine'] ?? null;
                    $quantity = $this->safeString($data['quantity'] ?? null)
                        ?: $this->safeString($data['qty']     ?? null)
                        ?: $this->safeString($data['stock']   ?? null)
                        ?: '0';
                    
                    $price = $this->safeString($data['unit_price']       ?? null) 
                        ?: $this->safeString($data['pharmacist_price'] ?? null)
                        ?: $this->safeString($data['price']            ?? null)
                        ?: $this->safeString($data['pharmacist']       ?? null)
                        ?: '0';
                    $expiry   = $data['expiry_date'] ?? $data['expiry'] ?? $data['تاريخ_الانتهاء'] ?? null;
                    $batch    = $data['batch_number'] ?? $data['batch'] ?? $data['رقم_الدفعة'] ?? null;

                    $drugName = $drugName !== null ? trim($this->safeString($drugName)) : null;

                    if (! $drugName) {
                        continue; // truly empty row
                    }
                    \Illuminate\Support\Facades\Log::info('p2p_import_drug_lookup', [
                        'row'        => $rowNum,
                        'drug_name'  => $drugName,
                        'data_keys'  => array_keys($data),
                    ]);
                    $drugId = $this->resolveDrug($drugName);

                    $qty        = (int)   preg_replace('/[^\d.\-]/', '', $this->convertArabicNumerals($this->safeString($quantity)));
                    $unitPrice  = (float) preg_replace('/[^\d.\-]/', '', $this->convertArabicNumerals($this->safeString($price)));

                    if (! $drugId) {
                        $errors[] = "Row {$rowNum}: drug not found — '{$drugName}'.";
                        continue;
                    }

                    if ($qty <= 0 || $unitPrice <= 0) {
                        $errors[] = "Row {$rowNum}: quantity ({$qty}) and unit_price ({$unitPrice}) must be positive.";
                        continue;
                    }

                    PharmacyDrugInventory::updateOrCreate(
                        [
                            'seller_branch_id' => $log->seller_branch_id,
                            'drug_id'          => $drugId,
                        ],
                        [
                            'zone_ids'           => $log->zone_ids,
                            'quantity_available' => $qty,
                            'unit_price'         => $unitPrice,
                            'expiry_date'        => $expiry ?: null,
                            'batch_number'       => $batch  ?: null,
                            'is_active'          => true,
                        ]
                    );

                    $count++;
                }
            });

            $log->update([
                'status'         => 'completed',
                'processed_rows' => $count,
                'error_log'      => $errors ?: null,
                'file_content'   => null,
            ]);

        } catch (\Throwable $e) {
            $log->update([
                'status'    => 'failed',
                'error_log' => [['error' => $e->getMessage()]],
            ]);
        }
    }

    // -------------------------------------------------------------------------

    private function parseSpreadsheet(string $base64Content): array
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'p2p_import_') . '.xlsx';
        file_put_contents($tmpPath, base64_decode($base64Content));

        try {
            $spreadsheet = IOFactory::load($tmpPath);
            $sheet       = $spreadsheet->getActiveSheet();
            $rows        = $sheet->toArray(null, true, true, true);

            if (count($rows) < 2) {
                return [];
            }

            // Normalize headers
            $headerRow = array_shift($rows);
            $keys = array_map(function ($h) {
                $h = ltrim(trim((string) $h), "\xEF\xBB\xBF"); // strip BOM
                return $this->normaliseHeader(mb_strtolower($h, 'UTF-8'));
            }, array_values($headerRow));

            $result = [];
            foreach ($rows as $row) {
                $values = array_values($row);

                while (count($values) < count($keys)) {
                    $values[] = null;
                }
                $values = array_slice($values, 0, count($keys));

                $result[] = array_combine($keys, $values);
            }

            return $result;

        } finally {
            @unlink($tmpPath);
        }
    }

    private function normaliseHeader(string $header): string
    {
        $normalised = str_replace([' ', '-', '/', '\\', "\t"], '_', trim($header));
        $normalised = trim($normalised, '_'); // remove leading/trailing underscores from extra spaces

        $arabicMap = [
            'اسم_الدواء'        => 'drug_name',
            'اسم_المنتج'        => 'drug_name',
            'الدواء'            => 'drug_name',
            'المنتج'            => 'drug_name',
            'الاسم'             => 'drug_name',
            'اسم'               => 'drug_name',
            'الاسم_التجارى'     => 'drug_name',
            'الاسم_التجاري'     => 'drug_name',
            'اسم_تجارى'         => 'drug_name',
            'اسم_تجاري'         => 'drug_name',
            'الاسم_العلمى'      => 'drug_name',
            'الاسم_العلمي'      => 'drug_name',
            'اسم_الصنف'         => 'drug_name',
            'أسم_الصنف'         => 'drug_name',
            'الكمية'            => 'quantity',
            'كمية'              => 'quantity',
            'كميه'              => 'quantity',
            'الكميه'            => 'quantity',
            'المخزون'           => 'quantity',
            'مخزون'             => 'quantity',
            'عدد'               => 'quantity',
            'الكمية_المتاحة'    => 'quantity',
            'كمية_متاحة'        => 'quantity',
            'سعر_الصيدلى'       => 'unit_price',
            'سعر_الصيدلي'       => 'unit_price',
            'سعر_الصيدلية'      => 'unit_price',
            'السعر_للصيدلي'     => 'unit_price',
            'السعر_للصيدلى'     => 'unit_price',
            'سعر_البيع'         => 'unit_price',
            'السعر'             => 'unit_price',
            'سعر'               => 'unit_price',
            'سعر_الشراء'        => 'unit_price',
            'سعر_التوريد'       => 'unit_price',
            'سعر_التكلفة'       => 'unit_price',
            'سعر_الجمهور'       => 'public_price',
            'السعر_العام'       => 'public_price',
            'السعر_للجمهور'     => 'public_price',
            'الخصم'             => 'discount',
            'خصم'               => 'discount',
            'نسبة_الخصم'        => 'discount',
            'الحد_الأقصى'       => 'order_limit',
            'الحد_الاقصى'       => 'order_limit',
            'حد_الطلب'          => 'order_limit',
        ];

        return $arabicMap[$normalised] ?? $normalised;
    }

    private function convertArabicNumerals(string $value): string
    {
        return strtr($value, [
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4',
            '٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4',
            '۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
        ]);
    }

    private function safeString(mixed $value): string
    {
        if ($value === null) return '';
        if (is_float($value) && floor($value) == $value) return (string)(int)$value;
        if (is_int($value)) return (string)$value;
        return trim((string)$value);
    }

    
    private function resolveDrug(mixed $identifier): ?int
    {
        if (! $identifier) return null;

        $identifier = trim((string) $identifier);
        if (is_numeric($identifier)) return (int) $identifier;   // drug id sent directly

        $isArabic = ArabicNormalizer::isArabic($identifier);
        if ($isArabic) {
            $identifier = ArabicNormalizer::normalize($identifier);
        }

        $cacheKey = mb_strtolower($identifier, 'UTF-8');
        if (isset($this->drugCache[$cacheKey])) {
            return $this->drugCache[$cacheKey];
        }

        $tokens = DrugMatcher::tokenize($identifier);
        $brand  = $tokens[0] ?? '';
        $id     = null;

        if ($isArabic) {
            // 1. Arabic brand prefix on commercial_name_ar → strength-checked pick
            $id = $this->bestByBrand(['commercial_name_ar'], $brand, $tokens);

            // 2. Transliterate brand → English prefix (+ k/c variants: ديكلاك → diclac)
            if (! $id) {
                $firstWord = strtolower(explode(' ', DrugTransliterator::transliterateArabicToLatin($brand))[0]);
                $variants  = array_unique(array_filter([
                    $firstWord,
                    str_replace(['k', 'q'], 'c', $firstWord),
                    str_replace('c', 'k', $firstWord),
                ]));
                foreach ($variants as $prefix) {
                    if ($id = $this->bestByBrand(['trade_name', 'name'], $prefix, $tokens)) break;
                }
            }

            // Fill the Arabic name onto the matched drug if it has none
            if ($id) {
                \App\Models\Drug::where('id', $id)
                    ->where(fn ($q) => $q->whereNull('commercial_name_ar')->orWhere('commercial_name_ar', ''))
                    ->update(['commercial_name_ar' => $identifier]);
            }

        } else {
            // 1. English brand prefix on trade_name / name → strength-checked pick
            $id = $this->bestByBrand(['trade_name', 'name'], $brand, $tokens);

            // 2. Transliterate → Arabic prefix → strength-checked pick
            if (! $id) {
                $firstWordAr = mb_substr(DrugTransliterator::transliterateLatinToArabic($brand), 0, 4, 'UTF-8');
                $id = $this->bestByBrand(['commercial_name_ar'], $firstWordAr, $tokens);
            }
        }

        if ($id) {
            $this->drugCache[$cacheKey] = $id;
        }

        // null → no drug with the right brand AND strength; the row is rejected
        return $id;
    }

        /**
     * Fetch every drug whose $columns start with $brand, then let DrugMatcher
     * pick the one with the right strength (compared on the English trade_name).
     */
    private function bestByBrand(array $columns, string $brand, array $tokens): ?int
    {
        if (mb_strlen($brand, 'UTF-8') < 3) return null;

        $candidates = \App\Models\Drug::where(function ($q) use ($columns, $brand) {
                foreach ($columns as $col) {
                    $q->orWhere($col, 'like', "{$brand}%");
                }
            })
            ->limit(300)
            ->get(['id', 'trade_name', 'name'])
            ->mapWithKeys(fn ($d) => [$d->id => $d->trade_name ?: $d->name]);

        return DrugMatcher::pickBest($tokens, $candidates);
    }
}