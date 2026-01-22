<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\GameDataController;
use App\Http\Controllers\GachaController;
use Illuminate\Support\Facades\Route;

// Authentication
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Password Reset (Custom for Unity)
Route::post('/password/email', [AuthController::class, 'sendResetCode']);
Route::post('/password/verify', [AuthController::class, 'verifyResetCode']);
Route::post('/password/reset', [AuthController::class, 'resetPassword']);

// Protected Routes (ต้องมี Token)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']); // Validate Token
    
    // Save/Load Blob
    Route::post('/save', [GameDataController::class, 'save']);
    Route::get('/load', [GameDataController::class, 'load']);

    // Gacha System
    Route::post('/gacha/pull', [GachaController::class, 'pull']);
});