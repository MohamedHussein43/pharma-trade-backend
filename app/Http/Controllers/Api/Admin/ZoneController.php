<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Zone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ZoneController extends Controller
{
    // =========================================================
    // GET /api/v1/admin/zones
    // Also accessible publicly for registration dropdowns
    // Returns all active zones
    // =========================================================
    public function index(Request $request): JsonResponse
    {
        $query = Zone::orderBy('governorate')->orderBy('name');

        // Allow filtering inactive zones for admin
        if ($request->has('include_inactive') && $request->user()?->isAdmin()) {
            // return all zones
        } else {
            $query->where('is_active', 1);
        }

        // Filter by governorate
        if ($request->has('governorate')) {
            $query->where('governorate', $request->governorate);
        }

        $zones = $query->get();

        // Group by governorate for easier dropdown rendering
        $grouped = $zones->groupBy('governorate')->map(function ($group, $governorate) {
            return [
                'governorate' => $governorate,
                'zones'       => $group->map(fn($z) => [
                    'id'        => $z->id,
                    'name'      => $z->name,
                    'is_active' => $z->is_active,
                ]),
            ];
        })->values();

        return response()->json([
            'message' => 'Zones retrieved successfully.',
            'data'    => [
                'total'  => $zones->count(),
                'grouped' => $grouped,
            ],
        ], 200);
    }

    // =========================================================
    // GET /api/v1/admin/zones/{id}
    // Middleware: auth:sanctum + active.user + role:admin
    // Returns one zone with stats
    // =========================================================
    public function show(int $id): JsonResponse
    {
        $zone = Zone::withCount([
            'pharmacyBranches',
            'supplierZones',
        ])->find($id);

        if (! $zone) {
            return response()->json(['message' => 'Zone not found.'], 404);
        }

        return response()->json([
            'message' => 'Zone retrieved successfully.',
            'data'    => [
                'id'               => $zone->id,
                'name'             => $zone->name,
                'governorate'      => $zone->governorate,
                'is_active'        => $zone->is_active,
                'pharmacy_branches_count' => $zone->pharmacy_branches_count,
                'suppliers_count'         => $zone->supplier_zones_count,
                'created_at'       => $zone->created_at,
            ],
        ], 200);
    }

    // =========================================================
    // POST /api/v1/admin/zones
    // Middleware: auth:sanctum + active.user + role:admin
    // Creates a new zone
    // =========================================================
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'        => ['required', 'string', 'min:3', 'max:100', 'unique:zones,name'],
            'governorate' => ['required', 'string', 'min:3', 'max:100'],
        ], [
            'name.unique' => 'A zone with this name already exists.',
        ]);

        $zone = Zone::create([
            'name'        => $request->name,
            'governorate' => $request->governorate,
            'is_active'   => 1,
        ]);

        return response()->json([
            'message' => 'Zone created successfully.',
            'data'    => $zone,
        ], 201);
    }

    // =========================================================
    // PUT /api/v1/admin/zones/{id}
    // Middleware: auth:sanctum + active.user + role:admin
    // Updates zone name or governorate
    // =========================================================
    public function update(Request $request, int $id): JsonResponse
    {
        $zone = Zone::find($id);

        if (! $zone) {
            return response()->json(['message' => 'Zone not found.'], 404);
        }

        $request->validate([
            'name'        => ['sometimes', 'string', 'min:3', 'max:100', 'unique:zones,name,' . $id],
            'governorate' => ['sometimes', 'string', 'min:3', 'max:100'],
        ], [
            'name.unique' => 'A zone with this name already exists.',
        ]);

        $zone->update($request->only(['name', 'governorate']));

        return response()->json([
            'message' => 'Zone updated successfully.',
            'data'    => $zone->fresh(),
        ], 200);
    }

    // =========================================================
    // PATCH /api/v1/admin/zones/{id}/toggle
    // Middleware: auth:sanctum + active.user + role:admin
    //
    // Activates or deactivates a zone.
    // Deactivating blocks new registrations and allocations
    // for this zone but does NOT affect existing active branches.
    // =========================================================
    public function toggle(int $id): JsonResponse
    {
        $zone = Zone::withCount('pharmacyBranches')->find($id);

        if (! $zone) {
            return response()->json(['message' => 'Zone not found.'], 404);
        }

        $newStatus = ! $zone->is_active;

        // Warn admin if deactivating a zone that has active branches
        $warning = null;
        if (! $newStatus && $zone->pharmacy_branches_count > 0) {
            $warning = "This zone has {$zone->pharmacy_branches_count} active branch(es). Deactivating will prevent new allocations for those branches.";
        }

        $zone->update(['is_active' => $newStatus]);

        return response()->json([
            'message' => 'Zone ' . ($newStatus ? 'activated' : 'deactivated') . ' successfully.',
            'data'    => [
                'id'        => $zone->id,
                'name'      => $zone->name,
                'is_active' => $newStatus,
                'warning'   => $warning,
            ],
        ], 200);
    }

    // =========================================================
    // DELETE /api/v1/admin/zones/{id}
    // Middleware: auth:sanctum + active.user + role:admin
    //
    // Hard delete — only allowed if zone has no branches or
    // suppliers assigned to it.
    // =========================================================
    public function destroy(int $id): JsonResponse
    {
        $zone = Zone::withCount([
            'pharmacyBranches',
            'supplierZones',
        ])->find($id);

        if (! $zone) {
            return response()->json(['message' => 'Zone not found.'], 404);
        }

        // Block delete if zone is in use
        if ($zone->pharmacy_branches_count > 0 || $zone->supplier_zones_count > 0) {
            return response()->json([
                'message' => 'Cannot delete this zone. It has active branches or suppliers assigned to it.',
                'data'    => [
                    'pharmacy_branches' => $zone->pharmacy_branches_count,
                    'suppliers'         => $zone->supplier_zones_count,
                ],
            ], 422);
        }

        $zone->delete();

        return response()->json([
            'message' => 'Zone deleted successfully.',
        ], 200);
    }
}
