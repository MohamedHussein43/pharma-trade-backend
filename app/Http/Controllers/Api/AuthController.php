<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\LoginRequest;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

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
}
