<?php

namespace App\Jobs;

use App\Models\Drug;
use App\Models\InventoryUploadLog;
use App\Models\Notification;
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

    // =========================================================
    // handle()
    //
    // NEW FLOW v3:
    //   For each row in the Excel file:
    //     1. Validate basic data (name, qty, price)
    //     2. Try to match drug name to existing drugs table
    //     3. If NO match → create a new drug row in drugs table
    //     4. Upsert supplier_inventory using the drug_id
    //
    //   Result: zero drug name duplication across suppliers.
    //   Every supplier_inventory row always has a valid drug_id.
    //   drug_name_raw saved as audit reference only.
    // =========================================================
    public function handle(): void
    {
        $log = InventoryUploadLog::find($this->logId);
        if (! $log) {
            Log::error("ProcessInventoryUpload: Log ID {$this->logId} not found.");
            return;
        }

        try {
            // ── Load drug catalog into memory for matching ────────
            // One DB query. All matching runs in PHP — fast.
            $drugCatalog = $this->loadDrugCatalog();

            // ── Read file ─────────────────────────────────────────
            if (! Storage::disk('private')->exists($this->filePath)) {
                throw new \Exception("Upload file not found at path: {$this->filePath}");
            }

            $fullPath = Storage::disk('private')->path($this->filePath);
            $sheets   = Excel::toArray([], $fullPath);
            $rows     = $sheets[0] ?? [];

            if (empty($rows)) {
                throw new \Exception('The uploaded file is empty or could not be read.');
            }

            // Normalise headers
            $headers = array_map(
                fn($h) => strtolower(str_replace([' ', '-'], '_', trim((string)$h))),
                $rows[0]
            );

            $dataRows = array_filter(
                array_slice($rows, 1),
                fn($row) => count(array_filter(array_map('strval', $row))) > 0
            );

            $totalRows    = count($dataRows);
            $successRows  = 0;
            $failedRows   = 0;
            $newDrugsAdded = 0;  // track how many new drugs were created
            $errors       = [];
            $batch        = [];

            $log->update(['total_rows' => $totalRows]);

            // ── Process each row ──────────────────────────────────
            foreach (array_values($dataRows) as $index => $row) {
                $rowNum = $index + 2;

                // Map columns
                $data = [];
                foreach ($headers as $colIndex => $header) {
                    $data[$header] = isset($row[$colIndex])
                        ? trim((string)$row[$colIndex])
                        : null;
                }

                $drugName = $data['drug_name']
                    ?? $data['name']
                    ?? $data['medicine']
                    ?? $data['product_name']
                    ?? $data['item_name']
                    ?? null;

                $orderLimit = $data['order_limit']
                    ?? $data['limit']
                    ?? $data['max_order']
                    ?? $data['order_max']
                    ?? $data['max']
                    ?? null;
                    
                $publicPrice = $data['public_price']
                    ?? $data['public']
                    ?? $data['retail_price']
                    ?? $data['retail']
                    ?? '0';

                // pharmacist_price = what pharmacy pays supplier
                // unit_price is accepted as an alias for pharmacist_price
                $pharmacistPrice = $data['pharmacist_price']
                    ?? $data['pharmacist']
                    ?? $data['unit_price']      // ← accept unit_price as alias
                    ?? $data['price']
                    ?? $data['cost']
                    ?? '0';

                $quantity = $data['quantity']
                        ?? $data['qty']             
                        ?? $data['stock']       
                        ?? '0';

                $discount = $data['discount']           
                        ?? $data['discount_pct']    
                        ?? $data['disc']        
                        ?? '0';
                        
                $barcode  = $data['barcode']            
                        ?? $data['barcode_number']  
                        ?? null;

                // Validate order_limit is a positive integer if provided
                $orderLimitVal = null;
                if (! empty($orderLimit) && is_numeric($orderLimit) && (int)$orderLimit > 0) {
                    $orderLimitVal = (int)$orderLimit;
                }

                // ── Basic validation ──────────────────────────────
                if (empty($drugName)) {
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
                    $errors[] = "Row {$rowNum}: Invalid pharmacistPrice '{$pharmacistPrice}' for '{$drugName}' — row skipped.";
                    $failedRows++;
                    continue;
                }

                if (! is_numeric($publicPrice) || (float)$publicPrice < 0) {
                    $errors[] = "Row {$rowNum}: Invalid publicPrice '{$publicPrice}' for '{$drugName}' — row skipped.";
                    $failedRows++;
                    continue;
                }

                $discountVal = (float)$discount;
                if ($discountVal < 0 || $discountVal > 100) {
                    $errors[] = "Row {$rowNum}: Discount '{$discount}' must be between 0–100 for '{$drugName}' — row skipped.";
                    $failedRows++;
                    continue;
                }

                // ── Phase 2 banned drug check (flag-gated) ────────
                if ($this->isBannedDrugCheckEnabled()) {
                    if ($this->isBannedDrug($drugName)) {
                        $errors[] = "Row {$rowNum}: '{$drugName}' is on the restricted list — row skipped.";
                        $failedRows++;
                        continue;
                    }
                }

                // ── Step 3: Get or create drug in master catalog ──
                $drugId = $this->getOrCreateDrug(
                    $drugName,
                    $barcode,
                    $drugCatalog,
                    $newDrugsAdded
                );

                // Add to batch
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

                // Flush every 100 rows
                if (count($batch) >= 100) {
                    $this->flushBatch($batch);
                    $batch = [];
                    $log->update([
                        'success_rows' => $successRows,
                        'failed_rows'  => $failedRows,
                    ]);
                }
            }

            // Flush remaining
            if (! empty($batch)) {
                $this->flushBatch($batch);
            }

            // Build summary message including new drugs count
            $summaryNote = $newDrugsAdded > 0
                ? " {$newDrugsAdded} new drug(s) were added to the master catalog automatically."
                : null;

            $log->update([
                'total_rows'   => $totalRows,
                'success_rows' => $successRows,
                'failed_rows'  => $failedRows,
                'status'       => 'completed',
                'error_log'    => ! empty($errors)
                    ? json_encode(
                        array_merge(
                            $errors,
                            $summaryNote ? [$summaryNote] : []
                        ),
                        JSON_UNESCAPED_UNICODE
                      )
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

        InventoryUploadLog::where('id', $this->logId)
            ->update(['status' => 'failed']);
    }

    // =========================================================
    // PRIVATE — Load drug catalog into memory
    // Returns multiple lookup maps for fast in-memory matching
    // =========================================================
    private function loadDrugCatalog(): array
    {
        $drugs = Drug::select(['id', 'name', 'trade_name', 'scientific_name', 'barcode'])
            ->get();

        $catalog = [
            'by_barcode' => [],
            'by_trade'   => [],
            'by_name'    => [],
        ];

        foreach ($drugs as $drug) {
            if (! empty($drug->barcode)) {
                $catalog['by_barcode'][strtolower($drug->barcode)] = $drug->id;
            }
            if (! empty($drug->trade_name)) {
                $catalog['by_trade'][strtolower($drug->trade_name)] = $drug->id;
            }
            if (! empty($drug->name)) {
                $catalog['by_name'][strtolower($drug->name)] = $drug->id;
            }
        }

        return $catalog;
    }

    // =========================================================
    // PRIVATE — Get existing drug OR create a new one
    //
    // This is the core change in v3:
    //   - Try to match the drug name to an existing drugs row
    //   - If found → return its ID (no duplication)
    //   - If not found → INSERT a new row into drugs table
    //     with drug_name_raw as trade_name, then return new ID
    //
    // The $catalog array is passed by reference so newly created
    // drugs are added to the in-memory map immediately — if the
    // same drug name appears twice in the file, the second row
    // finds it in memory instead of creating a duplicate.
    // =========================================================
    private function getOrCreateDrug(
        string  $drugName,
        ?string $barcode,
        array   &$catalog,   // passed by reference — updated in place
        int     &$newDrugsAdded
    ): int {
        $searchName = strtolower(trim($drugName));

        // Strategy 1 — Barcode exact match
        if (! empty($barcode)) {
            $key = strtolower(trim($barcode));
            if (isset($catalog['by_barcode'][$key])) {
                return $catalog['by_barcode'][$key];
            }
        }

        // Strategy 2 — Exact trade name match
        if (isset($catalog['by_trade'][$searchName])) {
            return $catalog['by_trade'][$searchName];
        }

        // Strategy 3 — Exact Arabic name match
        if (isset($catalog['by_name'][$searchName])) {
            return $catalog['by_name'][$searchName];
        }

        // Strategy 4 — Partial trade name match
        // "Amoxil 500mg" matches existing trade_name "Amoxil"
        foreach ($catalog['by_trade'] as $tradeName => $drugId) {
            if (str_starts_with($searchName, $tradeName)
                || str_starts_with($tradeName, $searchName)) {
                return $drugId;
            }
        }

        // Strategy 5 — First word match (base drug name)
        $firstWord = explode(' ', $searchName)[0];
        if (strlen($firstWord) >= 4) {
            foreach ($catalog['by_trade'] as $tradeName => $drugId) {
                if (str_starts_with($tradeName, $firstWord)) {
                    return $drugId;
                }
            }
        }

        // ── No match found — create new drug in master catalog ──
        $newDrug = Drug::create([
            'name'       => $drugName,   // use raw name as display name
            'trade_name' => $drugName,   // also set as trade_name for searching
            'is_active'  => 1,
        ]);

        // Add to in-memory catalog so duplicates in same file
        // are caught without hitting the DB again
        $catalog['by_trade'][strtolower($drugName)] = $newDrug->id;
        $catalog['by_name'][strtolower($drugName)]  = $newDrug->id;

        $newDrugsAdded++;

        return $newDrug->id;
    }

    // =========================================================
    // PRIVATE — Batch upsert using (supplier_id, drug_id)
    // =========================================================
    private function flushBatch(array $batch): void
    {
        DB::table('supplier_inventory')->upsert(
            $batch,
            ['supplier_id', 'drug_id'],
            ['drug_name_raw', 'quantity_available','order_limit',
             'unit_price', 'public_price', 'pharmacist_price',
             'discount_pct', 'last_updated', 'updated_at']
        );
    }

    // =========================================================
    // PRIVATE — Phase 2 banned drug check
    // =========================================================
    private function isBannedDrugCheckEnabled(): bool
    {
        $setting = \App\Models\PlatformSetting::first();
        return $setting ? (bool)$setting->enable_banned_drug_check : false;
    }

    private function isBannedDrug(string $drugName): bool
    {
        return \App\Models\BannedDrug::where('is_active', 1)
            ->whereRaw('LOWER(drug_name) LIKE ?', ['%' . strtolower($drugName) . '%'])
            ->exists();
    }

    // =========================================================
    // PRIVATE — Notifications
    // =========================================================
    private function notifySupplier(int $success, int $failed, int $newDrugs): void
    {
        $supplier = Supplier::with('user')->find($this->supplierId);
        if (! $supplier?->user) return;

        $parts = ["{$success} drugs updated in your inventory."];
        if ($newDrugs > 0) $parts[] = "{$newDrugs} new drug(s) were added to the platform catalog.";
        if ($failed > 0)   $parts[] = "{$failed} rows had errors — check upload history.";

        Notification::create([
            'user_id'         => $supplier->user->id,
            'title'           => 'Inventory upload complete',
            'body'            => implode(' ', $parts),
            'type'            => 'general',
            'channel'         => 'push',
            'notifiable_type' => 'InventoryUploadLog',
            'notifiable_id'   => $this->logId,
            'is_read'         => 0,
        ]);
    }

    private function notifySupplierFailed(): void
    {
        $supplier = Supplier::with('user')->find($this->supplierId);
        if (! $supplier?->user) return;

        Notification::create([
            'user_id'         => $supplier->user->id,
            'title'           => 'Inventory upload failed',
            'body'            => 'Your inventory file could not be processed. Please check the file format and try again.',
            'type'            => 'general',
            'channel'         => 'push',
            'notifiable_type' => 'InventoryUploadLog',
            'notifiable_id'   => $this->logId,
            'is_read'         => 0,
        ]);
    }
}
