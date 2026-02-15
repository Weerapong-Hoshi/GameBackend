<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\GameSave;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Google\Client as GoogleClient;

class GoogleAuthController extends Controller
{
    /**
     * Redirect to Google OAuth (for web browser redirect flow)
     */
    public function redirect(): JsonResponse
    {
        // For WebGL, we'll use a different approach (ID token verification)
        // This endpoint might not be needed for WebGL, but kept for completeness
        $clientId = config('services.google.client_id');
        $redirectUri = config('services.google.redirect');
        $scope = urlencode('openid email profile');
        
        $redirectUrl = "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => $scope,
            'access_type' => 'online',
            'prompt' => 'select_account',
        ]);

        return response()->json(['redirect_url' => $redirectUrl]);
    }

    /**
     * Handle Google OAuth callback (for web browser redirect flow)
     */
    public function callback(Request $request): JsonResponse
    {
        // This callback is for web browser flow, not WebGL
        // For WebGL, use verifyToken endpoint instead
        return response()->json([
            'message' => 'Please use the verify-token endpoint for Unity WebGL'
        ], 400);
    }

    /**
     * Verify Google ID token from Unity WebGL
     * This endpoint is for Unity to send the ID token directly
     * Uses Google PHP Client Library for JWT verification (production-ready)
     */
    public function verifyToken(Request $request): JsonResponse
    {
        $request->validate([
            'id_token' => 'required|string',
        ]);
        
        try {
            $idToken = $request->id_token;
            
            // Initialize Google Client
            $client = new GoogleClient(['client_id' => config('services.google.client_id')]);
            
            // Verify the ID token
            $payload = $client->verifyIdToken($idToken);
            
            if (!$payload) {
                return response()->json([
                    'message' => 'Invalid Google Token - Verification failed'
                ], 401);
            }
            
            // Extract user information from the payload
            $googleId = $payload['sub']; // Google's unique ID for the user
            $email = $payload['email'];
            $name = $payload['name'] ?? 'Player';
            $avatar = $payload['picture'] ?? 'https://lh3.googleusercontent.com/a/default-user';
            
            // Validate email (must be @gmail.com for your system)
            if (!preg_match('/@gmail\.com$/i', $email)) {
                return response()->json([
                    'message' => 'Email ต้องลงท้ายด้วย @gmail.com'
                ], 422);
            }
            
            // Find user by google_id
            $user = User::where('google_id', $googleId)->first();
            
            if (!$user) {
                // Try to find by email (existing user with same email)
                $user = User::where('email', $email)->first();
                
                if ($user) {
                    // Link Google account to existing user
                    $user->google_id = $googleId;
                    $user->avatar = $avatar;
                    $user->save();
                    
                    \Log::info('Google account linked to existing user', [
                        'user_id' => $user->id,
                        'email' => $email,
                        'google_id' => $googleId
                    ]);
                } else {
                    // Create new user
                    $user = User::create([
                        'name' => $name,
                        'email' => $email,
                        'google_id' => $googleId,
                        'avatar' => $avatar,
                        'password' => null, // No password for Google users
                    ]);
                    
                    // Create default game save for new user
                    $this->createDefaultGameSave($user);
                    
                    \Log::info('New user created via Google Sign-In', [
                        'user_id' => $user->id,
                        'email' => $email,
                        'google_id' => $googleId
                    ]);
                }
            }
            
            // Delete existing tokens and create new one
            $user->tokens()->delete();
            $token = $user->createToken('unity-game')->plainTextToken;
            
            return response()->json([
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar,
                    'has_google_linked' => !empty($user->google_id),
                ],
                'message' => 'Google Sign-In Successful'
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Google Sign-In failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'message' => 'Google Sign-In Failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Link Google account to existing user (requires authentication)
     */
    public function linkAccount(Request $request): JsonResponse
    {
        $request->validate([
            'id_token' => 'required|string',
        ]);
        
        $user = $request->user();
        
        if ($user->google_id) {
            return response()->json([
                'message' => 'Google account already linked'
            ], 400);
        }
        
        try {
            $idToken = $request->id_token;
            
            // Initialize Google Client
            $client = new GoogleClient(['client_id' => config('services.google.client_id')]);
            
            // Verify the ID token
            $payload = $client->verifyIdToken($idToken);
            
            if (!$payload) {
                return response()->json([
                    'message' => 'Invalid Google Token - Verification failed'
                ], 401);
            }
            
            // Extract user information from the payload
            $googleId = $payload['sub'];
            $email = $payload['email'];
            $avatar = $payload['picture'] ?? 'https://lh3.googleusercontent.com/a/default-user';
            
            // Check if this Google account is already linked to another user
            $existingGoogleUser = User::where('google_id', $googleId)->first();
            if ($existingGoogleUser) {
                return response()->json([
                    'message' => 'This Google account is already linked to another user'
                ], 400);
            }
            
            // Check if the email from Google matches the current user's email
            if ($user->email !== $email) {
                return response()->json([
                    'message' => 'Google account email does not match your account email'
                ], 400);
            }
            
            // Validate email format (must be @gmail.com for your system)
            if (!preg_match('/@gmail\.com$/i', $email)) {
                return response()->json([
                    'message' => 'Email ต้องลงท้ายด้วย @gmail.com'
                ], 422);
            }
            
            // Link the Google account
            $user->google_id = $googleId;
            $user->avatar = $avatar;
            $user->save();
            
            \Log::info('Google account linked to user', [
                'user_id' => $user->id,
                'email' => $email,
                'google_id' => $googleId
            ]);
            
            return response()->json([
                'message' => 'Google account linked successfully',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar,
                    'has_google_linked' => true,
                ]
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to link Google account', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'message' => 'Failed to link Google account',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Unlink Google account from user
     */
    public function unlinkAccount(Request $request): JsonResponse
    {
        $user = $request->user();
        
        if (!$user->google_id) {
            return response()->json([
                'message' => 'No Google account linked'
            ], 400);
        }
        
        // Check if user has password (to prevent lockout)
        if (empty($user->password)) {
            return response()->json([
                'message' => 'Cannot unlink Google account. Please set a password first.'
            ], 400);
        }
        
        $user->google_id = null;
        $user->avatar = null;
        $user->save();
        
        return response()->json([
            'message' => 'Google account unlinked successfully',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => null,
                'has_google_linked' => false,
            ]
        ]);
    }

    /**
     * Check if user has Google account linked
     */
    public function checkLinked(Request $request): JsonResponse
    {
        $user = $request->user();
        
        return response()->json([
            'has_google_linked' => !empty($user->google_id),
            'avatar' => $user->avatar,
        ]);
    }

    /**
     * Create default game save for new user
     */
    private function createDefaultGameSave(User $user): void
    {
        $defaultSaveData = [
            'allPresets' => [],
            'progressData' => ['progressEntries' => []],
            'playerData' => [
                'playerName' => 'นักผจญภัย',
                'coins' => 5000,
                'gems' => 100,
                'playerRank' => 1,
                'currentExp' => 0,
                'expToNextRank' => 100,
                'maxTeamCost' => 50,
                'currentEnergy' => 240,
                'lastEnergyUpdateTime' => now()->timestamp,
                'gachaPityCounters' => new \stdClass(),
                'usedRedeemCodes' => [],
                'dailyShopPurchases' => new \stdClass(),
                'lastShopResetDate' => now()->format('Y-m-d'),
                'ownedCharacters' => new \stdClass(),
                'ownedMaterials' => new \stdClass(),
                'encounteredCharacterIds' => [],
            ],
            'questData' => ['questProgress' => new \stdClass()]
        ];

        GameSave::create([
            'user_id' => $user->id,
            'data' => json_encode($defaultSaveData, JSON_UNESCAPED_UNICODE),
            'pity_count' => 0
        ]);
    }
}