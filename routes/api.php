<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;

Route::prefix('auth')->group(function () {

    Route::post('/login', [AuthController::class, 'login']);

});