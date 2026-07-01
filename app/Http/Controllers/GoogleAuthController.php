<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\GameSave;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\DB;
use Google\Client as GoogleClient;

class GoogleAuthController extends Controller
{
    /**
     * Redirect to Google OAuth (for web browser redirect flow)
     * 
     * รองรับ guest_token สำหรับผูกบัญชี Guest กับ Google
     * Unity เปิด URL: /auth/google/redirect?guest_token=xxx
     */
    // 1. ส่งผู้ใช้ไปหน้าเลือกอีเมลของ Google
    public function redirect(Request $request)
    {
        $params = [
            'client_id' => env('GOOGLE_CLIENT_ID'),
            'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'access_type' => 'online',
            'prompt' => 'select_account',
        ];

        // ✅ ถ้ามี guest_token ให้ส่งผ่าน state เพื่อให้ Google ส่งกลับมาให้ callback
        $guestToken = $request->query('guest_token');
        if ($guestToken) {
            $params['state'] = 'guest_token=' . $guestToken;
        }

        $url = "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query($params);
        return redirect($url);
    }


    // 2. รับ Code จาก Google, สร้าง User, ออก Token และส่งเข้าเกม
    public function callback(Request $request)
    {
        $code = $request->query('code');
        $state = $request->query('state'); // อ่าน state ที่ Google ส่งกลับมา
        if (!$code)
            return response()->json(['error' => 'No code provided'], 400);

        try {
            // แลก Code เป็น Access Token
            $tokenResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'code' => $code,
                'client_id' => env('GOOGLE_CLIENT_ID'),
                'client_secret' => env('GOOGLE_CLIENT_SECRET'),
                'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
                'grant_type' => 'authorization_code',
            ]);
            $accessToken = $tokenResponse->json()['access_token'];

            // ดึงข้อมูล User จาก Google
            $userResponse = Http::get('https://www.googleapis.com/oauth2/v3/userinfo', [
                'access_token' => $accessToken
            ]);
            $googleUser = $userResponse->json();

            // ✅ ตรวจสอบ state ว่ามี guest_token หรือไม่ (โหมดผูกบัญชี Guest)
            $guestToken = null;
            if ($state && str_starts_with($state, 'guest_token=')) {
                $guestToken = substr($state, strlen('guest_token='));
            }

            if ($guestToken) {
                // 🔗 โหมดผูกบัญชี: ค้นหา Guest User จาก token
                $hashedToken = hash('sha256', $guestToken);
                $accessTokenRecord = \Laravel\Sanctum\PersonalAccessToken::where('token', $hashedToken)->first();

                if (!$accessTokenRecord) {
                    \Log::warning('Guest link failed: token not found', ['guest_token_hash' => $hashedToken]);
                    return redirect("mygame://auth?error=guest_not_found");
                }

                $guestUser = $accessTokenRecord->tokenable; // User model

                if (!$guestUser || !$guestUser->is_guest) {
                    \Log::warning('Guest link failed: user not found or not guest', ['user_id' => $guestUser?->id]);
                    return redirect("mygame://auth?error=guest_not_found");
                }

                // ตรวจสอบว่า Google นี้ถูกผูกกับ User อื่นแล้วหรือยัง
                $existingUser = User::where('google_id', $googleUser['sub'])->first();
                if ($existingUser && $existingUser->id !== $guestUser->id) {
                    \Log::warning('Guest link failed: Google already linked to another user', [
                        'google_id' => $googleUser['sub'],
                        'existing_user_id' => $existingUser->id
                    ]);
                    return redirect("mygame://auth?error=google_already_linked");
                }

                // ตรวจสอบว่า email นี้ถูกใช้โดย User อื่นแล้วหรือยัง
                $existingEmailUser = User::where('email', $googleUser['email'])->first();
                if ($existingEmailUser && $existingEmailUser->id !== $guestUser->id) {
                    \Log::warning('Guest link failed: email already used by another user', [
                        'email' => $googleUser['email'],
                        'existing_user_id' => $existingEmailUser->id
                    ]);
                    return redirect("mygame://auth?error=email_already_used");
                }

                // ✅ อัปเกรด Guest → ถาวร
                $guestUser->email = $googleUser['email'];
                $guestUser->google_id = $googleUser['sub'];
                $guestUser->avatar = $googleUser['picture'] ?? null;
                $guestUser->is_guest = false;
                $guestUser->save();

                \Log::info('Guest account upgraded via Google link', [
                    'user_id' => $guestUser->id,
                    'email' => $googleUser['email'],
                    'google_id' => $googleUser['sub']
                ]);

                // ออก Token ใหม่ (ลบ Token เก่าทิ้ง)
                $guestUser->tokens()->delete();
                $newToken = $guestUser->createToken('unity-game', ['*'], now()->addDays(30))->plainTextToken;

                return redirect("mygame://auth?token=" . $newToken);
            }

