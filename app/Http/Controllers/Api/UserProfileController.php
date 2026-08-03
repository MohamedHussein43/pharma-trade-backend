<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class UserProfileController extends Controller
{
    // =========================================================
    // GET /api/v1/profile
    // Middleware: auth:sanctum + active.user
    //
    // Returns the authenticated user's profile.
    // Includes branch or supplier details based on role.
    // =========================================================
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = [
            'id'            => $user->id,
            'name'          => $user->name,
            'email'         => $user->email,
            'phone'         => $user->phone,
            'role'          => $user->role,
            'status'        => $user->status,
            'last_login_at' => $user->last_login_at,
            'created_at'    => $user->created_at,
        ];

        // Attach role-specific profile details
        if ($user->role === 'pharmacy') {
            $branch = $user->pharmacyBranch()->with([
                'pharmacy:id,name,licence_number',
                'zones:id,name,governorate',
            ])->first();

            $data['branch'] = $branch ? [
                'id'             => $branch->id,
                'name'           => $branch->name,
                'address'        => $branch->address,
                'phone'          => $branch->phone,
                'licence_number' => $branch->licence_number,
                'zones'          => $branch->zones,
                'pharmacy'       => $branch->pharmacy,
            ] : null;
        }

        if ($user->role === 'supplier') {
            $supplier = $user->supplier()->with('zones:id,name,governorate')->first();

            $data['supplier'] = $supplier ? [
                'id'              => $supplier->id,
                'name'            => $supplier->name,
                'licence_number'  => $supplier->licence_number,
                'min_order_value' => $supplier->min_order_value,
                'min_order_qty'   => $supplier->min_order_qty,
                'zones'           => $supplier->zones,
            ] : null;
        }

        return response()->json([
            'message' => 'Profile retrieved successfully.',
            'data'    => $data,
        ], 200);
    }

    // =========================================================
    // PUT /api/v1/profile
    // Middleware: auth:sanctum + active.user
    //
    // Updates the authenticated user's personal info.
    // Only updates what is sent — all fields optional.
    // Does NOT allow changing role, status, or email.
    // =========================================================
      public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'name'     => ['sometimes', 'string', 'min:3', 'max:150'],
            'phone'    => [
                'sometimes',
                'string',
                'regex:/^(\+20|0)[0-9]{10}$/',
                Rule::unique('users', 'phone')->ignore($user->id),
            ],
            'email'    => [
                'sometimes',
                'email',
                'max:191',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'password' => ['required', 'string'],  // ← always required now
        ], [
            'password.required'  => 'Please confirm your password to update your profile.',
            'phone.unique'       => 'This phone number is already used by another account.',
            'phone.regex'        => 'Phone must be a valid Egyptian number (e.g. 01012345678).',
            'email.unique'       => 'This email address is already registered to another account.',
        ]);

        // Verify password first before any changes
        if (! Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Password is incorrect.',
                'errors'  => [
                    'password' => ['The password you entered is incorrect.'],
                ],
            ], 422);
        }

        $updateData   = [];
        $emailChanged = false;

        if ($request->filled('name')) {
            $updateData['name'] = $request->name;
        }

        if ($request->filled('phone')) {
            $updateData['phone'] = $request->phone;
        }

        if ($request->filled('email')) {
            if (strtolower($request->email) === strtolower($user->email)) {
                return response()->json([
                    'message' => 'New email is the same as your current email.',
                    'errors'  => [
                        'email' => ['Please enter a different email address.'],
                    ],
                ], 422);
            }

            $updateData['email'] = $request->email;
            $emailChanged        = true;
        }

        if (empty($updateData)) {
            return response()->json(['message' => 'No changes provided.'], 422);
        }

        $user->update($updateData);

        // Email changed → revoke all tokens → force re-login
        if ($emailChanged) {
            $user->tokens()->delete();

            return response()->json([
                'message' => 'Profile updated successfully. Your email has changed — please log in again.',
                'data'    => [
                    'id'             => $user->id,
                    'name'           => $user->name,
                    'email'          => $user->email,
                    'phone'          => $user->phone,
                    'email_changed'  => true,
                    'tokens_revoked' => true,
                ],
            ], 200);
        }

        return response()->json([
            'message' => 'Profile updated successfully.',
            'data'    => [
                'id'            => $user->id,
                'name'          => $user->name,
                'email'         => $user->email,
                'phone'         => $user->phone,
                'email_changed' => false,
            ],
        ], 200);
    }


    // =========================================================
    // PATCH /api/v1/profile/password
    // Middleware: auth:sanctum + active.user
    //
    // Changes the user's password.
    // Requires current password for verification.
    // Invalidates ALL existing tokens after change.
    // =========================================================
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'password.confirmed' => 'New password confirmation does not match.',
            'password.min'       => 'New password must be at least 8 characters.',
        ]);

        // Verify current password
        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect.',
                'errors'  => [
                    'current_password' => ['The password you entered is incorrect.'],
                ],
            ], 422);
        }

        // Prevent reusing the same password
        if (Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'New password must be different from your current password.',
                'errors'  => [
                    'password' => ['New password cannot be the same as the current password.'],
                ],
            ], 422);
        }

        // Update password and revoke all tokens (force re-login on all devices)
        $user->update(['password' => Hash::make($request->password)]);
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Password changed successfully. Please log in again.',
        ], 200);
    }

    // =========================================================
    // PATCH /api/v1/profile/device-token
    // Middleware: auth:sanctum + active.user
    //
    // Updates the FCM device token for push notifications.
    // Called by Flutter after login or when token refreshes.
    // =========================================================
    public function updateDeviceToken(Request $request): JsonResponse
    {
        $request->validate([
            'device_token' => ['required', 'string', 'max:255'],
        ]);

        $request->user()->update([
            'device_token' => $request->device_token,
        ]);

        return response()->json([
            'message' => 'Device token updated successfully.',
        ], 200);
    }

    // =========================================================
    // PATCH /api/v1/profile/branch
    // Middleware: auth:sanctum + active.user + role:pharmacy
    //
    // Pharmacy user updates their branch details.
    // Cannot change zones — that requires admin approval.
    // =========================================================
    public function updateBranch(Request $request): JsonResponse
    {
        $user   = $request->user();
        $branch = $user->pharmacyBranch;

        if (! $branch) {
            return response()->json(['message' => 'Branch not found.'], 404);
        }

        $request->validate([
            'name'    => ['sometimes', 'string', 'min:3', 'max:200'],
            'address' => ['sometimes', 'string', 'min:10', 'max:500'],
            'phone'   => ['sometimes', 'string', 'regex:/^(\+20|0)[0-9]{10}$/'],
        ], [
            'address.min' => 'Address must be at least 10 characters.',
            'phone.regex' => 'Phone must be a valid Egyptian number.',
        ]);

        $updateData = [];

        if ($request->filled('name'))    $updateData['name']    = $request->name;
        if ($request->filled('address')) $updateData['address'] = $request->address;
        if ($request->filled('phone'))   $updateData['phone']   = $request->phone;

        if (empty($updateData)) {
            return response()->json(['message' => 'No changes provided.'], 422);
        }

        $branch->update($updateData);

        return response()->json([
            'message' => 'Branch details updated successfully.',
            'data'    => [
                'id'      => $branch->id,
                'name'    => $branch->name,
                'address' => $branch->address,
                'phone'   => $branch->phone,
            ],
        ], 200);
    }

    // =========================================================
    // PATCH /api/v1/profile/supplier
    // Middleware: auth:sanctum + active.user + role:supplier
    //
    // Supplier updates their business details.
    // Cannot change zones or licence — that requires admin.
    // =========================================================
    public function updateSupplier(Request $request): JsonResponse
    {
        $user     = $request->user();
        $supplier = $user->supplier;

        if (! $supplier) {
            return response()->json(['message' => 'Supplier account not found.'], 404);
        }

        $request->validate([
            'name'            => ['sometimes', 'string', 'min:3', 'max:200'],
            'min_order_value' => ['sometimes', 'numeric', 'min:0'],
            'min_order_qty'   => ['sometimes', 'integer', 'min:0'],
        ]);

        $updateData = [];

        if ($request->filled('name'))             $updateData['name']            = $request->name;
        if ($request->has('min_order_value'))      $updateData['min_order_value'] = (float)$request->min_order_value;
        if ($request->has('min_order_qty'))        $updateData['min_order_qty']   = (int)$request->min_order_qty;

        if (empty($updateData)) {
            return response()->json(['message' => 'No changes provided.'], 422);
        }

        $supplier->update($updateData);

        return response()->json([
            'message' => 'Supplier details updated successfully.',
            'data'    => [
                'id'              => $supplier->id,
                'name'            => $supplier->name,
                'min_order_value' => $supplier->min_order_value,
                'min_order_qty'   => $supplier->min_order_qty,
            ],
        ], 200);
    }

    // =========================================================
    // DELETE /api/v1/profile
    // Middleware: auth:sanctum + active.user
    //
    // Deactivates the user account.
    // Does NOT hard delete — sets status to suspended.
    // Revokes all tokens immediately.
    // Admin must reactivate if needed.
    // =========================================================
    public function deactivate(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
        ], [
            'password.required' => 'Please confirm your password to deactivate your account.',
        ]);

        $user = $request->user();

        if (! Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Password is incorrect.',
                'errors'  => [
                    'password' => ['The password you entered is incorrect.'],
                ],
            ], 422);
        }

        // Revoke all tokens first
        $user->tokens()->delete();

        // Soft deactivate
        $user->update([
            'status'    => 'suspended',
            'is_active' => 0,
        ]);

        return response()->json([
            'message' => 'Your account has been deactivated. Contact support to reactivate.',
        ], 200);
    }



    // =========================================================
    // PATCH /api/v1/profile/email
    // Middleware: auth:sanctum + active.user
    //
    // Updates the user's email directly after password
    // confirmation. No admin approval needed.
    //
    // Validates:
    //   - Password must be correct
    //   - New email must be unique across users table
    //   - New email cannot be same as current
    // =========================================================
    public function updateEmail(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'email'    => [
                'required',
                'email',
                'max:191',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'password' => ['required', 'string'],
        ], [
            'email.unique'    => 'This email address is already registered to another account.',
            'email.required'  => 'New email address is required.',
            'password.required' => 'Please confirm your password to change your email.',
        ]);

        // Verify password before allowing email change
        if (! Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Password is incorrect.',
                'errors'  => [
                    'password' => ['The password you entered is incorrect.'],
                ],
            ], 422);
        }

        // Prevent updating to same email
        if (strtolower($request->email) === strtolower($user->email)) {
            return response()->json([
                'message' => 'New email is the same as your current email.',
                'errors'  => [
                    'email' => ['Please enter a different email address.'],
                ],
            ], 422);
        }

        $oldEmail = $user->email;
        $user->update(['email' => $request->email]);

        // Revoke all tokens — force re-login with new email
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Email updated successfully. Please log in again.',
            'data'    => [
                'old_email' => $oldEmail,
                'new_email' => $user->email,
            ],
        ], 200);
    }

    // =========================================================
    // POST /api/v1/profile/request-zone-update
    // Middleware: auth:sanctum + active.user
    //
    // Submits a request to change zones.
    // Admin must approve before zones are actually changed.
    // Creates a registration_requests row of type zone_update.
    //
    // For pharmacy: changes pharmacy_branch_zones
    // For supplier: changes supplier_zones
    // =========================================================
    public function requestZoneUpdate(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! in_array($user->role, ['pharmacy', 'supplier'])) {
            return response()->json([
                'message' => 'Zone update requests are only available for pharmacy and supplier accounts.',
            ], 403);
        }

        $request->validate([
            'zone_ids'       => ['required', 'array', 'min:1'],
            'zone_ids.*'     => ['integer', 'exists:zones,id'],
            'reason'         => ['nullable', 'string', 'max:500'],
        ], [
            'zone_ids.required' => 'Please select at least one zone.',
            'zone_ids.*.exists' => 'One or more selected zones are not valid.',
        ]);

        // Check for existing pending request
        $existingRequest = \App\Models\RegistrationRequest::where('user_id', $user->id)
            ->where('entity_type', 'zone_update')
            ->where('status', 'pending')
            ->first();

        if ($existingRequest) {
            return response()->json([
                'message' => 'You already have a pending zone update request. Please wait for admin review.',
                'data'    => ['request_id' => $existingRequest->id],
            ], 422);
        }

        // Get current entity info for the request
        $entityInfo = $this->getEntityInfo($user);

        $regRequest = \App\Models\RegistrationRequest::create([
            'user_id'        => $user->id,
            'entity_type'    => 'zone_update',
            'applicant_name' => $user->name,
            'business_name'  => $entityInfo['business_name'],
            'licence_number' => $entityInfo['licence_number'],
            'phone'          => $user->phone,
            'address'        => $entityInfo['address'],
            'zone_id'        => $request->zone_ids[0],
            'meta'           => json_encode([
                'zone_ids'    => $request->zone_ids,
                'reason'      => $request->reason,
                'entity_type' => $user->role,
                'entity_id'   => $entityInfo['entity_id'],
            ]),
            'status'         => 'pending',
        ]);

        return response()->json([
            'message' => 'Zone update request submitted. An admin will review it shortly.',
            'data'    => [
                'request_id'  => $regRequest->id,
                'status'      => 'pending',
                'zone_ids'    => $request->zone_ids,
                'submitted_at'=> $regRequest->created_at,
            ],
        ], 201);
    }

    // =========================================================
    // POST /api/v1/profile/request-licence-update
    // Middleware: auth:sanctum + active.user
    //
    // Submits a request to change licence number.
    // Requires uploading the new licence image.
    // Admin must approve before licence is actually changed.
    // =========================================================
    public function requestLicenceUpdate(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! in_array($user->role, ['pharmacy', 'supplier'])) {
            return response()->json([
                'message' => 'Licence update requests are only available for pharmacy and supplier accounts.',
            ], 403);
        }

        $request->validate([
            'licence_number' => ['required', 'string', 'max:100'],
            'licence_image'  => ['required', 'file', 'mimes:jpg,jpeg,png,heic,pdf', 'max:5120'],
            'reason'         => ['nullable', 'string', 'max:500'],
        ], [
            'licence_number.required' => 'New licence number is required.',
            'licence_image.required'  => 'Please upload your new licence image.',
            'licence_image.max'       => 'Licence image must not exceed 5MB.',
        ]);

        // Check for existing pending request
        $existingRequest = \App\Models\RegistrationRequest::where('user_id', $user->id)
            ->where('entity_type', 'licence_update')
            ->where('status', 'pending')
            ->first();

        if ($existingRequest) {
            return response()->json([
                'message' => 'You already have a pending licence update request.',
                'data'    => ['request_id' => $existingRequest->id],
            ], 422);
        }

        $entityInfo = $this->getEntityInfo($user);

        $regRequest = \App\Models\RegistrationRequest::create([
            'user_id'        => $user->id,
            'entity_type'    => 'licence_update',
            'applicant_name' => $user->name,
            'business_name'  => $entityInfo['business_name'],
            'licence_number' => $request->licence_number,
            'phone'          => $user->phone,
            'address'        => $entityInfo['address'],
            'meta'           => json_encode([
                'reason'          => $request->reason,
                'entity_type'     => $user->role,
                'entity_id'       => $entityInfo['entity_id'],
                'old_licence'     => $entityInfo['licence_number'],
                'new_licence'     => $request->licence_number,
            ]),
            'status'         => 'pending',
        ]);

        // Store the new licence image
        $file      = $request->file('licence_image');
        $path      = $file->store("licences/{$regRequest->id}", 'private');

        \App\Models\LicenceImage::create([
            'registration_request_id' => $regRequest->id,
            'file_path'               => $path,
            'file_name'               => $file->getClientOriginalName(),
            'mime_type'               => $file->getMimeType(),
            'file_size_kb'            => (int)($file->getSize() / 1024),
            'is_primary'              => 1,
        ]);

        return response()->json([
            'message' => 'Licence update request submitted. An admin will review your new licence.',
            'data'    => [
                'request_id'      => $regRequest->id,
                'status'          => 'pending',
                'new_licence'     => $request->licence_number,
                'submitted_at'    => $regRequest->created_at,
            ],
        ], 201);
    }

    // =========================================================
    // GET /api/v1/profile/update-requests
    // Middleware: auth:sanctum + active.user
    //
    // Returns the user's pending/resolved update requests
    // so they can track status of zone or licence requests.
    // =========================================================
    public function updateRequests(Request $request): JsonResponse
    {
        $requests = \App\Models\RegistrationRequest::where('user_id', $request->user()->id)
            ->whereIn('entity_type', ['zone_update', 'licence_update'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($req) => [
                'id'             => $req->id,
                'type'           => $req->entity_type,
                'status'         => $req->status,
                'submitted_at'   => $req->created_at,
                'reviewed_at'    => $req->reviewed_at,
                'decline_reason' => $req->decline_reason,
                'meta'           => $req->meta,
            ]);

        return response()->json([
            'message' => 'Update requests retrieved successfully.',
            'data'    => $requests,
        ], 200);
    }

    // =========================================================
    // PRIVATE — Get entity info for update requests
    // =========================================================
    private function getEntityInfo(\App\Models\User $user): array
    {
        if ($user->role === 'pharmacy') {
            $branch = $user->pharmacyBranch;
            return [
                'business_name'  => $branch?->name ?? $user->name,
                'licence_number' => $branch?->licence_number ?? '',
                'address'        => $branch?->address ?? '',
                'entity_id'      => $branch?->id,
            ];
        }

        if ($user->role === 'supplier') {
            $supplier = $user->supplier;
            return [
                'business_name'  => $supplier?->name ?? $user->name,
                'licence_number' => $supplier?->licence_number ?? '',
                'address'        => '',
                'entity_id'      => $supplier?->id,
            ];
        }

        return [
            'business_name'  => $user->name,
            'licence_number' => '',
            'address'        => '',
            'entity_id'      => null,
        ];
    }
}