<?php

namespace App\Http\Controllers\Api\Admin;
use Illuminate\Support\Facades\URL;
use App\Http\Controllers\Controller;
use App\Models\LicenceImage;
use App\Models\MasterOrder;
use App\Models\Notification;
use App\Models\Pharmacy;
use App\Models\PharmacyBranch;
use App\Models\RegistrationRequest;
use App\Models\Supplier;
use App\Models\SupplierZone;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;


use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterPharmacyRequest;
use App\Http\Requests\RegisterSupplierRequest;

class AdminRegistrationController extends Controller
{
    // =========================================================
    // GET /api/v1/admin/registration-requests
    // Middleware: auth:sanctum + active.user + role:admin
    //
    // Returns paginated list of registration requests.
    // Supports filtering by status and entity_type.
    // Includes licence image signed URLs for admin review.
    // =========================================================
    public function index(Request $request): JsonResponse
    {
        $query = RegistrationRequest::with([
            'user:id,name,email,phone,role,status',
            'zone:id,name,governorate',
            'reviewer:id,name',
            'licenceImages',
        ])
        ->orderBy('created_at', 'desc');

        // Filter by status (default: pending)
        if ($request->has('status')) {
            $query->where('status', $request->status);
        } else {
            $query->where('status', 'pending');
        }

        // Filter by entity type
        if ($request->has('entity_type')) {
            $query->where('entity_type', $request->entity_type);
        }

        $requests = $query->paginate($request->get('per_page', 15));

        // Transform to add signed image URLs
        $requests->getCollection()->transform(function ($req) {
            return $this->formatRequest($req);
        });

        return response()->json([
            'message' => 'Registration requests retrieved successfully.',
            'data'    => $requests,
        ], 200);
    }

