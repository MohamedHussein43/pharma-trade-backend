<?php

namespace App\Http\Controllers\Api\Pharmacy;

use App\Http\Requests\Pharmacy\StoreInventoryRequest;
use App\Http\Requests\Pharmacy\StoreInventoryImportRequest;
use App\Jobs\ProcessMarketplaceImport;
use App\Models\PharmacyDrugInventory;
use App\Models\PharmacyMarketplaceImportLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MarketplaceInventoryController extends BaseMarketplaceController
{
    /**
     * GET /pharmacy/marketplace/inventory
     * List the authenticated branch's private drug inventory.
     */
    public function index(Request $request): JsonResponse
    {
        $branch = $this->sellerBranch();

        $inventory = PharmacyDrugInventory::with('drug')
            ->forBranch($branch->id)
            ->when($request->boolean('active_only'), fn ($q) => $q->active())
            ->orderByDesc('updated_at')
            ->paginate(50);

        return response()->json($inventory);
    }

    /**
     * POST /pharmacy/marketplace/inventory
     * Add or update a single drug in the branch's inventory.
     * zone_ids are fetched from pharmacy_branch_zones — never from the request.
     */
    public function store(StoreInventoryRequest $request): JsonResponse
    {
        $branch = $this->sellerBranch();
        $data   = $request->validated();

        // Fetch all zone IDs this branch belongs to from pharmacy_branch_zones
        $zoneIds = DB::table('pharmacy_branch_zones')
            ->where('branch_id', $branch->id)
            ->pluck('zone_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if (empty($zoneIds)) {
            return response()->json([
                'message' => 'الفرع غير مرتبط بأي منطقة. يرجى التواصل مع الإدارة.',
            ], 422);
        }

        $inventory = PharmacyDrugInventory::updateOrCreate(
            [
                'seller_branch_id' => $branch->id,
                'drug_id'          => $data['drug_id'],
            ],
            [
                'zone_ids'           => $zoneIds,   // JSON array e.g. [1, 3, 7]
                'quantity_available' => $data['quantity_available'],
                'unit_price'         => $data['unit_price'],
                'expiry_date'        => $data['expiry_date'] ?? null,
                'batch_number'       => $data['batch_number'] ?? null,
                'is_active'          => true,
            ]
        );

        return response()->json($inventory->load('drug'), 201);
    }

    public function import(StoreInventoryImportRequest $request): JsonResponse
    {
        $branch  = $this->sellerBranch();
        $zoneIds = DB::table('pharmacy_branch_zones')
            ->where('branch_id', $branch->id)
            ->pluck('zone_id')
            ->map(fn ($id) => (int) $id)
            ->values()->all();

        if (empty($zoneIds)) {
            return response()->json(['message' => 'الفرع غير مرتبط بأي منطقة. يرجى التواصل مع الإدارة.'], 422);
        }

        $file   = $request->file('file');
        $base64 = base64_encode(file_get_contents($file->getRealPath()));
        Log::info('branch debug', ['branch' => $branch->toArray(), 'zoneIds' => $zoneIds]);
        $log = PharmacyMarketplaceImportLog::create([
            'seller_branch_id' => $branch->id,
            'zone_ids'         => $zoneIds,
            'file_name'        => $file->getClientOriginalName(),
            'file_content'     => $base64,
            'status'           => 'pending',
            'processed_rows'   => 0,
        ]);

        ProcessMarketplaceImport::dispatchSync($log->id);

        return response()->json([
            'message' => 'جارٍ معالجة الملف. سيتم تحديث المخزون قريباً.',
            'log_id'  => $log->id,
        ], 202);
    }

    /**
     * DELETE /pharmacy/marketplace/inventory/{inventory}
     * Remove a drug from the branch's inventory (only if not in any active collection).
     */
    public function destroy(PharmacyDrugInventory $inventory): JsonResponse
    {
        $this->authorizeOwnership($inventory);

        $inUse = $inventory->collectionItems()
            ->whereHas('collection', fn ($q) => $q->whereIn('status', ['draft', 'active', 'ordered']))
            ->exists();

        if ($inUse) {
            return response()->json([
                'message' => 'لا يمكن حذف هذا الصنف لأنه مستخدم في مجموعة نشطة.',
            ], 422);
        }

        $inventory->delete();

        return response()->json(['message' => 'تم حذف الصنف من المخزون.']);
    }

    private function authorizeOwnership(PharmacyDrugInventory $inventory): void
    {
        $branch = $this->sellerBranch();

        if ($inventory->seller_branch_id !== $branch->id) {
            abort(403, 'غير مصرح.');
        }
    }
}
