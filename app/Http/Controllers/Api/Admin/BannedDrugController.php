<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\BannedDrug;
use App\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BannedDrugController extends Controller
{
    // =========================================================
    // GET /api/v1/admin/settings/banned-drug-check
    // Returns the current state of the Phase 2 feature flag.
    // =========================================================
    public function getFlag(): JsonResponse
    {
        $setting = PlatformSetting::first();

        return response()->json([
            'data' => [
                'enabled' => (bool)($setting?->enable_banned_drug_check ?? false),
            ],
        ], 200);
    }

    // =========================================================
    // PATCH /api/v1/admin/settings/banned-drug-check
    // Admin toggles the flag on/off.
    // This is the single switch that activates Phase 2 — when
    // off, every upload behaves exactly like before this change.
    // =========================================================
    public function toggleFlag(Request $request): JsonResponse
    {
        $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $setting = PlatformSetting::first();

        if (! $setting) {
            $setting = PlatformSetting::create([
                'enable_banned_drug_check' => $request->enabled,
                'updated_by'               => $request->user()->id,
            ]);
        } else {
            $setting->update([
                'enable_banned_drug_check' => $request->enabled,
                'updated_by'               => $request->user()->id,
            ]);
        }

        return response()->json([
            'message' => 'Setting updated successfully.',
            'data'    => [
                'enabled' => (bool)$setting->enable_banned_drug_check,
            ],
        ], 200);
    }

    // =========================================================
    // GET /api/v1/admin/banned-drugs
    // List all entries in the restricted drug list.
    // =========================================================
    public function index(Request $request): JsonResponse
    {
        $drugs = BannedDrug::with('addedBy:id,name')
            ->orderBy('drug_name')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'message' => 'Banned drugs retrieved successfully.',
            'data'    => $drugs,
        ], 200);
    }

    // =========================================================
    // POST /api/v1/admin/banned-drugs
    // Admin adds a drug name to the restricted list.
    // Matching during upload is done via substring search, so
    // entries should be the core active-ingredient or product
    // name rather than a full product description.
    // =========================================================
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'drug_name' => ['required', 'string', 'max:255', 'unique:banned_drugs,drug_name'],
            'reason'    => ['nullable', 'string', 'max:1000'],
        ], [
            'drug_name.unique' => 'This drug name is already on the restricted list.',
        ]);

        $banned = BannedDrug::create([
            'drug_name' => $request->drug_name,
            'reason'    => $request->reason,
            'added_by'  => $request->user()->id,
            'is_active' => 1,
        ]);

        return response()->json([
            'message' => 'Drug added to restricted list successfully.',
            'data'    => $banned,
        ], 201);
    }

    // =========================================================
    // PATCH /api/v1/admin/banned-drugs/{id}/toggle
    // Activate or deactivate a restricted drug entry without
    // deleting its history.
    // =========================================================
    public function toggle(int $id): JsonResponse
    {
        $banned = BannedDrug::find($id);

        if (! $banned) {
            return response()->json(['message' => 'Entry not found.'], 404);
        }

        $banned->update(['is_active' => ! $banned->is_active]);

        return response()->json([
            'message' => 'Restricted list entry ' . ($banned->is_active ? 'activated' : 'deactivated') . ' successfully.',
            'data'    => $banned,
        ], 200);
    }

    // =========================================================
    // DELETE /api/v1/admin/banned-drugs/{id}
    // =========================================================
    public function destroy(int $id): JsonResponse
    {
        $banned = BannedDrug::find($id);

        if (! $banned) {
            return response()->json(['message' => 'Entry not found.'], 404);
        }

        $banned->delete();

        return response()->json(['message' => 'Entry removed successfully.'], 200);
    }

    // =========================================================
    // GET /api/v1/admin/rejected-inventory-rows
    // Shows every row that was rejected by the banned-drug check
    // across all suppliers — for admin audit purposes.
    // Empty if the flag has never been enabled.
    // =========================================================
    public function rejectedRows(Request $request): JsonResponse
    {
        $rows = \App\Models\RejectedInventoryRow::with([
            'supplier:id,name',
            'bannedDrug:id,drug_name,reason',
        ])
        ->orderBy('created_at', 'desc')
        ->paginate($request->get('per_page', 20));

        return response()->json([
            'message' => 'Rejected rows retrieved successfully.',
            'data'    => $rows,
        ], 200);
    }
}
