<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterPharmacyRequest;
use App\Http\Requests\RegisterSupplierRequest;

use App\Models\User;
use App\Models\RegistrationRequest;
use App\Models\LicenceImage;
use App\Models\Notification;

class AuthController extends Controller
{
     public function login(LoginRequest $request)
    {
        $user = User::where('phone', $request->phone)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {

            return response()->json([
                'success' => false,
                'message' => 'Invalid phone or password'
            ], 401);
        }

        if ($user->status === 'pending_approval') {

            return response()->json([
                'success' => false,
                'message' => 'Account pending approval'
            ], 403);
        }

        if ($user->status === 'suspended') //blocked from admin due to any reason
        {

            return response()->json([
                'success' => false,
                'message' => 'Account suspended'
            ], 403);
        }

        if (!$user->is_active) {

            return response()->json([
                'success' => false,
                'message' => 'Account inactive'
            ], 403);
        }

        $user->device_token = $request->device_token;
        $user->last_login_at = now();
        $user->save();

        $token = $user->createToken('mobile-app')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'email' => $user->email,
                'role' => $user->role
            ]
        ]);
    }

     public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
 
        // Clear device token so FCM push stops for this device
        $user->update(['device_token' => null]);
 
        // Delete only the token used in this request (not all tokens)
        // This allows multi-device logout to be scoped correctly.
        $request->user()->currentAccessToken()->delete();
 
        return response()->json([
            'message' => 'Logged out successfully.',
        ], 200);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $user = $request->user();
 
        // Clear device token
        $user->update(['device_token' => null]);
 
        // Revoke every token this user has
        $user->tokens()->delete();
 
        return response()->json([
            'message' => 'Logged out from all devices successfully.',
        ], 200);
    }

        // =========================================================
    // POST /api/register/pharmacy
    // Public — no token required
    //
    // Tables written:
    //   1. users                  (INSERT — is_active = 0)
    //   2. registration_requests  (INSERT — status = pending)
    //   3. licence_images         (INSERT — file stored)
    //   4. notifications          (INSERT — to all admins)
    //
    // All 4 writes are wrapped in a DB transaction.
    // If file upload or any insert fails → full rollback.
    // =========================================================
    public function registerPharmacy(RegisterPharmacyRequest $request): JsonResponse
    {
        try {
            $result = DB::transaction(function () use ($request) {

                // Step 1: Create user (inactive)
                $user = User::create([
                    'name'         => $request->name,
                    'email'        => $request->email,
                    'phone'        => $request->phone,
                    'password'     => Hash::make($request->password),
                    'role'         => 'pharmacy',
                    'status'       => 'pending_approval',
                    'is_active'    => 0,
                    'device_token' => $request->device_token,
                ]);

                // Step 2: Create registration request
                // Store all zone_ids in meta — same pattern as supplier
                $regRequest = RegistrationRequest::create([
                    'user_id'        => $user->id,
                    'entity_type'    => 'pharmacy',
                    'applicant_name' => $request->name,
                    'business_name'  => $request->business_name,
                    'licence_number' => $request->licence_number,
                    'phone'          => $request->phone,
                    'address'        => $request->address,
                    'zone_id'        => is_array($request->zones) 
                                                            ? $request->zones[0] 
                                                            : json_decode($request->zones)[0], // primary zone for display
                    'meta'           => json_encode([
                        'zone_ids' => $request->zones,     // ALL zones stored here
                    ]),
                    'status'         => 'pending',
                ]);

                // Step 3: Store licence images
                $this->storeLicenceImages($request, $regRequest->id);

                // Step 4: Notify all admins
                $this->notifyAllAdmins(
                    requestId: $regRequest->id,
                    title:     'New pharmacy licence pending review',
                    body:      "Pharmacy '{$request->business_name}' submitted a registration request.",
                );

                return ['user' => $user, 'request' => $regRequest];
            });

            return response()->json([
                'message' => 'Registration submitted successfully. Your account is pending admin review.',
                'data'    => [
                    'request_id'   => $result['request']['id'],
                    'status'       => 'pending',
                    'submitted_at' => $result['request']['created_at'],
                ],
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Registration failed. Please try again.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
 
    // =========================================================
    // POST /api/register/supplier
    // Public — no token required
    //
    // Tables written:
    //   1. users                  (INSERT — is_active = 0)
    //   2. registration_requests  (INSERT — status = pending)
    //   3. licence_images         (INSERT)
    //   4. notifications          (INSERT — to all admins)
    //
    // zone_ids array is stored as JSON in the request notes
    // field until approval, when supplier_zones rows are made.
    // =========================================================
    public function registerSupplier(RegisterSupplierRequest $request): JsonResponse
    {
        
        
        if (!$request->zones)
            {
                
                 $request->zones = [1];
        }  
                
        try {
            $result = DB::transaction(function () use ($request) {
 
                // ── Step 1: Create the user (inactive) ──
                $user = User::create([
                    'name'         => $request->name,
                    'email'        => $request->email,
                    'phone'        => $request->phone,
                    'password'     => Hash::make($request->password),
                    'role'         => 'supplier',
                    'status'       => 'pending_approval',
                    'is_active'    => 0,
                    'device_token' => $request->device_token,
                ]);
                
 
                // ── Step 2: Create registration request ──
                // zone_ids stored as JSON in address field temporarily,
                // OR add a nullable JSON column to registration_requests.
                // Here we store them cleanly as a separate notes field.
                $regRequest = RegistrationRequest::create([
                    'user_id'        => $user->id,
                    'entity_type'    => 'supplier',
                    'applicant_name' => $request->name,
                    'business_name'  => $request->business_name,
                    'licence_number' => $request->licence_number,
                    'phone'          => $request->phone,
                    'address'        => $request->address,
                    'zone_id' => is_array($request->zones) 
                        ? $request->zones[0] 
                        : json_decode($request->zones)[0],        // primary zone (for display)
                    'status'         => 'pending',
                    'meta'           => json_encode([                  // ALL zones stored here
                                            'zone_ids'        => $request->zones,       // ← changed from zone_ids
                                            'min_order_value' => $request->min_order_value ?? 0,
                                            'min_order_qty'   => $request->min_order_qty   ?? 0,
                                        ]),
                ]);
 
                // ── Step 3: Licence image ──
                $this->storeLicenceImages($request, $regRequest->id);
 
                // ── Step 4: Notify admins ──
                $this->notifyAllAdmins(
                    requestId: $regRequest->id,
                    title:     'New supplier licence pending review',
                    body:      "Supplier '{$request->business_name}' submitted a registration request.",
                );
 
                return ['user' => $user, 'request' => $regRequest];
            });
 
            return response()->json([
                'message' => 'Supplier registration submitted. Your account is pending admin review.',
                'data'    => [
                    'request_id'   => $result['request']['id'],
                    'status'       => 'pending',
                    'submitted_at' => $result['request']['created_at'],
                ],
            ], 201);
 
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Registration failed. Please try again.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
 
    // =========================================================
    // GET /api/registration/status
    // Requires: Bearer token (auth:sanctum)
    //
    // The pending user polls this to see their review status.
    // Works even when is_active = 0 (token still valid but
    // all other endpoints return 403).
    // =========================================================
    public function registrationStatus(Request $request): JsonResponse
    {   
        $user = User::where('phone', $request->phone)->first();

        if (! $user) {
            return response()->json([
                'message' => 'No account found with this phone address.',
            ], 404);
        }

        // Admins and active users don't need this endpoint
        if ($user->is_active && $user->status === 'active') {
            return response()->json([
                'message' => 'Your account is active. Please log in normally.',
                'status'  => 'active',
            ], 200);
        }

        $regRequest = RegistrationRequest::where('user_id', $user->id)
            ->latest()
            ->first();

        if (! $regRequest) {
            return response()->json([
                'message' => 'No registration request found for this phone.',
            ], 404);
        }

        $response = [
            'status'       => $regRequest->status,
            'submitted_at' => $regRequest->created_at,
            'reviewed_at'  => $regRequest->reviewed_at,
        ];

        if ($regRequest->status === 'pending') {
            $response['message'] = 'Your licence is under review. We will notify you once reviewed.';
        } elseif ($regRequest->status === 'approved') {
            $response['message'] = 'Your account is approved. Please log in.';
        } elseif ($regRequest->status === 'declined') {
            $response['message'] = 'Your registration was declined. Please contact support.';
            $response['decline_reason'] = $regRequest->decline_reason;
        }

        return response()->json(['data' => $response], 200);
    }
 
    // =========================================================
    // PRIVATE HELPERS
    // =========================================================
 
    /**
     * Store one or two licence images for a registration request.
     * Accepts:  licence_image        (required)
     *           licence_image_back   (optional)
     */
    private function storeLicenceImages(Request $request, int $requestId): void
    {
        $images = [];
 
        if ($request->hasFile('licence_image')) {
            $images[] = ['file' => $request->file('licence_image'), 'is_primary' => 1];
        }
 
        if ($request->hasFile('licence_image_back')) {
            $images[] = ['file' => $request->file('licence_image_back'), 'is_primary' => 0];
        }
 
        foreach ($images as $img) {
            // Store in private disk — never public
            // Path: licences/2024/03/random_hash.jpg
            $path = $img['file']->store('licences/' . date('Y/m'), 'private');
 
            LicenceImage::create([
                'registration_request_id' => $requestId,
                'file_path'               => $path,
                'file_name'               => $img['file']->getClientOriginalName(),
                'mime_type'               => $img['file']->getMimeType(),
                'file_size_kb'            => (int) ceil($img['file']->getSize() / 1024),
                'is_primary'              => $img['is_primary'],
            ]);
        }
    }
 
    /**
     * Send a push notification to every admin user.
     * Runs inside the same DB transaction as the registration.
     */
    private function notifyAllAdmins(int $requestId, string $title, string $body): void
    {
        $admins = User::where('role', 'admin')->where('is_active', 1)->get();
 
        foreach ($admins as $admin) {
            Notification::create([
                'user_id'          => $admin->id,
                'title'            => $title,
                'body'             => $body,
                'type'             => 'registration_submitted',
                'channel'          => 'push',
                'notifiable_type'  => 'RegistrationRequest',
                'notifiable_id'    => $requestId,
                'is_read'          => 0,
            ]);
 
            // TODO: Fire FCM push using device_token
            // FirebasePushService::send($admin->device_token, $title, $body);
        }
    }
}
