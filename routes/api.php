<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\Admin\AdminRegistrationController;
use App\Http\Controllers\API\Admin\ZoneController;
use App\Http\Controllers\API\Admin\LicenceImageController;
use App\Http\Controllers\API\Admin\DrugController;
use App\Http\Controllers\API\Supplier\SupplierInventoryController;

Route::prefix('v1')->group(function () {

    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/register/pharmacy',  [AuthController::class, 'registerPharmacy']);
    Route::post('/register/supplier',  [AuthController::class, 'registerSupplier']);
    Route::get('/registration/status', [AuthController::class, 'registrationStatus']);
     // Zones list is public — needed for registration dropdowns
    Route::get('/zones', [ZoneController::class, 'index']);

    // Drug catalog — public search for pharmacy order creation
    Route::get('/drugs',              [DrugController::class, 'index']);
    Route::get('/drugs/dosage-forms', [DrugController::class, 'dosageForms']);
    Route::get('/drugs/{id}',         [DrugController::class, 'show']);

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

            // Drug catalog management (admin writes)
            Route::post('/drugs',              [DrugController::class, 'store']);
            Route::put('/drugs/{id}',          [DrugController::class, 'update']);
            Route::patch('/drugs/{id}/toggle', [DrugController::class, 'toggle']);

            // Licence image viewer (signed URL)
            Route::get('/licences/{image}/view', [LicenceImageController::class, 'view'])
                ->name('admin.licence.view');


            // ... existing admin routes (registration-requests, zones, drugs) ...

            // ── Phase 2 — Banned drug feature (inert until flag is on) ──
            Route::get('/settings/banned-drug-check',   [BannedDrugController::class, 'getFlag']);
            Route::patch('/settings/banned-drug-check', [BannedDrugController::class, 'toggleFlag']);

            Route::get('/banned-drugs',              [BannedDrugController::class, 'index']);
            Route::post('/banned-drugs',             [BannedDrugController::class, 'store']);
            Route::patch('/banned-drugs/{id}/toggle',[BannedDrugController::class, 'toggle']);
            Route::delete('/banned-drugs/{id}',      [BannedDrugController::class, 'destroy']);

            Route::get('/rejected-inventory-rows',   [BannedDrugController::class, 'rejectedRows']);
        });
    });
    

    // ────────────────────────────────────────────────────────
    // SUPPLIER ROUTES
    // ────────────────────────────────────────────────────────
    Route::middleware('role:supplier')->prefix('supplier')->group(function () {


     // ── Inventory CRUD ────────────────────────────────────────
    Route::get('/inventory',                         [SupplierInventoryController::class, 'index']);
    Route::post('/inventory',                        [SupplierInventoryController::class, 'store']);         // manual add
    Route::get('/inventory/{id}',                    [SupplierInventoryController::class, 'show']);          // single item
    Route::put('/inventory/{id}',                    [SupplierInventoryController::class, 'update']);        // full edit
    Route::patch('/inventory/{id}/quantity',         [SupplierInventoryController::class, 'updateQuantity']); // quick qty update
    Route::delete('/inventory/{id}',                 [SupplierInventoryController::class, 'destroy']);

    // ── Excel upload ──────────────────────────────────────────
    Route::post('/inventory/upload',                 [SupplierInventoryController::class, 'upload']);
    Route::get('/inventory/upload-status/{id}',      [SupplierInventoryController::class, 'uploadStatus']);
    Route::get('/inventory/upload-history',          [SupplierInventoryController::class, 'uploadHistory']);

        // Inventory management
        //to ad drug into the drugs table
        Route::post('/drugs',                   [DrugController::class, 'store']);
    });
 
        // ── Pharmacy only ─────────────────────────────────────
        Route::middleware('role:pharmacy')->group(function () {
            Route::post('/branches/request',
                [App\Http\Controllers\Api\Pharmacy\BranchController::class, 'request']);
        });
 
    });
});