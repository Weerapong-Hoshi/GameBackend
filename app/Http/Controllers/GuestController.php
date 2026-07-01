<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\GameSave;
use App\Http\Requests\GuestStoreRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GuestController extends Controller
{
    /**
     * POST /api/guest
     * 
     * Create a new guest user with a dummy email and return a Bearer token.
     */
    public function store(GuestStoreRequest $request): JsonResponse
    {
        try {
            $user = DB::transaction(function () use ($request) {
                // Generate unique dummy email for guest
                $dummyEmail = 'guest_' . uniqid() . '@yourgame.local';
                $dummyPassword = bin2hex(random_bytes(16));

                $user = User::create([
                    'name' => $request->name,
                    'email' => $dummyEmail,
                    'password' => Hash::make($dummyPassword),
                    'is_guest' => true,
                ]);

                // Create default game save data (ใช้ Single Source of Truth จาก GameSave model)
                GameSave::createDefaultForUser($user);

                return $user;
            });

            // Generate Sanctum token with 30-day expiration
            $token = $user->createToken('unity-game', ['*'], now()->addDays(30))->plainTextToken;

            return response()->json(['token' => $token], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create guest account: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/guest/link
     * 
     * Link a guest account to a real email/password.
     * Requires Bearer token of the guest user.
     * Revokes old tokens and issues a new one.
     */
    public function link(Request $request): JsonResponse
    {
        try {
            // Validate input
            $request->validate([
                'email' => 'required|email|unique:users,email',
                'password' => 'required|string|min:6',
            ]);

            $user = $request->user();

            // Ensure the account is a guest account
            if (!$user->is_guest) {
                return response()->json([
                    'message' => 'This account is not a guest account.'
                ], 400);
            }

            DB::transaction(function () use ($user, $request) {
                // Update user with real credentials
                $user->email = $request->email;
                $user->password = Hash::make($request->password);
                $user->is_guest = false;
                $user->save();
            });

            // Revoke all old tokens
            $user->tokens()->delete();

            // Issue a new token with 30-day expiration
            $newToken = $user->createToken('unity-game', ['*'], now()->addDays(30))->plainTextToken;

            return response()->json(['token' => $newToken], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->errors()[array_key_first($e->errors())][0]
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to link account: ' . $e->getMessage()
            ], 500);
        }
    }
}