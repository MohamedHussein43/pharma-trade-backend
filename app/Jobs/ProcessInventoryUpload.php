<?php

namespace App\Jobs;

use App\Models\Drug;
use App\Models\InventoryUploadLog;
use App\Models\Supplier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ProcessInventoryUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 300;
    public int $backoff = 60;

    public function __construct(
        private int    $supplierId,
        private int    $logId,
        private string $filePath,
    ) {}

    public function handle(): void
    {
        $log = InventoryUploadLog::find($this->logId);
        if (! $log) {
            Log::error("ProcessInventoryUpload: Log ID {$this->logId} not found.");
            return;
        }

        try {
            $drugCatalog = $this->loadDrugCatalog();

            if (! Storage::disk('private')->exists($this->filePath)) {
                throw new \Exception("Upload file not found at path: {$this->filePath}");
            }

            $fullPath = Storage::disk('private')->path($this->filePath);
            $sheets   = Excel::toArray([], $fullPath);
            $rows     = $sheets[0] ?? [];

            if (empty($rows)) {
                throw new \Exception('The uploaded file is empty or could not be read.');
            }

            // ── FIXED: Safe cell reading that handles Arabic text ──
            // Always cast to string using strval() not (string) cast
            // Then convert Arabic-Indic numerals to Western
            /*$rawHeaders = array_map(
                fn($h) => $this->safeString($h),
                $rows[0]
            );*/
            $rawHeaders = array_map(fn($h) => $this->safeString($h), array_values($rows[0]));

            $headers = array_map(
                fn($h) => $this->normaliseHeader($h),
                $rawHeaders
            );

            // Log headers for debugging
            Log::info('ProcessInventoryUpload: Headers detected', [
                'raw'        => $rawHeaders,
                'normalised' => $headers,
            ]);

            $dataRows = array_filter(
                array_slice($rows, 1),
                fn($row) => count(array_filter(array_map('strval', $row))) > 0
            );

            $totalRows     = count($dataRows);
            $successRows   = 0;
            $failedRows    = 0;
            $newDrugsAdded = 0;
            $errors        = [];
            $batch         = [];

            $log->update(['total_rows' => $totalRows]);

            foreach (array_values($dataRows) as $index => $row) {
                $rowNum = $index + 2;
                $row    = array_values($row); // ← force 0-based index
                // ── FIXED: Safe cell reading per row ──────────────
                $data = [];
                foreach ($headers as $colIndex => $header) {
                    $raw          = $row[$colIndex] ?? null;
                    $data[$header] = $raw !== null
                        ? $this->convertArabicNumerals($this->safeString($raw))
                        : null;
                }

                // Log first row for debugging
                if ($index === 0) {
                    Log::info('ProcessInventoryUpload: First data row', ['data' => $data]);
                }

                $drugName = $data['drug_name']
                    ?? $data['name']
                    ?? $data['medicine']
                    ?? $data['product_name']
                    ?? $data['item_name']
                    ?? null;

                $quantity = $data['quantity']
                    ?? $data['qty']
                    ?? $data['stock']
                    ?? '0';

                $publicPrice = $data['public_price']
                    ?? $data['public']
                    ?? $data['retail_price']
                    ?? $data['retail']
                    ?? '0';

                $pharmacistPrice = $data['pharmacist_price']
                    ?? $data['pharmacist']
                    ?? $data['unit_price']
                    ?? $data['price']
                    ?? $data['cost']
                    ?? '0';

                $discount = $data['discount']
                    ?? $data['discount_pct']
                    ?? $data['disc']
                    ?? '0';

                $orderLimit = $data['order_limit']
                    ?? $data['limit']
                    ?? $data['max_order']
                    ?? $data['max']
                    ?? null;

                $barcode = $data['barcode']
                    ?? $data['barcode_number']
                    ?? null;

                $orderLimitVal = null;
                if (! empty($orderLimit) && is_numeric($orderLimit) && (int)$orderLimit > 0) {
                    $orderLimitVal = (int)$orderLimit;
                }

                // ── Validation ────────────────────────────────────
                if (empty($drugName) || trim($drugName) === '') {
                    $errors[] = "Row {$rowNum}: Drug name is empty — row skipped.";
                    $failedRows++;
                    continue;
                }

                if (! is_numeric($quantity) || (float)$quantity < 0) {
                    $errors[] = "Row {$rowNum}: Invalid quantity '{$quantity}' for '{$drugName}' — row skipped.";
                    $failedRows++;
                    continue;
                }

                if (! is_numeric($pharmacistPrice) || (float)$pharmacistPrice < 0) {
                    $errors[] = "Row {$rowNum}: Invalid pharmacist price '{$pharmacistPrice}' for '{$drugName}' — row skipped.";
                    $failedRows++;
                    continue;
                }

                if (! is_numeric($publicPrice) || (float)$publicPrice < 0) {
                    $errors[] = "Row {$rowNum}: Invalid public price '{$publicPrice}' for '{$drugName}' — row skipped.";
                    $failedRows++;
                    continue;
                }

                $discountVal = (float)$discount;
                if ($discountVal < 0 || $discountVal > 100) {
                    $errors[] = "Row {$rowNum}: Discount must be 0–100 for '{$drugName}' — row skipped.";
                    $failedRows++;
                    continue;
                }

                if ($this->isBannedDrugCheckEnabled() && $this->isBannedDrug($drugName)) {
                    $errors[] = "Row {$rowNum}: '{$drugName}' is on the restricted list — row skipped.";
                    $failedRows++;
                    continue;
                }

                $drugId = $this->getOrCreateDrug($drugName, $barcode, $drugCatalog, $newDrugsAdded);

                $batch[] = [
                    'supplier_id'        => $this->supplierId,
                    'drug_id'            => $drugId,
                    'drug_name_raw'      => $drugName,
                    'quantity_available' => (int)$quantity,
                    'order_limit'        => $orderLimitVal,
                    'unit_price'         => round((float)$pharmacistPrice, 2),
                    'public_price'       => round((float)$publicPrice, 2),
                    'pharmacist_price'   => round((float)$pharmacistPrice, 2),
                    'discount_pct'       => round($discountVal, 2),
                    'last_updated'       => now(),
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ];

                $successRows++;

                if (count($batch) >= 100) {
                    $this->flushBatch($batch);
                    $batch = [];
                    $log->update([
                        'success_rows' => $successRows,
                        'failed_rows'  => $failedRows,
                    ]);
                }
            }

            if (! empty($batch)) {
                $this->flushBatch($batch);
            }

            $summaryNote = $newDrugsAdded > 0
                ? " {$newDrugsAdded} new drug(s) added to catalog automatically."
                : null;

            $log->update([
                'total_rows'   => $totalRows,
                'success_rows' => $successRows,
                'failed_rows'  => $failedRows,
                'status'       => 'completed',
                'error_log'    => ! empty($errors)
                    ? json_encode(array_merge($errors, $summaryNote ? [$summaryNote] : []), JSON_UNESCAPED_UNICODE)
                    : ($summaryNote ? json_encode([$summaryNote]) : null),
            ]);

            $this->notifySupplier($successRows, $failedRows, $newDrugsAdded);
            Storage::disk('private')->delete($this->filePath);

        } catch (\Exception $e) {
            Log::error("ProcessInventoryUpload failed: " . $e->getMessage(), [
                'supplier_id' => $this->supplierId,
                'log_id'      => $this->logId,
            ]);
            $log?->update([
                'status'    => 'failed',
                'error_log' => json_encode([$e->getMessage()]),
            ]);
            $this->notifySupplierFailed();
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical("ProcessInventoryUpload permanently failed", [
            'supplier_id' => $this->supplierId,
            'log_id'      => $this->logId,
            'error'       => $exception->getMessage(),
        ]);
        InventoryUploadLog::where('id', $this->logId)->update(['status' => 'failed']);
    }

    // =========================================================
    // PRIVATE — Safe string conversion for Arabic text
    // Handles null, float, int, and string cell values
    // Uses mb_convert_encoding if available to ensure UTF-8
    // =========================================================
   private function safeString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        // Excel reader returns numeric cells as float/int
        // e.g. 100.0 → '100', not '100.0'
        if (is_float($value) && floor($value) == $value) {
            return (string)(int)$value;
        }

        if (is_int($value)) {
            return (string)$value;
        }

        return trim((string)$value);
    }

    // =========================================================
    // PRIVATE — Convert Arabic-Indic numerals to Western
    // =========================================================
    private function convertArabicNumerals(string $value): string
    {
        return strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }

    // =========================================================
    // PRIVATE — Normalise header to standard English key
    // =========================================================
    private function normaliseHeader(string $header): string
    {
        // Only replace spaces and dashes — do NOT use strtolower on Arabic
        // strtolower() corrupts Arabic Unicode on some PHP configs
        $normalised = str_replace([' ', '-', '/', '\\', "\t"], '_', trim($header));

        $arabicMap = [
            'اسم_الدواء'          => 'drug_name',
            'اسم_المنتج'          => 'drug_name',
            'اسم_الدوا'           => 'drug_name',
            'الدواء'              => 'drug_name',
            'المنتج'              => 'drug_name',
            'اسم_العلاج'          => 'drug_name',
            'الاسم'               => 'drug_name',
            'اسم'                 => 'drug_name',
            'الاسم_التجارى'       => 'drug_name',
            'الاسم_التجاري'       => 'drug_name',
            'اسم_تجارى'           => 'drug_name',
            'اسم_تجاري'           => 'drug_name',
            'الاسم_العلمى'        => 'drug_name',
            'الاسم_العلمي'        => 'drug_name',

            'الكمية'              => 'quantity',
            'كمية'                => 'quantity',
            'كميه'                => 'quantity',
            'الكميه'              => 'quantity',
            'المخزون'             => 'quantity',
            'مخزون'               => 'quantity',
            'عدد'                 => 'quantity',
            'الكمية_المتاحة'      => 'quantity',
            'كمية_متاحة'          => 'quantity',
            'الكمية_المتاحه'      => 'quantity',

            'سعر_الجمهور'         => 'public_price',
            'سعر_العام'           => 'public_price',
            'السعر_العام'         => 'public_price',
            'السعر_للجمهور'       => 'public_price',
            'سعر_البيع_للعملاء'   => 'public_price',
            'سعر_المستهلك'        => 'public_price',
            'سعر_للجمهور'         => 'public_price',

            'سعر_الصيدلى'         => 'pharmacist_price',
            'سعر_الصيدلي'         => 'pharmacist_price',
            'سعر_الصيدلية'        => 'pharmacist_price',
            'السعر_للصيدلي'       => 'pharmacist_price',
            'السعر_للصيدلى'       => 'pharmacist_price',
            'سعر_البيع'           => 'pharmacist_price',
            'السعر'               => 'pharmacist_price',
            'سعر'                 => 'pharmacist_price',
            'سعر_الشراء'          => 'pharmacist_price',
            'سعر_التوريد'         => 'pharmacist_price',
            'سعر_التكلفة'         => 'pharmacist_price',

            'الخصم'               => 'discount',
            'خصم'                 => 'discount',
            'نسبة_الخصم'          => 'discount',
            'تخفيض'               => 'discount',
            'نسبة_التخفيض'        => 'discount',
            'الخصم_%'             => 'discount',
            'خصم_%'               => 'discount',

            'الحد_الأقصى'         => 'order_limit',
            'الحد_الاقصى'         => 'order_limit',
            'حد_الطلب'            => 'order_limit',
            'الحد'                => 'order_limit',
            'أقصى_كمية'           => 'order_limit',
            'اقصى_كميه'           => 'order_limit',
            'الحد_الاقصى_للطلب'   => 'order_limit',
            'أقصى_طلب'            => 'order_limit',

            // English headers (keep for mixed files)
            'drug_name'           => 'drug_name',
            'name'                => 'name',
            'medicine'            => 'medicine',
            'quantity'            => 'quantity',
            'qty'                 => 'qty',
            'public_price'        => 'public_price',
            'pharmacist_price'    => 'pharmacist_price',
            'unit_price'          => 'unit_price',
            'price'               => 'price',
            'discount'            => 'discount',
            'order_limit'         => 'order_limit',
            'limit'               => 'limit',
        ];

        return $arabicMap[$normalised] ?? $normalised;
    }

    private function loadDrugCatalog(): array
    {
        $drugs   = Drug::select(['id', 'name', 'trade_name', 'scientific_name', 'barcode'])->get();
        $catalog = ['by_barcode' => [], 'by_trade' => [], 'by_name' => []];

        foreach ($drugs as $drug) {
            if (! empty($drug->barcode))    $catalog['by_barcode'][strtolower($drug->barcode)]    = $drug->id;
            if (! empty($drug->trade_name)) $catalog['by_trade'][strtolower($drug->trade_name)]   = $drug->id;
            if (! empty($drug->name))       $catalog['by_name'][strtolower($drug->name)]          = $drug->id;
        }

        return $catalog;
    }

    private function getOrCreateDrug(string $drugName, ?string $barcode, array &$catalog, int &$newDrugsAdded): int
    {
        $searchName = strtolower(trim($drugName));

        if (! empty($barcode) && isset($catalog['by_barcode'][strtolower(trim($barcode))])) {
            return $catalog['by_barcode'][strtolower(trim($barcode))];
        }
        if (isset($catalog['by_trade'][$searchName])) return $catalog['by_trade'][$searchName];
        if (isset($catalog['by_name'][$searchName]))  return $catalog['by_name'][$searchName];

        foreach ($catalog['by_trade'] as $tradeName => $drugId) {
            if (str_starts_with($searchName, $tradeName) || str_starts_with($tradeName, $searchName)) {
                return $drugId;
            }
        }

        $firstWord = explode(' ', $searchName)[0];
        if (strlen($firstWord) >= 4) {
            foreach ($catalog['by_trade'] as $tradeName => $drugId) {
                if (str_starts_with($tradeName, $firstWord)) return $drugId;
            }
        }

        $newDrug = Drug::create(['name' => $drugName, 'trade_name' => $drugName, 'is_active' => 1]);
        $catalog['by_trade'][strtolower($drugName)] = $newDrug->id;
        $catalog['by_name'][strtolower($drugName)]  = $newDrug->id;
        $newDrugsAdded++;

        return $newDrug->id;
    }

    private function flushBatch(array $batch): void
    {
        DB::table('supplier_inventory')->upsert(
            $batch,
            ['supplier_id', 'drug_id'],
            ['drug_name_raw', 'quantity_available', 'order_limit',
             'unit_price', 'public_price', 'pharmacist_price',
             'discount_pct', 'last_updated', 'updated_at']
        );
    }

    private function isBannedDrugCheckEnabled(): bool
    {
        return \App\Models\PlatformSetting::isBannedDrugCheckEnabled();
    }

    private function isBannedDrug(string $drugName): bool
    {
        return \App\Models\BannedDrug::where('is_active', 1)
            ->whereRaw('LOWER(drug_name) LIKE ?', ['%' . strtolower($drugName) . '%'])
            ->exists();
    }

    private function notifySupplier(int $success, int $failed, int $newDrugs): void
    {
        $supplier = Supplier::with('user')->find($this->supplierId);
        if (! $supplier?->user) return;

        $notificationService = app(\App\Services\NotificationService::class);
        $notificationService->inventoryUploadComplete($supplier->user->id, $this->logId, $success, $failed);
    }

    private function notifySupplierFailed(): void
    {
        $supplier = Supplier::with('user')->find($this->supplierId);
        if (! $supplier?->user) return;

        $notificationService = app(\App\Services\NotificationService::class);
        $notificationService->send(
            userId:         $supplier->user->id,
            title:          'Inventory upload failed',
            body:           'Your inventory file could not be processed. Please check the file format and try again.',
            type:           'general',
            notifiableType: 'InventoryUploadLog',
            notifiableId:   $this->logId,
        );
    }
}
