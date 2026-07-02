<?php

namespace App\Jobs;

use App\Models\BannedDrug;
use App\Models\Drug;
use App\Models\InventoryUploadLog;
use App\Models\Notification;
use App\Models\PlatformSetting;
use App\Models\RejectedInventoryRow;
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
    // KEY CHANGE FROM V1:
    //   No row is ever rejected for failing to match the master
    //   drug catalog. Every row with a valid name, quantity, and
    //   price is saved. Catalog matching still runs — but only
    //   to ENRICH the row with a drug_id when possible, never
    //   to BLOCK it. drug_name_raw is always saved as-is.
    //
    //   The ONLY thing that can still reject a row is the
    //   Phase 2 banned-drug check, and only when an admin has
    //   turned the feature flag on. With the flag off (default)
    //   this step is skipped entirely and has zero effect.
    // =========================================================
    public function handle(): void
    {
        $log = InventoryUploadLog::find($this->logId);
        if (! $log) {
            Log::error("ProcessInventoryUpload: Log ID {$this->logId} not found.");
            return;
        }

        try {
            // Load catalog for best-effort enrichment (not gating)
            $drugCatalog = $this->loadDrugCatalog();

            // Load banned drug list — only matters if the flag is on
            $bannedCheckEnabled = $this->isBannedDrugCheckEnabled();
            $bannedCatalog      = $bannedCheckEnabled ? $this->loadBannedDrugs() : [];

            if (! Storage::disk('private')->exists($this->filePath)) {
                throw new \Exception("Upload file not found at path: {$this->filePath}");
            }

            $fullPath = Storage::disk('private')->path($this->filePath);
            $sheets   = Excel::toArray([], $fullPath);
            $rows     = $sheets[0] ?? [];

            if (empty($rows)) {
                throw new \Exception('The uploaded file is empty or could not be read.');
            }

            $headers = array_map(
                fn($h) => strtolower(trim(str_replace([' ', '-'], '_', (string)$h))),
                $rows[0]
            );

            $dataRows = array_filter(
                array_slice($rows, 1),
                fn($row) => count(array_filter(array_map('strval', $row))) > 0
            );

            $totalRows   = count($dataRows);
            $successRows = 0;
            $failedRows  = 0;   // only true data errors now — empty name, bad number
            $rejectedRows = 0;  // banned-drug rejections (Phase 2 only)
            $errors      = [];
            $batch       = [];

            $log->update(['total_rows' => $totalRows]);

            foreach (array_values($dataRows) as $index => $row) {
                $rowNum = $index + 2;

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

                $quantity = $data['quantity']  ?? $data['qty']        ?? $data['stock'] ?? '0';
                $price    = $data['price']     ?? $data['unit_price'] ?? $data['cost']  ?? '0';
                $discount = $data['discount']  ?? $data['discount_pct'] ?? $data['disc'] ?? '0';
                $barcode  = $data['barcode']   ?? $data['barcode_number'] ?? $data['ean'] ?? null;

                // ── Basic data validity — these are the ONLY rejections
                // not related to the banned list. A row needs a name,
                // a non-negative quantity, and a non-negative price.
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

                if (! is_numeric($price) || (float)$price < 0) {
                    $errors[] = "Row {$rowNum}: Invalid price '{$price}' for '{$drugName}' — row skipped.";
                    $failedRows++;
                    continue;
                }

                $discountVal = (float)$discount;
                if ($discountVal < 0 || $discountVal > 100) {
                    $errors[] = "Row {$rowNum}: Discount '{$discount}' must be between 0 and 100 for '{$drugName}' — row skipped.";
                    $failedRows++;
                    continue;
                }

                // ── Phase 2 — banned drug check (flag-gated) ──────────
                // This is the ONLY place a legitimately well-formed
                // row can still be rejected. Skipped entirely when
                // the flag is off — zero behavior change from that
                // point on.
                if ($bannedCheckEnabled) {
                    $bannedId = $this->matchBannedDrug($drugName, $bannedCatalog);
                    if ($bannedId) {
                        RejectedInventoryRow::create([
                            'supplier_id'    => $this->supplierId,
                            'upload_log_id'  => $this->logId,
                            'drug_name_raw'  => $drugName,
                            'banned_drug_id' => $bannedId,
                            'quantity'       => (int)$quantity,
                            'price'          => round((float)$price, 2),
                        ]);

                        $errors[] = "Row {$rowNum}: '{$drugName}' is on the restricted drug list and was not added.";
                        $rejectedRows++;
                        continue;
                    }
                }

                // ── Catalog matching — enrichment only, never gates ──
                // If we find a match, great — we link drug_id and the
                // row gets full catalog benefits (search, standard
                // naming). If not, the row still saves fully using
                // exactly what the supplier typed in drug_name_raw.
                $drugId = $this->matchDrug($drugName, $barcode, $drugCatalog);

                $batch[] = [
                    'supplier_id'         => $this->supplierId,
                    'drug_id'             => $drugId,              // nullable now
                    'drug_name_raw'       => $drugName,             // always saved
                    'is_catalog_matched'  => $drugId ? 1 : 0,
                    'quantity_available'  => (int)$quantity,
                    'unit_price'          => round((float)$price, 2),
                    'discount_pct'        => round($discountVal, 2),
                    'last_updated'        => now(),
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ];

                $successRows++;

                if (count($batch) >= 100) {
                    $this->flushBatch($batch);
                    $batch = [];

                    $log->update([
                        'success_rows' => $successRows,
                        'failed_rows'  => $failedRows + $rejectedRows,
                    ]);
                }
            }

            if (! empty($batch)) {
                $this->flushBatch($batch);
            }

            $log->update([
                'total_rows'   => $totalRows,
                'success_rows' => $successRows,
                'failed_rows'  => $failedRows + $rejectedRows,
                'status'       => 'completed',
                'error_log'    => ! empty($errors)
                    ? json_encode($errors, JSON_UNESCAPED_UNICODE)
                    : null,
            ]);

            $this->notifySupplier($successRows, $failedRows, $rejectedRows);

            Storage::disk('private')->delete($this->filePath);

        } catch (\Exception $e) {
            Log::error("ProcessInventoryUpload failed: " . $e->getMessage(), [
                'supplier_id' => $this->supplierId,
                'log_id'      => $this->logId,
                'file'        => $this->filePath,
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
        Log::critical("ProcessInventoryUpload permanently failed after {$this->tries} tries", [
            'supplier_id' => $this->supplierId,
            'log_id'      => $this->logId,
            'error'       => $exception->getMessage(),
        ]);

        InventoryUploadLog::where('id', $this->logId)
            ->update(['status' => 'failed']);
    }

    // =========================================================
    // Catalog loading — used ONLY for enrichment, not gating
    // =========================================================
    private function loadDrugCatalog(): array
    {
        $drugs = Drug::where('is_active', 1)
            ->select(['id', 'name', 'trade_name', 'scientific_name', 'barcode'])
            ->get();

        $catalog = ['by_barcode' => [], 'by_trade' => [], 'by_name' => []];

        foreach ($drugs as $drug) {
            if (! empty($drug->barcode))    $catalog['by_barcode'][strtolower($drug->barcode)]    = $drug->id;
            if (! empty($drug->trade_name)) $catalog['by_trade'][strtolower($drug->trade_name)]    = $drug->id;
            if (! empty($drug->name))       $catalog['by_name'][strtolower($drug->name)]           = $drug->id;
        }

        return $catalog;
    }

    private function matchDrug(string $drugName, ?string $barcode, array $catalog): ?int
    {
        if (! empty($barcode)) {
            $key = strtolower(trim($barcode));
            if (isset($catalog['by_barcode'][$key])) return $catalog['by_barcode'][$key];
        }

        $searchName = strtolower(trim($drugName));

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

        return null; // No match — row still saves, just unenriched
    }

    // =========================================================
    // Phase 2 — Feature flag check
    // Reads the single-row platform_settings table.
    // Defaults to OFF (false) if the row is somehow missing.
    // =========================================================
    private function isBannedDrugCheckEnabled(): bool
    {
        $setting = PlatformSetting::first();
        return $setting ? (bool)$setting->enable_banned_drug_check : false;
    }

    // =========================================================
    // Phase 2 — Load the banned drug list into memory
    // Only called when the flag is on.
    // =========================================================
    private function loadBannedDrugs(): array
    {
        return BannedDrug::where('is_active', 1)
            ->pluck('id', 'drug_name')
            ->mapWithKeys(fn($id, $name) => [strtolower($name) => $id])
            ->toArray();
    }

    // =========================================================
    // Phase 2 — Check a drug name against the banned list
    // Simple substring match — a banned entry "Tramadol" will
    // catch "Tramadol 50mg", "Tramadol Hydrochloride", etc.
    // =========================================================
    private function matchBannedDrug(string $drugName, array $bannedCatalog): ?int
    {
        $searchName = strtolower(trim($drugName));

        foreach ($bannedCatalog as $bannedName => $bannedId) {
            if (str_contains($searchName, $bannedName)) {
                return $bannedId;
            }
        }

        return null;
    }

    private function flushBatch(array $batch): void
    {
        DB::table('supplier_inventory')->upsert(
            $batch,
            ['supplier_id', 'drug_name_raw'],
            ['drug_id', 'is_catalog_matched', 'quantity_available',
             'unit_price', 'discount_pct', 'last_updated', 'updated_at']
        );
    }

    private function notifySupplier(int $success, int $failed, int $rejected): void
    {
        $supplier = Supplier::with('user')->find($this->supplierId);
        if (! $supplier?->user) return;

        $parts = ["{$success} drugs updated successfully."];
        if ($failed > 0)   $parts[] = "{$failed} rows had invalid data.";
        if ($rejected > 0) $parts[] = "{$rejected} rows were restricted and not added.";

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
