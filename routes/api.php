<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\Admin\AdminRegistrationController;
use App\Http\Controllers\API\Admin\ZoneController;
use App\Http\Controllers\API\Admin\LicenceImageController;

Route::prefix('v1')->group(function () {

    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/register/pharmacy',  [AuthController::class, 'registerPharmacy']);
    Route::post('/register/supplier',  [AuthController::class, 'registerSupplier']);
    Route::get('/registration/status', [AuthController::class, 'registrationStatus']);
     // Zones list is public — needed for registration dropdowns
    Route::get('/zones', [ZoneController::class, 'index']);

});
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
 
    // Pending users can only call this endpoint
 
    // All other routes require is_active = 1
    Route::middleware('active.user')->group(function () {
 
        // ── Auth ─────────────────────────────────────────────
        Route::post('/logout',     [AuthController::class, 'logout']);
        Route::post('/logout/all', [AuthController::class, 'logoutAll']);
 
        // ── Admin only ────────────────────────────────────────
       Route::middleware('role:admin')->group(function () {

        // Registration request review
        Route::prefix('admin')->group(function () {

            // Registration requests
            Route::get('/registration-requests/stats',
                [AdminRegistrationController::class, 'stats']);
            Route::get('/registration-requests',
                [AdminRegistrationController::class, 'index']);
            Route::get('/registration-requests/{id}',
                [AdminRegistrationController::class, 'show']);
            Route::post('/registration-requests/{id}/approve',
                [AdminRegistrationController::class, 'approve']);
            Route::post('/registration-requests/{id}/decline',
                [AdminRegistrationController::class, 'decline']);

            // Zone management
            Route::get('/zones/{id}',       [ZoneController::class, 'show']);
            Route::post('/zones',           [ZoneController::class, 'store']);
            Route::put('/zones/{id}',       [ZoneController::class, 'update']);
            Route::patch('/zones/{id}/toggle', [ZoneController::class, 'toggle']);
            Route::delete('/zones/{id}',    [ZoneController::class, 'destroy']);

            // Licence image viewer (signed URL)
            Route::get('/licences/{image}/view', [LicenceImageController::class, 'view'])
                ->name('admin.licence.view');
        });
    });
 
        // ── Pharmacy only ─────────────────────────────────────
        Route::middleware('role:pharmacy')->group(function () {
            Route::post('/branches/request',
                [App\Http\Controllers\Api\Pharmacy\BranchController::class, 'request']);
        });
 
    });
});