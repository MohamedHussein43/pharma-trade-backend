<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserProfileController;
use App\Http\Controllers\Api\Admin\AdminRegistrationController;
use App\Http\Controllers\Api\Admin\ZoneController;
use App\Http\Controllers\Api\Admin\LicenceImageController;
use App\Http\Controllers\Api\Admin\DrugController;
use App\Http\Controllers\Api\Admin\BannedDrugController;
use App\Http\Controllers\Api\Supplier\SupplierInventoryController;

use App\Http\Controllers\Api\Pharmacy\PharmacyController;
use App\Http\Controllers\Api\Pharmacy\OrderController;
use App\Http\Controllers\Api\Pharmacy\AllocateOrderController;
use App\Http\Controllers\Api\Supplier\SupplierOrderController;
use App\Http\Controllers\Api\Admin\CommissionController;
use App\Http\Controllers\Api\Admin\AdminSettingsController;
use App\Http\Controllers\Api\Pharmacy\OrderTrackingController;

use App\Http\Controllers\Api\Admin\NotificationController;

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


        // ── Notifications — all roles ─────────────────────────────
        // IMPORTANT: read-all and unread-count must come BEFORE
        // /{id} routes otherwise Laravel matches "read-all" as an id
        Route::get('/notifications',                    [NotificationController::class, 'index']);
        Route::get('/notifications/unread-count',       [NotificationController::class, 'unreadCount']);
        Route::patch('/notifications/read-all',         [NotificationController::class, 'markAllRead']);
        Route::patch('/notifications/{id}/read',        [NotificationController::class, 'markRead']);
        Route::delete('/notifications',                 [NotificationController::class, 'destroyAll']);
        Route::delete('/notifications/{id}',            [NotificationController::class, 'destroy']);
 
        // ── Auth ─────────────────────────────────────────────
        Route::post('/logout',     [AuthController::class, 'logout']);
        Route::post('/logout/all', [AuthController::class, 'logoutAll']);
         // ── Profile — all authenticated roles ────────────────────
        Route::get('/profile',                  [UserProfileController::class, 'show']);
        Route::put('/profile',                  [UserProfileController::class, 'update']);
        //Route::patch('/profile/email',          [UserProfileController::class, 'updateEmail']);  
        Route::patch('/profile/password',       [UserProfileController::class, 'changePassword']);
        Route::patch('/profile/device-token',   [UserProfileController::class, 'updateDeviceToken']);
        Route::delete('/profile',               [UserProfileController::class, 'deactivate']);

        // ── Update requests (zone + licence) ─────────────────────
        Route::post('/profile/request-zone-update',     [UserProfileController::class, 'requestZoneUpdate']);    // ← NEW
        Route::post('/profile/request-licence-update',  [UserProfileController::class, 'requestLicenceUpdate']); // ← NEW
        Route::get('/profile/update-requests',          [UserProfileController::class, 'updateRequests']);        // ← NEW

        // ── Branch profile — pharmacy only ───────────────────────
        Route::patch('/profile/branch', [UserProfileController::class, 'updateBranch'])
            ->middleware('role:pharmacy');

        // ── Supplier profile — supplier only ─────────────────────
        Route::patch('/profile/supplier', [UserProfileController::class, 'updateSupplier'])
            ->middleware('role:supplier');
 
        // ── Admin only ────────────────────────────────────────
       Route::middleware('role:admin')->group(function () {

        // Registration request review
        Route::prefix('admin')->group(function () {
            Route::get('/fcm-debug', [\App\Http\Controllers\Api\Admin\FcmDebugController::class, 'debug']);
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
            Route::get('/settings',  [AdminSettingsController::class, 'getSettings']);
            Route::patch('/settings', [AdminSettingsController::class, 'updateSettings']);

            Route::get('/commissions',                      [CommissionController::class, 'index']);
            Route::get('/commissions/by-supplier',          [CommissionController::class, 'bySupplier']);
            Route::get('/commissions/by-period',            [CommissionController::class, 'byPeriod']);
            Route::get('/commissions/order/{order_id}',     [CommissionController::class, 'byOrder']);
            Route::patch('/commissions/{id}/mark-paid',     [CommissionController::class, 'markPaid']);
            Route::patch('/commissions/bulk-mark-paid',     [CommissionController::class, 'bulkMarkPaid']);
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


        // Orders
        Route::get('/orders',                          [SupplierOrderController::class, 'index']);
        Route::get('/orders/{id}',                     [SupplierOrderController::class, 'show']);
        Route::post('/orders/{id}/confirm',            [SupplierOrderController::class, 'confirm']);
        Route::post('/orders/{id}/report-shortage',    [SupplierOrderController::class, 'reportShortage']);
        Route::post('/orders/{id}/ship',               [SupplierOrderController::class, 'ship']);
        Route::patch('/orders/{id}/deliver',           [SupplierOrderController::class, 'deliver']);
        Route::get('/orders/{id}/track', [OrderTrackingController::class, 'trackSupplierOrder']);

            // Inventory management
        //to ad drug into the drugs table
        Route::post('/drugs',                   [DrugController::class, 'store']);
    });


    // ────────────────────────────────────────────────────────────
    // PHARMACY ROUTES
    // ────────────────────────────────────────────────────────────
    Route::middleware('role:pharmacy')->prefix('pharmacy')->group(function () {

        // Branch profile
        Route::get('/branch',                          [PharmacyController::class, 'branch']);

        // Suppliers in zone
        Route::get('/suppliers',                       [PharmacyController::class, 'suppliers']);
        Route::get('/suppliers/drugs',                 [PharmacyController::class, 'availableDrugs']);
        Route::get('/suppliers/{id}/inventory',        [PharmacyController::class, 'supplierInventory']);

        // Orders
        Route::get('/orders',                          [OrderController::class, 'index']);
        Route::post('/orders',                         [OrderController::class, 'store']);
        Route::get('/orders/{id}',                     [OrderController::class, 'show']);
        Route::patch('/orders/{id}/cancel',            [OrderController::class, 'cancel']);

        // Order items
        Route::post('/orders/{id}/items',              [OrderController::class, 'addItem']);
        Route::delete('/orders/{id}/items/{item_id}',  [OrderController::class, 'removeItem']);
        Route::post('/orders/{id}/upload',             [OrderController::class, 'uploadItems']);

        // Allocation
        Route::post('/orders/{id}/allocate',           [AllocateOrderController::class, 'allocate']);
        Route::post('/orders/{id}/confirm',            [AllocateOrderController::class, 'confirm']);
        Route::post('/orders/{id}/resolve-shortage',   [AllocateOrderController::class, 'resolveShortage']);
        Route::post('/orders/{id}/confirm-delivery',   [PharmacyController::class, 'confirmDelivery']);
        Route::get('/orders/{id}/track', [OrderTrackingController::class, 'track']);

    });

 
        // ── Pharmacy only ─────────────────────────────────────
       /* Route::middleware('role:pharmacy')->group(function () {
            Route::post('/branches/request',
                [App\Http\Controllers\Api\Pharmacy\BranchController::class, 'request']);
        });*/
 
    });
});