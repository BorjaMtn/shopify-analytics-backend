<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\ConnectionController; // Asegúrate que está importado
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\InventoryController; // Asegúrate que está importado

/* API Routes */

Route::prefix('v1')->group(function () {

    // Auth públicas
    Route::post('/register', [AuthController::class, 'register'])->name('api.v1.register');
    Route::post('/login', [AuthController::class, 'login'])->name('api.v1.login');

    // Rutas protegidas
    Route::middleware('auth:sanctum')->group(function () {

        // Usuario
        Route::get('/user', [UserController::class, 'show'])->name('api.v1.user');
        Route::post('/logout', [AuthController::class, 'logout'])->name('api.v1.logout');

        // Conexiones
        Route::post('/connect/shopify/token', [ConnectionController::class, 'saveShopifyToken'])->name('api.v1.connect.shopify.token');

        // --- NUEVAS RUTAS PARA GOOGLE OAUTH ---
        Route::get('/connect/google', [ConnectionController::class, 'redirectToGoogle'])->name('api.v1.connect.google');
        Route::post('/connect/google/callback', [ConnectionController::class, 'handleGoogleCallback'])->name('api.v1.connect.google.callback');
        // --------------------------------------

        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('api.v1.dashboard');

        // Ruta para guardar/actualizar el ID de Propiedad de GA4
        Route::put('/connect/google/property', [\App\Http\Controllers\Api\V1\ConnectionController::class, 'saveGaPropertyId'])
        ->name('api.v1.connect.google.property');

        Route::get('/inventory-insights', [InventoryController::class, 'getInsights']);

    });
});