<?php

use Illuminate\Http\Request;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GameDataController;
use App\Http\Controllers\GachaController;
use App\Http\Controllers\GoogleAuthController;
use Illuminate\Support\Facades\Route;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

// Rate Limiting Configuration
RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
});

RateLimiter::for('auth', function (Request $request) {
    return Limit::perMinute(10)->by($request->ip());
});

RateLimiter::for('password-reset', function (Request $request) {
    return Limit::perHour(5)->by($request->ip());
});

// Authentication with rate limiting
Route::middleware('throttle:auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// Google OAuth Authentication with rate limiting
Route::middleware('throttle:auth')->group(function () {
    Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect']);
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback']);
    Route::post('/auth/google/verify-token', [GoogleAuthController::class, 'verifyToken']);
});

// Password Reset (Custom for Unity) with rate limiting
Route::middleware('throttle:password-reset')->group(function () {
    Route::post('/password/email', [AuthController::class, 'sendResetEmail']);
    Route::post('/password/verify', [AuthController::class, 'verifyResetCode']);
    Route::post('/password/reset', [AuthController::class, 'resetPassword']);
});

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
