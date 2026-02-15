<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\GameDataController;
use App\Http\Controllers\GachaController;
use App\Http\Controllers\GoogleAuthController;
use Illuminate\Support\Facades\Route;

// Authentication
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Google OAuth Authentication
Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect']);
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback']);
Route::post('/auth/google/verify-token', [GoogleAuthController::class, 'verifyToken']);

// Password Reset (Custom for Unity)
Route::post('/password/email', [AuthController::class, 'sendResetEmail']);
Route::post('/password/verify', [AuthController::class, 'verifyResetCode']);
Route::post('/password/reset', [AuthController::class, 'resetPassword']);

// Protected Routes (ต้องมี Token)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']); // Validate Token
    
    // Google Account Management
    Route::post('/auth/google/link', [GoogleAuthController::class, 'linkAccount']);
    Route::post('/auth/google/unlink', [GoogleAuthController::class, 'unlinkAccount']);
    Route::get('/auth/google/check-linked', [GoogleAuthController::class, 'checkLinked']);
    
    // Save/Load Blob
    Route::post('/save', [GameDataController::class, 'save']);
    Route::get('/load', [GameDataController::class, 'load']);

    // Gacha System
    Route::post('/gacha/pull', [GachaController::class, 'pull']);
});
