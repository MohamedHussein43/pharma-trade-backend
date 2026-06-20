<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Drug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DrugController extends Controller
{
    // =========================================================
    // GET /api/v1/drugs
    // PUBLIC — needed for pharmacy order creation search
    //
    // Supports:
    //   ?search=amoxil        → FULLTEXT search
    //   ?dosage_form=Tablet   → filter by form
    //   ?per_page=20          → pagination
    // =========================================================
    public function index(Request $request): JsonResponse
    {
        $query = Drug::where('is_active', 1);

        if ($request->filled('search')) {
            $term = $request->search;
            // FULLTEXT search first, fallback to LIKE for short terms
            if (strlen($term) >= 3) {
                $query->whereRaw(
                    "MATCH(name, trade_name, scientific_name) AGAINST(? IN BOOLEAN MODE)",
                    [$term . '*']
                );
            } else {
                $query->where(function ($q) use ($term) {
                    $q->where('name',            'LIKE', "%{$term}%")
                      ->orWhere('trade_name',    'LIKE', "%{$term}%")
                      ->orWhere('scientific_name','LIKE', "%{$term}%");
                });
            }
        }

        if ($request->filled('dosage_form')) {
            $query->where('dosage_form', $request->dosage_form);
        }

        if ($request->filled('manufacturer')) {
            $query->where('manufacturer', 'LIKE', "%{$request->manufacturer}%");
        }

        $drugs = $query->select([
            'id','name','trade_name','scientific_name',
            'dosage_form','strength','manufacturer','barcode',
        ])
        ->orderBy('trade_name')
        ->paginate($request->get('per_page', 20));

        return response()->json([
            'message' => 'Drugs retrieved successfully.',
            'data'    => $drugs,
        ], 200);
    }

    // =========================================================
    // GET /api/v1/drugs/{id}
    // PUBLIC — full detail of one drug
    // =========================================================
    public function show(int $id): JsonResponse
    {
        $drug = Drug::find($id);

        if (! $drug) {
            return response()->json(['message' => 'Drug not found.'], 404);
        }

        return response()->json([
            'message' => 'Drug retrieved successfully.',
            'data'    => $drug,
        ], 200);
    }

    // =========================================================
    // POST /api/v1/admin/drugs
    // Middleware: auth:sanctum + active.user + role:admin
    // Admin creates a new drug in the master catalog
    // =========================================================
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'            => ['required', 'string', 'max:255'],
            'trade_name'      => ['nullable', 'string', 'max:255'],
            'scientific_name' => ['nullable', 'string', 'max:255'],
            'manufacturer'    => ['nullable', 'string', 'max:255'],
            'dosage_form'     => ['nullable', 'string', 'max:100'],
            'strength'        => ['nullable', 'string', 'max:100'],
            'barcode'         => ['nullable', 'string', 'max:100', 'unique:drugs,barcode'],
        ], [
            'name.required'    => 'Drug name is required.',
            'barcode.unique'   => 'This barcode already exists in the catalog.',
        ]);

        $drug = Drug::create([
            'name'            => $request->name,
            'trade_name'      => $request->trade_name,
            'scientific_name' => $request->scientific_name,
            'manufacturer'    => $request->manufacturer,
            'dosage_form'     => $request->dosage_form,
            'strength'        => $request->strength,
            'barcode'         => $request->barcode,
            'is_active'       => 1,
        ]);

        return response()->json([
            'message' => 'Drug added to catalog successfully.',
            'data'    => $drug,
        ], 201);
    }

    // =========================================================
    // PUT /api/v1/admin/drugs/{id}
    // Middleware: auth:sanctum + active.user + role:admin
    // Update drug details
    // =========================================================
    public function update(Request $request, int $id): JsonResponse
    {
        $drug = Drug::find($id);

        if (! $drug) {
            return response()->json(['message' => 'Drug not found.'], 404);
        }

        $request->validate([
            'name'            => ['sometimes', 'string', 'max:255'],
            'trade_name'      => ['nullable', 'string', 'max:255'],
            'scientific_name' => ['nullable', 'string', 'max:255'],
            'manufacturer'    => ['nullable', 'string', 'max:255'],
            'dosage_form'     => ['nullable', 'string', 'max:100'],
            'strength'        => ['nullable', 'string', 'max:100'],
            'barcode'         => ['nullable', 'string', 'max:100', 'unique:drugs,barcode,' . $id],
        ]);

        $drug->update($request->only([
            'name', 'trade_name', 'scientific_name',
            'manufacturer', 'dosage_form', 'strength', 'barcode',
        ]));

        return response()->json([
            'message' => 'Drug updated successfully.',
            'data'    => $drug->fresh(),
        ], 200);
    }

    // =========================================================
    // PATCH /api/v1/admin/drugs/{id}/toggle
    // Activates or deactivates a drug in the catalog.
    // Deactivated drugs are hidden from search and new orders.
    // Existing order items referencing this drug are unaffected.
    // =========================================================
    public function toggle(int $id): JsonResponse
    {
        $drug = Drug::find($id);

        if (! $drug) {
            return response()->json(['message' => 'Drug not found.'], 404);
        }

        $drug->update(['is_active' => ! $drug->is_active]);

        return response()->json([
            'message' => 'Drug ' . ($drug->is_active ? 'activated' : 'deactivated') . ' successfully.',
            'data'    => [
                'id'        => $drug->id,
                'name'      => $drug->trade_name ?? $drug->name,
                'is_active' => $drug->is_active,
            ],
        ], 200);
    }

    // =========================================================
    // GET /api/v1/admin/drugs/dosage-forms
    // Returns distinct dosage forms for filter dropdowns
    // =========================================================
    public function dosageForms(): JsonResponse
    {
        $forms = Drug::where('is_active', 1)
            ->whereNotNull('dosage_form')
            ->distinct()
            ->pluck('dosage_form')
            ->sort()
            ->values();

        return response()->json([
            'message' => 'Dosage forms retrieved successfully.',
            'data'    => $forms,
        ], 200);
    }
}
