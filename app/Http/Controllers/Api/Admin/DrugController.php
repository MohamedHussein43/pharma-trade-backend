<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Drug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\DrugTransliterator;
use App\Services\ArabicNormalizer;

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
            $isArabic = preg_match('/\p{Arabic}/u', $term);

            if (!$isArabic && strlen($term) >= 3) {
                $query->whereRaw(
                    "MATCH(name, trade_name, scientific_name) AGAINST(? IN BOOLEAN MODE)",
                    [$term . '*']
                );
            } else {
                $query->where(function ($q) use ($term) {
                    $q->where('name',               'LIKE', "%{$term}%")
                    ->orWhere('trade_name',        'LIKE', "%{$term}%")
                    ->orWhere('scientific_name',   'LIKE', "%{$term}%")
                    ->orWhere('commercial_name_ar','LIKE', "%{$term}%");
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
            'id','name','trade_name','commercial_name_ar',
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
            'name'               => ['required', 'string', 'max:255'],
            'trade_name'         => ['nullable', 'string', 'max:255'],
            'commercial_name_ar' => ['nullable', 'string', 'max:255'],
            'scientific_name'    => ['nullable', 'string'],
            'manufacturer'       => ['nullable', 'string', 'max:255'],
            'dosage_form'        => ['nullable', 'string', 'max:100'],
            'strength'           => ['nullable', 'string', 'max:100'],
            'barcode'            => ['nullable', 'string', 'max:100', 'unique:drugs,barcode'],
        ], [
            'name.required'  => 'Drug name is required.',
            'barcode.unique' => 'This barcode already exists in the catalog.',
        ]);

        $inputName = trim($request->name);
        $isArabic  = (bool) preg_match('/\p{Arabic}/u', $inputName);

        if ($isArabic) {
            // Arabic input — fill English side
            $commercialNameAr = ArabicNormalizer::normalize(
                      $request->commercial_name_ar ?? $inputName
                  );
            $tradeName        = $request->trade_name
                ?? DrugTransliterator::arabicToEnglish($inputName)['value'];
            $name             = $tradeName; // internal `name` stores English display name
        } else {
            // English input — fill Arabic side
            $tradeName        = $request->trade_name ?? $inputName;
            $commercialNameAr = $request->commercial_name_ar
                ?? DrugTransliterator::englishToArabic($inputName)['value'];
            $name             = $inputName;
        }

        $drug = Drug::create([
            'name'               => $name,
            'trade_name'         => $tradeName,
            'commercial_name_ar' => $commercialNameAr,
            'scientific_name'    => $request->scientific_name,
            'manufacturer'       => $request->manufacturer,
            'dosage_form'        => $request->dosage_form,
            'strength'           => $request->strength,
            'barcode'            => $request->barcode,
            'is_active'          => 1,
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
            'name'               => ['sometimes', 'string', 'max:255'],
            'trade_name'         => ['nullable',  'string', 'max:255'],
            'commercial_name_ar' => ['nullable',  'string', 'max:255'],   // ← was missing
            'scientific_name'    => ['nullable',  'string', 'max:255'],
            'manufacturer'       => ['nullable',  'string', 'max:255'],
            'dosage_form'        => ['nullable',  'string', 'max:100'],
            'strength'           => ['nullable',  'string', 'max:100'],
            'barcode'            => ['nullable',  'string', 'max:100', 'unique:drugs,barcode,' . $id],
        ]);

        // ── Build the update payload ───────────────────────────
        $data = $request->only([
            'trade_name', 'scientific_name',
            'manufacturer', 'dosage_form', 'strength', 'barcode',
        ]);

        // Handle name field with language detection
        if ($request->filled('name')) {
            $inputName = trim($request->name);
            $isArabic  = ArabicNormalizer::isArabic($inputName);

            if ($isArabic) {
                // Arabic passed as "name" → route to commercial_name_ar,
                // auto-fill trade_name if not also provided
                $data['commercial_name_ar'] = ArabicNormalizer::normalize($inputName);
                if (! $request->filled('trade_name') && empty($drug->trade_name)) {
                    $data['trade_name'] = DrugTransliterator::arabicToEnglish($inputName)['value'];
                    $data['name']       = $data['trade_name'];
                }
                // If trade_name already exists on the record, keep name in sync with it
                if (! isset($data['name'])) {
                    $data['name'] = $request->filled('trade_name')
                        ? trim($request->trade_name)
                        : $drug->name;
                }
            } else {
                // English name — update name + trade_name together
                $data['name']       = $inputName;
                $data['trade_name'] = $request->filled('trade_name')
                    ? trim($request->trade_name)
                    : $inputName;
                // Auto-fill Arabic if still missing and not provided
                if (! $request->filled('commercial_name_ar') && empty($drug->commercial_name_ar)) {
                    $data['commercial_name_ar'] = DrugTransliterator::englishToArabic($inputName)['value'];
                }
            }
        }

        // Normalize commercial_name_ar if explicitly provided in the request
        if ($request->filled('commercial_name_ar')) {
            $data['commercial_name_ar'] = ArabicNormalizer::normalize(
                trim($request->commercial_name_ar)
            );
        }

        // Normalize trade_name: if Arabic somehow slipped in, clear it out
        if (isset($data['trade_name']) && ArabicNormalizer::isArabic($data['trade_name'])) {
            // Arabic in trade_name field → move it to commercial_name_ar
            $data['commercial_name_ar'] = ArabicNormalizer::normalize($data['trade_name']);
            $data['trade_name']         = $drug->trade_name; // keep existing English
            unset($data['name']);                            // don't overwrite name either
        }

        $drug->update($data);

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

    public function missingEnglish(Request $request): JsonResponse
    {
        $drugs = Drug::where(fn ($q) => $q->whereNull('trade_name')->orWhere('trade_name', ''))
            ->whereNotNull('commercial_name_ar')
            ->orderByDesc('id')
            ->paginate((int) $request->get('per_page', 20), ['id', 'commercial_name_ar', 'created_at']);

        return response()->json([
            'message' => 'Drugs missing English names.',
            'data'    => $drugs,
        ]);
    }
}