            // 🔵 โหมดปกติ (Login/Register)
            $user = User::where('google_id', $googleUser['sub'])->first();

            if (!$user) {
                // ยังไม่เคย Login ด้วย Google นี้ → ค้นหาด้วย Email
                $user = User::where('email', $googleUser['email'])->first();

                if ($user) {
                    // พบ User ที่ใช้อีเมลนี้อยู่แล้ว
                    if ($user->google_id) {
                        // ⚠️ อีเมลนี้ถูก Google บัญชีอื่นยึดไว้แล้ว → ป้องกันการแย่งบัญชี
                        \Log::warning('Google login blocked: email already linked to another google account', [
                            'email' => $googleUser['email'],
                            'existing_google_id' => $user->google_id,
                            'incoming_google_id' => $googleUser['sub'],
                        ]);
                        return redirect("mygame://auth?error=email_already_linked");
                    }

                    // ✅ User สมัครด้วย Email/Password มาก่อน → แค่ลิ้งค์ Google ID
                    $user->google_id = $googleUser['sub'];
                    $user->avatar = $googleUser['picture'] ?? null;
                    $user->save();

                    // ถ้ายังไม่มี GameSave (เช่น สมัครด้วย email/password) → สร้างให้
                    $existingSave = \App\Models\GameSave::where('user_id', $user->id)->first();
                    if (!$existingSave) {
                        $this->createDefaultGameSave($user);
                    }
                } else {
                    // 👤 User ใหม่ → สร้างและให้ coins/gems
                    $user = User::create([
                        'name' => $googleUser['name'] ?? 'Player',
                        'email' => $googleUser['email'],
                        'google_id' => $googleUser['sub'],
                        'avatar' => $googleUser['picture'] ?? null,
                        'password' => null,
                    ]);
                    $this->createDefaultGameSave($user);
                }
            }

            // ออก Token ใหม่
            $user->tokens()->delete();
            $token = $user->createToken('unity-game', ['*'], now()->addDays(30))->plainTextToken;

