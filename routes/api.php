<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\App\PosController;
use Illuminate\Support\Facades\Route;

// Sanctum token API for the PWA/APK wrapper. Mirrors the shop web app's
// core flows so the same Laravel backend can serve both.

Route::post('login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'api.subscription'])->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);

    Route::get('home', [HomeController::class, 'index']);

    // Mirrors the owner-to-cashier `perm:` gates the web app enforces on
    // the equivalent routes (routes/web.php) — without these, a cashier the
    // owner deliberately withheld POS/customer access from could still
    // check out sales or read/create customers by calling the mobile API
    // directly, since the UI hiding the button was the only thing stopping
    // them.
    Route::middleware('perm:pos')->group(function () {
        Route::get('products', [ProductController::class, 'index']);
        Route::get('products/barcode/{barcode}', [ProductController::class, 'barcode'])->middleware('sales.mode');
        Route::post('pos/checkout', [PosController::class, 'checkout']);
    });

    Route::middleware('perm:customers')->group(function () {
        Route::get('customers', [CustomerController::class, 'index']);
        Route::post('customers', [CustomerController::class, 'store']);
    });
});
