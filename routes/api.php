<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;

Route::prefix('v1')->group(function () {

    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/register/pharmacy',  [AuthController::class, 'registerPharmacy']);
    Route::post('/register/supplier',  [AuthController::class, 'registerSupplier']);
    Route::get('/registration/status', [AuthController::class, 'registrationStatus']);

});
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
 
    // Pending users can only call this endpoint
 
    // All other routes require is_active = 1
    Route::middleware('active.user')->group(function () {
 
        // ── Auth ─────────────────────────────────────────────
        Route::post('/logout',     [AuthController::class, 'logout']);
        Route::post('/logout/all', [AuthController::class, 'logoutAll']);
 
        // ── Admin only ────────────────────────────────────────
       //    check about registeration requests i thing it git all the pending apporvals requests but just check with claude
        Route::middleware('role:admin')->prefix('admin')->group(function () {
            Route::get('/registration-requests',
                [App\Http\Controllers\Api\Admin\RegistrationController::class, 'index']);
            Route::post('/registration-requests/{id}/approve',
                [App\Http\Controllers\Api\Admin\RegistrationController::class, 'approve']);
            Route::post('/registration-requests/{id}/decline',
                [App\Http\Controllers\Api\Admin\RegistrationController::class, 'decline']);
        });
 
        // ── Pharmacy only ─────────────────────────────────────
        Route::middleware('role:pharmacy')->group(function () {
            Route::post('/branches/request',
                [App\Http\Controllers\Api\Pharmacy\BranchController::class, 'request']);
        });
 
    });
});