    // =========================================================
    // GET /api/v1/admin/registration-requests/{id}
    // Middleware: auth:sanctum + active.user + role:admin
    //
    // Returns full detail of one registration request.
    // =========================================================
    public function show(int $id): JsonResponse
    {
        $regRequest = RegistrationRequest::with([
            'user:id,name,email,phone,role,status,created_at',
            'zone:id,name,governorate',
            'reviewer:id,name,email',
            'licenceImages',
        ])->find($id);

        if (! $regRequest) {
            return response()->json([
                'message' => 'Registration request not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Registration request retrieved successfully.',
            'data'    => $this->formatRequest($regRequest),
        ], 200);
    }

    // =========================================================
    // POST /api/v1/admin/registration-requests/{id}/approve
    // Middleware: auth:sanctum + active.user + role:admin
    //
    // Approves a registration request.
    // Everything runs inside ONE DB transaction:
    //   1. Validate request is still pending
    //   2. Create the entity (pharmacy / branch / supplier)
    //   3. Activate the user account
    //   4. Update the registration_request row
    //   5. Notify the applicant
    // If anything fails → full rollback, nothing is created.
    // =========================================================
    public function approve(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $regRequest = RegistrationRequest::with('user')->find($id);

        if (! $regRequest) {
            return response()->json(['message' => 'Registration request not found.'], 404);
        }

        if ($regRequest->status !== 'pending') {
            return response()->json([
                'message' => "This request has already been {$regRequest->status}.",
            ], 422);
        }

        try {
            $result = DB::transaction(function () use ($regRequest, $request) {

                $entityId = null;

                // ── Create the correct entity based on type ──
                switch ($regRequest->entity_type) {

                    case 'pharmacy':
                        $entityId = $this->createPharmacy($regRequest, $request->user());
                        break;

                    case 'pharmacy_branch':
                        $entityId = $this->createPharmacyBranch($regRequest, $request->user());
                        break;

                    case 'supplier':
                        $entityId = $this->createSupplier($regRequest, $request->user());
                        break;
                    case 'zone_update':
                        $entityId = $this->applyZoneUpdate($regRequest, $request->user());
                        break;

                    case 'licence_update':
                        $entityId = $this->applyLicenceUpdate($regRequest, $request->user());
                        break;
                }

                // ── Activate the user account ──
                $regRequest->user->update([
                    'status'    => 'active',
                    'is_active' => 1,
                ]);

                // ── Mark request as approved ──
                $regRequest->update([
                    'status'      => 'approved',
                    'reviewed_by' => $request->user()->id,
                    'reviewed_at' => now(),
                    'entity_id'   => $entityId,
                ]);

                // ── Notify the applicant ──
                /*Notification::create([
                    'user_id'         => $regRequest->user_id,
                    'title'           => 'Your account has been approved!',
                    'body'            => "Welcome to the platform! Your {$regRequest->entity_type} account for '{$regRequest->business_name}' has been approved. You can now log in.",
                    'type'            => 'registration_approved',
                    'channel'         => 'push',
                    'notifiable_type' => 'RegistrationRequest',
                    'notifiable_id'   => $regRequest->id,
                    'is_read'         => 0,
                ]);*/
                $notificationService = app(\App\Services\NotificationService::class);
                $notificationService->registrationApproved($user->id, $req->entity_type);

                return $entityId;
            });

            return response()->json([
                'message' => 'Registration approved successfully.',
                'data'    => [
                    'request_id'  => $regRequest->id,
                    'entity_type' => $regRequest->entity_type,
                    'entity_id'   => $result,
                    'approved_by' => $request->user()->name,
                    'approved_at' => now(),
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Approval failed. Please try again.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // =========================================================
    // POST /api/v1/admin/registration-requests/{id}/decline
    // Middleware: auth:sanctum + active.user + role:admin
    //
    // Declines a registration request.
    // decline_reason is required — sent to applicant.
    // User account stays in DB with status = suspended.
    // No entity row is created.
    // =========================================================
    public function decline(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'decline_reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $regRequest = RegistrationRequest::with('user')->find($id);

        if (! $regRequest) {
            return response()->json(['message' => 'Registration request not found.'], 404);
        }

        if ($regRequest->status !== 'pending') {
            return response()->json([
                'message' => "This request has already been {$regRequest->status}.",
            ], 422);
        }

        DB::transaction(function () use ($regRequest, $request) {

            // ── Mark request as declined ──
            $regRequest->update([
                'status'         => 'declined',
                'reviewed_by'    => $request->user()->id,
                'reviewed_at'    => now(),
                'decline_reason' => $request->decline_reason,
            ]);

            // ── Suspend the user (keeps account, blocks login) ──
            $regRequest->user->update([
                'status'    => 'suspended',
                'is_active' => 0,
            ]);

            // ── Notify the applicant with reason ──
            $notificationService = app(\App\Services\NotificationService::class);
            $notificationService->registrationDeclined($user->id, $request->decline_reason);
            /*Notification::create([
                'user_id'         => $regRequest->user_id,
                'title'           => 'Registration request declined',
                'body'            => "Your registration for '{$regRequest->business_name}' was declined. Reason: {$request->decline_reason}",
                'type'            => 'registration_declined',
                'channel'         => 'push',
                'notifiable_type' => 'RegistrationRequest',
                'notifiable_id'   => $regRequest->id,
                'is_read'         => 0,
            ]);*/
        });

        return response()->json([
            'message' => 'Registration declined successfully.',
            'data'    => [
                'request_id'     => $regRequest->id,
                'status'         => 'declined',
                'decline_reason' => $request->decline_reason,
                'declined_by'    => $request->user()->name,
                'declined_at'    => now(),
            ],
        ], 200);
    }

    // =========================================================
    // GET /api/v1/admin/registration-requests/stats
    // Quick count summary for the admin dashboard badge
    // =========================================================
    public function stats(): JsonResponse
    {
        $stats = RegistrationRequest::selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending'  THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'declined' THEN 1 ELSE 0 END) as declined,
            SUM(CASE WHEN entity_type in ('pharmacy', 'pharmacy_branch')        AND status = 'pending' THEN 1 ELSE 0 END) as pending_pharmacies,
            SUM(CASE WHEN entity_type = 'supplier'        AND status = 'pending' THEN 1 ELSE 0 END) as pending_suppliers
            ")->first();
           // SUM(CASE WHEN entity_type = 'pharmacy_branch' AND status = 'pending' THEN 1 ELSE 0 END) as pending_branches,
            
        return response()->json([
            'message' => 'Stats retrieved successfully.',
            'data'    => $stats,
        ], 200);
    }

    // =========================================================
    // PRIVATE — Create entity rows on approval
    // =========================================================

    private function createPharmacy(RegistrationRequest $req, User $admin): int
    {
        // Create the pharmacy organisation
        $pharmacy = Pharmacy::create([
            'name'            => $req->business_name,
            'licence_number'  => $req->licence_number,
            'approval_status' => 'approved',
            'reviewed_by'     => $admin->id,
            'reviewed_at'     => now(),
            'is_active'       => 1,
        ]);

        // Get all zone IDs from meta, fallback to primary zone_id
        $meta    = is_string($req->meta) ? json_decode($req->meta, true) : ($req->meta ?? []);
        $zoneIds = $meta['zone_ids'] ?? ($req->zone_id ? [$req->zone_id] : []);

        // Create the main branch WITHOUT zone_id column
        $branch = PharmacyBranch::create([
            'pharmacy_id'     => $pharmacy->id,
            'user_id'         => $req->user_id,
            'name'            => $req->business_name . ' — Main Branch',
            'licence_number'  => $req->licence_number,
            'address'         => $req->address,
            'phone'           => $req->phone,
            'approval_status' => 'approved',
            'reviewed_by'     => $admin->id,
            'reviewed_at'     => now(),
            'is_active'       => 1,
        ]);

        // Create one pharmacy_branch_zones row per zone
        foreach ($zoneIds as $zoneId) {
            \App\Models\PharmacyBranchZone::create([
                'branch_id'  => $branch->id,
                'zone_id'    => (int)$zoneId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $pharmacy->id;
    }

    private function createPharmacyBranch(RegistrationRequest $req, User $admin): int
    {
        $meta    = is_string($req->meta) ? json_decode($req->meta, true) : ($req->meta ?? []);
        $zoneIds = $meta['zone_ids'] ?? ($req->zone_id ? [$req->zone_id] : []);

        // Create branch WITHOUT zone_id column
        $branch = PharmacyBranch::create([
            'pharmacy_id'     => $req->pharmacy_id,
            'user_id'         => $req->user_id,
            'name'            => $req->business_name,
            'licence_number'  => $req->licence_number,
            'address'         => $req->address,
            'phone'           => $req->phone,
            'approval_status' => 'approved',
            'reviewed_by'     => $admin->id,
            'reviewed_at'     => now(),
            'is_active'       => 1,
        ]);

        // Create zone pivot rows
        foreach ($zoneIds as $zoneId) {
            \App\Models\PharmacyBranchZone::create([
                'branch_id'  => $branch->id,
                'zone_id'    => (int)$zoneId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $branch->id;
    }

    private function createSupplier(RegistrationRequest $req, User $admin): int
    {
        // Read min_order values from meta JSON
        // Decode meta — handle both array and JSON string cases
        $meta = $req->meta;

        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }

        $meta = $meta ?? [];
        $zoneIds = $meta['zone_ids'] ?? [];

        // Final fallback — use the single zone_id column
        if (empty($zoneIds) && $req->zone_id) {
            $zoneIds = [$req->zone_id];
        }

        $supplier = Supplier::create([
            'user_id'         => $req->user_id,
            'name'            => $req->business_name,
            'licence_number'  => $req->licence_number,
            'min_order_value' => $meta['min_order_value'] ?? 0,
            'min_order_qty'   => $meta['min_order_qty']   ?? 0,
            'approval_status' => 'approved',
            'reviewed_by'     => $admin->id,
            'reviewed_at'     => now(),
            'is_active'       => 1,
        ]);

        // Create supplier_zones rows for ALL zones from meta
        //$zoneIds = $meta['zone_ids'] ?? [$req->zone_id];
        foreach ($zoneIds as $zoneId) {
            SupplierZone::create([
                'supplier_id' => $supplier->id,
                'zone_id'     => $zoneId,
            ]);
        }

        return $supplier->id;
    }

    // =========================================================
    // PRIVATE — Format request for response
    // Adds signed URLs for licence images
    // =========================================================
    private function formatRequest(RegistrationRequest $req): array
    {
        $images = $req->licenceImages->map(function ($img) {
            return [
                'id'         => $img->id,
                'file_name'  => $img->file_name,
                'mime_type'  => $img->mime_type,
                'is_primary' => $img->is_primary,
                'size_kb'    => $img->file_size_kb,
                // Signed URL valid for 60 minutes — never expose raw file_path
                'url'        => Storage::disk('private')->exists($img->file_path)
                                ? URL::temporarySignedRoute(
                                    'admin.licence.view',
                                    now()->addMinutes(60),
                                    ['image' => $img->id]
                                )
                                : null,
            ];
        });

        return [
            'id'             => $req->id,
            'entity_type'    => $req->entity_type,
            'business_name'  => $req->business_name,
            'applicant_name' => $req->applicant_name,
            'licence_number' => $req->licence_number,
            'phone'          => $req->phone,
            'address'        => $req->address,
            'zone'           => $req->zone,
            'meta'           => $req->meta,
            'status'         => $req->status,
            'decline_reason' => $req->decline_reason,
            'submitted_at'   => $req->created_at,
            'reviewed_at'    => $req->reviewed_at,
            'reviewer'       => $req->reviewer,
            'applicant'      => $req->user,
            'licence_images' => $images,
        ];
    }

        private function applyZoneUpdate(RegistrationRequest $req, User $admin): int
    {
        $meta       = is_string($req->meta) ? json_decode($req->meta, true) : ($req->meta ?? []);
        $zoneIds    = $meta['zone_ids']    ?? [];
        $entityType = $meta['entity_type'] ?? null;
        $entityId   = $meta['entity_id']   ?? null;

        if (empty($zoneIds) || ! $entityId) {
            throw new \Exception('Zone update request is missing zone_ids or entity_id.');
        }

        if ($entityType === 'pharmacy') {
            // Delete all existing zones for this branch
            \App\Models\PharmacyBranchZone::where('branch_id', $entityId)->delete();

            // Insert new zones
            foreach ($zoneIds as $zoneId) {
                \App\Models\PharmacyBranchZone::create([
                    'branch_id'  => $entityId,
                    'zone_id'    => (int)$zoneId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if ($entityType === 'supplier') {
            // Delete all existing supplier zones
            \App\Models\SupplierZone::where('supplier_id', $entityId)->delete();

            // Insert new zones
            foreach ($zoneIds as $zoneId) {
                \App\Models\SupplierZone::create([
                    'supplier_id' => $entityId,
                    'zone_id'     => (int)$zoneId,
                ]);
            }
        }

        return $entityId;
    }

    // =========================================================
    // Apply approved licence update
    // Updates the licence_number on the entity row
    // =========================================================
    private function applyLicenceUpdate(RegistrationRequest $req, User $admin): int
    {
        $meta       = is_string($req->meta) ? json_decode($req->meta, true) : ($req->meta ?? []);
        $entityType = $meta['entity_type'] ?? null;
        $entityId   = $meta['entity_id']   ?? null;
        $newLicence = $meta['new_licence']  ?? $req->licence_number;

        if (! $entityId || ! $newLicence) {
            throw new \Exception('Licence update request is missing entity_id or new licence number.');
        }

        if ($entityType === 'pharmacy') {
            \App\Models\PharmacyBranch::where('id', $entityId)
                ->update(['licence_number' => $newLicence]);
        }

        if ($entityType === 'supplier') {
            \App\Models\Supplier::where('id', $entityId)
                ->update(['licence_number' => $newLicence]);
        }

        return $entityId;
    }

}
