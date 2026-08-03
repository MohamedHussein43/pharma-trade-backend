<?php

namespace App\Http\Controllers\Api\Supplier;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessInventoryUpload;
use App\Models\InventoryUploadLog;
use App\Models\SupplierInventory;
use App\Models\Drug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierInventoryController extends Controller
{
    // =========================================================
    // GET /api/v1/supplier/inventory
    // is_catalog_matched is included so the supplier app can
    // optionally show a small indicator on unmatched rows —
    // purely informational, never blocks anything.
    // =========================================================
        public function index(Request $request): JsonResponse
    {
        $supplier = $request->user()->supplier;
        if (! $supplier) {
            return response()->json(['message' => 'Supplier account not found.'], 404);
        }

        $query = SupplierInventory::with('drug:id,name,trade_name,dosage_form,strength')
            ->where('supplier_id', $supplier->id);

        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('drug_name_raw', 'LIKE', "%{$term}%")
                  ->orWhereHas('drug', function ($q2) use ($term) {
                      $q2->where('trade_name', 'LIKE', "%{$term}%")
                         ->orWhere('name',      'LIKE', "%{$term}%");
                  });
            });
        }

        if ($request->boolean('in_stock_only')) {
            $query->where('quantity_available', '>', 0);
        }

        $inventory = $query->orderBy('last_updated', 'desc')
            ->paginate($request->get('per_page', 30));

        $inventory->getCollection()->transform(fn($item) => $this->formatItem($item));

        $lastUpload = InventoryUploadLog::where('supplier_id', $supplier->id)
            ->whereIn('status', ['completed', 'processing'])
            ->latest()->first();

        return response()->json([
            'message' => 'Inventory retrieved successfully.',
            'data'    => [
                'inventory'   => $inventory,
                'last_upload' => $lastUpload ? [
                    'upload_id'    => $lastUpload->id,
                    'status'       => $lastUpload->status,
                    'uploaded_at'  => $lastUpload->created_at,
                    'total_rows'   => $lastUpload->total_rows,
                    'success_rows' => $lastUpload->success_rows,
                    'failed_rows'  => $lastUpload->failed_rows,
                ] : null,
            ],
        ], 200);
    }


    // =========================================================
    // GET /api/v1/supplier/inventory/{id}
    // Middleware: auth:sanctum + active.user + role:supplier
    //
    // Returns single inventory item detail.
    // Used when supplier taps a drug row to open the edit screen.
    // =========================================================
     public function show(Request $request, int $id): JsonResponse
    {
        $supplier = $request->user()->supplier;
        $item = SupplierInventory::with('drug')
            ->where('supplier_id', $supplier->id)
            ->where('id', $id)->first();

        if (! $item) {
            return response()->json(['message' => 'Inventory item not found.'], 404);
        }

        return response()->json([
            'message' => 'Inventory item retrieved successfully.',
            'data'    => $this->formatItem($item),
        ], 200);
    }

    // =========================================================
    // PUT /api/v1/supplier/inventory/{id}
    // Middleware: auth:sanctum + active.user + role:supplier
    //
    // Supplier edits one inventory row.
    // What the supplier CAN edit:
    //   - drug_name_raw  (how they refer to this product)
    //   - quantity_available
    //   - unit_price
    //   - discount_pct
    //
    // What they CANNOT edit:
    //   - drug_id        (catalog link — set by the system)
    //   - is_catalog_matched (set by the system)
    //   - supplier_id    (always their own)
    //
    // If drug_name_raw changes, we re-attempt catalog matching
    // so the drug_id stays as accurate as possible.
    // =========================================================
    // =========================================================
    // PUT /api/v1/supplier/inventory/{id}
    // Updated to check drug_id conflict instead of drug_name_raw
    // =========================================================
    public function update(Request $request, int $id): JsonResponse
    {
        $supplier = $request->user()->supplier;
        $item = SupplierInventory::where('supplier_id', $supplier->id)
            ->where('id', $id)->first();

        if (! $item) {
            return response()->json(['message' => 'Inventory item not found.'], 404);
        }

        $request->validate([
            'drug_name_raw'      => ['sometimes', 'string', 'min:2', 'max:255'],
            'quantity_available' => ['sometimes', 'integer', 'min:0'],
            'order_limit'        => ['nullable', 'integer', 'min:1'],
            'public_price'       => ['sometimes', 'numeric', 'min:0'],
            'pharmacist_price'   => ['sometimes', 'numeric', 'min:0'],
            'unit_price'         => ['sometimes', 'numeric', 'min:0'],
            'discount_pct'       => ['sometimes', 'numeric', 'min:0', 'max:100'],
        ]);

        $updateData = [];

        if ($request->filled('drug_name_raw') && $request->drug_name_raw !== $item->drug_name_raw) {
            $drug = $this->getOrCreateDrug($request->drug_name_raw);
            $drugExists = SupplierInventory::where('supplier_id', $supplier->id)
                ->where('drug_id', $drug->id)->where('id', '!=', $id)->exists();
            if ($drugExists) {
                return response()->json([
                    'message' => 'You already have this drug in your inventory.',
                    'errors'  => ['drug_name_raw' => ['This drug already exists in your inventory.']],
                ], 422);
            }
            $updateData['drug_id']       = $drug->id;
            $updateData['drug_name_raw'] = $request->drug_name_raw;
        }

        if ($request->has('quantity_available')) {
            $updateData['quantity_available'] = (int)$request->quantity_available;
        }

        // Fix: explicitly handle null order_limit (remove limit)
        if ($request->exists('order_limit')) {
            $updateData['order_limit'] = $request->order_limit !== null
                ? (int)$request->order_limit
                : null;
        }

        if ($request->has('public_price')) {
            $updateData['public_price'] = round((float)$request->public_price, 2);
        }

        // Accept either pharmacist_price or unit_price
        $newPrice = $request->pharmacist_price ?? $request->unit_price ?? null;
        if ($newPrice !== null) {
            $updateData['pharmacist_price'] = round((float)$newPrice, 2);
            $updateData['unit_price']       = round((float)$newPrice, 2);
        }

        if ($request->has('discount_pct')) {
            $updateData['discount_pct'] = round((float)$request->discount_pct, 2);
        }

        if (empty($updateData)) {
            return response()->json(['message' => 'No changes provided.'], 422);
        }

        $updateData['last_updated'] = now();
        $item->update($updateData);

        return response()->json([
            'message' => 'Inventory item updated successfully.',
            'data'    => $this->formatItem($item->fresh('drug')),
        ], 200);
    }

    // =========================================================
    // PATCH /api/v1/supplier/inventory/{id}/quantity
    // Middleware: auth:sanctum + active.user + role:supplier
    //
    // Quick quantity-only update — for when the supplier just
    // wants to update stock count without opening the full
    // edit screen. Useful for quick daily stock corrections.
    // =========================================================
    public function updateQuantity(Request $request, int $id): JsonResponse
    {
        $supplier = $request->user()->supplier;
        $item = SupplierInventory::where('supplier_id', $supplier->id)
            ->where('id', $id)->first();

        if (! $item) {
            return response()->json(['message' => 'Inventory item not found.'], 404);
        }

        $request->validate([
            'quantity_available' => ['required', 'integer', 'min:0'],
        ]);

        $item->update([
            'quantity_available' => (int)$request->quantity_available,
            'last_updated'       => now(),
        ]);

        return response()->json([
            'message' => 'Quantity updated successfully.',
            'data'    => [
                'id'                 => $item->id,
                'drug_name'          => $item->drug?->trade_name ?? $item->drug_name_raw,
                'quantity_available' => $item->quantity_available,
                'last_updated'       => $item->last_updated,
            ],
        ], 200);
    }
 // =========================================================
    // POST /api/v1/supplier/inventory
    // Manually add a single drug — uses get-or-create
    // =========================================================
  public function store(Request $request): JsonResponse
    {
        $supplier = $request->user()->supplier;
        if (! $supplier) {
            return response()->json(['message' => 'Supplier account not found.'], 404);
        }

        $request->validate([
            'drug_id'            => ['required_without:drug_name_raw', 'integer', 'exists:drugs,id'],
            'drug_name_raw'      => ['required_without:drug_id', 'string', 'min:2', 'max:255'],
            'quantity_available' => ['required', 'integer', 'min:0'],
            'order_limit'        => ['nullable', 'integer', 'min:1'],
            'public_price'       => ['required', 'numeric', 'min:0'],
            // Accept either pharmacist_price OR unit_price — they mean the same thing
            'pharmacist_price'   => ['required_without:unit_price', 'numeric', 'min:0'],
            'unit_price'         => ['required_without:pharmacist_price', 'numeric', 'min:0'],
            'discount_pct'       => ['nullable', 'numeric', 'min:0', 'max:100'],
        ], [
            'drug_id.required_without'          => 'Either a drug selection or drug name is required.',
            'drug_name_raw.required_without'    => 'Either a drug selection or drug name is required.',
            'pharmacist_price.required_without' => 'Pharmacist price or unit price is required.',
            'unit_price.required_without'       => 'Unit price or pharmacist price is required.',
            'public_price.required'             => 'Public price is required.',
        ]);

        if ($request->filled('drug_id')) {
            $drug = Drug::find($request->drug_id);
        } else {
            $drug = $this->getOrCreateDrug($request->drug_name_raw);
        }

        $exists = SupplierInventory::where('supplier_id', $supplier->id)
            ->where('drug_id', $drug->id)->exists();

        if ($exists) {
            return response()->json([
                'message' => 'This drug already exists in your inventory. Use edit to update it.',
                'errors'  => ['drug_id' => ['This drug already exists in your inventory.']],
            ], 422);
        }

        // Accept either pharmacist_price or unit_price — same field
        $pharmacistPrice = round((float)($request->pharmacist_price ?? $request->unit_price), 2);
        $publicPrice     = round((float)$request->public_price, 2);

        $item = SupplierInventory::create([
            'supplier_id'        => $supplier->id,
            'drug_id'            => $drug->id,
            'drug_name_raw'      => $request->drug_name_raw ?? $drug->trade_name,
            'quantity_available' => (int)$request->quantity_available,
            'order_limit'        => $request->filled('order_limit') ? (int)$request->order_limit : null,
            'unit_price'         => $pharmacistPrice,
            'public_price'       => $publicPrice,
            'pharmacist_price'   => $pharmacistPrice,
            'discount_pct'       => round((float)($request->discount_pct ?? 0), 2),
            'last_updated'       => now(),
        ]);

        return response()->json([
            'message' => 'Drug added to inventory successfully.',
            'data'    => $this->formatItem($item->load('drug')),
        ], 201);
    }


    // =========================================================
    // DELETE /api/v1/supplier/inventory/{id}
    // =========================================================
     public function destroy(Request $request, int $id): JsonResponse
    {
        $supplier = $request->user()->supplier;
        $item = SupplierInventory::where('supplier_id', $supplier->id)
            ->where('id', $id)->first();

        if (! $item) {
            return response()->json(['message' => 'This drug is not in your inventory.'], 404);
        }

        $drugName = $item->drug?->trade_name ?? $item->drug_name_raw;
        $item->delete();

        return response()->json([
            'message' => "'{$drugName}' removed from inventory successfully.",
        ], 200);
    }

    // =========================================================
    // PRIVATE — Format inventory item for response
    // =========================================================
     private function formatItem(SupplierInventory $item): array
    {
        return [
            'id'                 => $item->id,
            'drug_name'          => $item->drug?->trade_name ?? $item->drug_name_raw,
            'drug_name_raw'      => $item->drug_name_raw,
            'quantity_available' => $item->quantity_available,
            'order_limit'        => $item->order_limit,   // ← add this
            'unit_price'         => $item->unit_price,
            'public_price'       => $item->public_price,
            'pharmacist_price'   => $item->pharmacist_price,
            'discount_pct'       => $item->discount_pct,
            'effective_price'    => $item->pharmacist_price,/*round(
                (float)$item->pharmacist_price * (1 - (float)$item->discount_pct / 100), 2
            ),*/
            'savings_per_unit'   => ($item->public_price - $item->pharmacist_price),/*round(
                (float)$item->public_price - ((float)$item->pharmacist_price * (1 - (float)$item->discount_pct / 100)), 2
            ),*/
            'last_updated'       => $item->last_updated,
            'drug'               => $item->drug ? [
                'id'          => $item->drug->id,
                'name'        => $item->drug->name,
                'trade_name'  => $item->drug->trade_name,
                'dosage_form' => $item->drug->dosage_form,
                'strength'    => $item->drug->strength,
            ] : null,
        ];
    }


    // =========================================================
    // PRIVATE — Attempt catalog match (enrichment only)
    // Same logic as the upload job but for single rows.
    // =========================================================
    private function attemptCatalogMatch(string $drugName): ?int
    {
        $searchName = strtolower(trim($drugName));

        // Exact trade name match
        $drug = \App\Models\Drug::where('is_active', 1)
            ->whereRaw('LOWER(trade_name) = ?', [$searchName])
            ->first();
        if ($drug) return $drug->id;

        // Exact Arabic name match
        $drug = \App\Models\Drug::where('is_active', 1)
            ->whereRaw('LOWER(name) = ?', [$searchName])
            ->first();
        if ($drug) return $drug->id;

        // Partial match — trade name starts with search term
        $drug = \App\Models\Drug::where('is_active', 1)
            ->whereRaw('LOWER(trade_name) LIKE ?', [$searchName . '%'])
            ->first();
        if ($drug) return $drug->id;

        return null;
    }

    // =========================================================
    // Upload methods — kept from previous controller
    // =========================================================
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ], [
            'file.required' => 'Please upload an inventory file.',
            'file.mimes'    => 'File must be .xlsx, .xls, or .csv format.',
            'file.max'      => 'File must not exceed 10MB.',
        ]);

        $supplier = $request->user()->supplier;
        if (! $supplier) {
            return response()->json(['message' => 'Supplier account not found.'], 404);
        }

        $alreadyProcessing = InventoryUploadLog::where('supplier_id', $supplier->id)
            ->where('status', 'processing')->exists();

        if ($alreadyProcessing) {
            return response()->json([
                'message' => 'You already have an upload being processed. Please wait.',
            ], 422);
        }

        $originalName = $request->file('file')->getClientOriginalName();
        $storagePath  = $request->file('file')->storeAs(
            'inventory-uploads/' . $supplier->id,
            time() . '_' . $originalName,
            'private'
        );

        $log = InventoryUploadLog::create([
            'supplier_id'  => $supplier->id,
            'uploaded_by'  => $request->user()->id,
            'file_name'    => $originalName,
            'total_rows'   => 0,
            'success_rows' => 0,
            'failed_rows'  => 0,
            'status'       => 'processing',
        ]);

        ProcessInventoryUpload::dispatch($supplier->id, $log->id, $storagePath);

        return response()->json([
            'message' => 'File received. Processing in background.',
            'data'    => [
                'upload_id'        => $log->id,
                'file_name'        => $originalName,
                'status'           => 'processing',
                'check_status_url' => "/api/v1/supplier/inventory/upload-status/{$log->id}",
            ],
        ], 202);
    }

    public function uploadStatus(Request $request, int $id): JsonResponse
    {
        $supplier = $request->user()->supplier;
        $log = InventoryUploadLog::where('id', $id)
            ->where('supplier_id', $supplier->id)->first();

        if (! $log) {
            return response()->json(['message' => 'Upload log not found.'], 404);
        }

        $data = [
            'upload_id'    => $log->id,
            'file_name'    => $log->file_name,
            'status'       => $log->status,
            'total_rows'   => $log->total_rows,
            'success_rows' => $log->success_rows,
            'failed_rows'  => $log->failed_rows,
            'uploaded_at'  => $log->created_at,
        ];

        if (in_array($log->status, ['completed', 'failed']) && $log->error_log) {
            $data['errors'] = json_decode($log->error_log, true);
        }

        return response()->json(['message' => 'Upload status retrieved.', 'data' => $data], 200);
    }
    public function uploadHistory(Request $request): JsonResponse
        {
            $supplier = $request->user()->supplier;
            if (! $supplier) {
                return response()->json(['message' => 'Supplier account not found.'], 404);
            }

            $logs = InventoryUploadLog::where('supplier_id', $supplier->id)
                ->orderBy('created_at', 'desc')->limit(10)->get()
                ->map(fn($log) => [
                    'id'           => $log->id,
                    'file_name'    => $log->file_name,
                    'total_rows'   => $log->total_rows,
                    'success_rows' => $log->success_rows,
                    'failed_rows'  => $log->failed_rows,
                    'status'       => $log->status,
                    'errors'       => $log->error_log ? json_decode($log->error_log, true) : [],
                    'uploaded_at'  => $log->created_at,
                ]);

            return response()->json(['message' => 'Upload history retrieved.', 'data' => $logs], 200);
        }

       private function getOrCreateDrug(string $drugName): Drug
    {
        $searchName = strtolower(trim($drugName));

        $drug = Drug::where('is_active', 1)
            ->whereRaw('LOWER(trade_name) = ?', [$searchName])->first();
        if ($drug) return $drug;

        $drug = Drug::where('is_active', 1)
            ->whereRaw('LOWER(name) = ?', [$searchName])->first();
        if ($drug) return $drug;

        $drug = Drug::where('is_active', 1)
            ->whereRaw('LOWER(trade_name) LIKE ?', [$searchName . '%'])->first();
        if ($drug) return $drug;

        return Drug::create([
            'name'       => $drugName,
            'trade_name' => $drugName,
            'is_active'  => 1,
        ]);
    }

}