            // 🚀 ส่งกลับเข้าเกมผ่าน Deep Link
            return redirect("mygame://auth?token=" . $token);

        } catch (\Exception $e) {
            \Log::error('Google callback error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return redirect("mygame://auth?error=server_error");
        }
    }

    /**
     * Verify Google ID token from Unity WebGL
     * This endpoint is for Unity to send the ID token directly
     * Uses Google PHP Client Library for JWT verification (production-ready)
     */
    public function verifyToken(Request $request): JsonResponse
    {
        $request->validate([
            'access_token' => 'required|string',
        ]);

        try {
            $accessToken = $request->access_token; // Google Access Token จาก Unity WebGL

            \Log::info('Received Google Access Token', ['token_length' => strlen($accessToken)]);

            // ✅ ใช้ HTTP Client ดึงข้อมูล User จาก Google
            $response = Http::get('https://www.googleapis.com/oauth2/v3/userinfo', [
                'access_token' => $accessToken
            ]);

            if (!$response->successful()) {
                \Log::error('Google API Error', ['response' => $response->body()]);
                return response()->json([
                    'message' => 'Invalid Google Token'
                ], 401);
            }

            $googleUser = $response->json();

            \Log::info('Google User Info', $googleUser);

            // ดึงข้อมูล
            $googleId = $googleUser['sub'] ?? null;
            $email = $googleUser['email'] ?? null;
            $name = $googleUser['name'] ?? 'Player';
            $avatar = $googleUser['picture'] ?? 'https://lh3.googleusercontent.com/a/default-user';
            $emailVerified = $googleUser['email_verified'] ?? false;

            if (!$googleId || !$email) {
                return response()->json([
                    'message' => 'Invalid Google User Data'
                ], 400);
            }

            // ตรวจสอบว่า email ถูก verify โดย Google หรือไม่
            if (!$emailVerified) {
                \Log::warning('Google email not verified', ['email' => $email]);
                return response()->json([
                    'message' => 'Please verify your Google email before signing in'
                ], 422);
            }

            // เช็ค @gmail.com
            if (!preg_match('/@gmail\.com$/i', $email)) {
                return response()->json([
                    'message' => 'Email ต้องลงท้ายด้วย @gmail.com'
                ], 422);
            }

            // หา User หรือสร้างใหม่
            $user = User::where('google_id', $googleId)->first();

            if (!$user) {
                // ยังไม่เคย Login ด้วย Google นี้ → ค้นหาด้วย Email
                $user = User::where('email', $email)->first();

                if ($user) {
                    // พบ User ที่ใช้อีเมลนี้อยู่แล้ว
                    if ($user->google_id) {
                        // ⚠️ อีเมลนี้ถูก Google บัญชีอื่นยึดไว้แล้ว → ป้องกันการแย่งบัญชี
                        \Log::warning('Google sign-in blocked: email already linked to another google account', [
                            'email' => $email,
                            'existing_google_id' => $user->google_id,
                            'incoming_google_id' => $googleId,
                        ]);
                        return response()->json([
                            'message' => 'This email is already linked to another Google account'
                        ], 409);
                    }

                    // ✅ User สมัครด้วย Email/Password มาก่อน → แค่ลิ้งค์ Google ID
                    $user->google_id = $googleId;
                    $user->avatar = $avatar;
                    $user->save();

                    // ถ้ายังไม่มี GameSave → สร้างให้ (เช่น สมัครด้วย email/password)
                    $existingSave = \App\Models\GameSave::where('user_id', $user->id)->first();
                    if (!$existingSave) {
                        $this->createDefaultGameSave($user);
                    }

                    \Log::info('Linked Google to existing user', ['user_id' => $user->id]);
                } else {
                    // 👤 User ใหม่ → สร้างและให้ coins/gems
                    $user = User::create([
                        'name' => $name,
                        'email' => $email,
                        'google_id' => $googleId,
                        'avatar' => $avatar,
                        'password' => null,
                    ]);

                    $this->createDefaultGameSave($user);

                    \Log::info('Created new user via Google', ['user_id' => $user->id]);
                }
            }

            // ลบ Token เก่า สร้างใหม่
            $user->tokens()->delete();
            // สร้าง token ที่มีอายุ 30 วัน
            $token = $user->createToken('unity-game', ['*'], now()->addDays(30))->plainTextToken;

            return response()->json([
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar,
                ],
                'message' => 'Google Sign-In Successful'
            ]);

        } catch (\Exception $e) {
            \Log::error('Google Sign-In Exception', [
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
     * Create default game save for new user (ใช้ Single Source of Truth จาก GameSave model)
     */
    private function createDefaultGameSave(User $user): void
    {
        GameSave::createDefaultForUser($user);
    }
}