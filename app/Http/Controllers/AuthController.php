<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\GameSave;
use Illuminate\Http\Request;
use Carbon\Carbon; 
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use App\Mail\ResetPasswordMail;

class AuthController extends Controller
{
    // =======================================================
    // 1. REGISTER
    // =======================================================
    public function register(Request $request)
    {
        try {
            // 1. Validation และบังคับใช้ @gmail.com
            $request->validate([
                'email' => [
                    'required',
                    'email',
                    'unique:users',
                    'regex:/@gmail\.com$/i'
                ],
                'password' => 'required|min:6'
            ], [
                'email.regex' => 'Email ต้องลงท้ายด้วย @gmail.com',
                'email.unique' => 'พบบัญชีซ้ำในระบบ',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->errors()[array_key_first($e->errors())][0]
            ], 422);
        }

        $user = User::create([
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'name' => 'Unknown', // Default Name
        ]);

        // สร้าง Save เปล่าเตรียมไว้
        GameSave::create(['user_id' => $user->id]);

        $token = $user->createToken('unity-game')->plainTextToken;

        return response()->json(['token' => $token, 'message' => 'Register Successful']);
    }

    // =======================================================
    // 2. LOGIN
    // =======================================================
    public function login(Request $request)
    {
        // 1. เช็คว่ามี User ในระบบหรือไม่
        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json(['message' => 'ไม่พบบัญชีนี้'], 404);
        }

        // 2. ถ้ามี User ค่อยลอง Login
        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json(['message' => 'รหัสผ่านไม่ถูกต้อง'], 401);
        }

        // 3. Login สำเร็จ
        $user->tokens()->delete();
        $token = $user->createToken('unity-game')->plainTextToken;

        return response()->json(['token' => $token, 'message' => 'Login Successful']);
    }

    public function user(Request $request)
    {
        return response()->json($request->user());
    }

    // --- Password Reset Logic (Simplified for Unity) ---

    public function sendResetEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $user = User::where('email', $request->email)->first();

        // สร้างรหัส 6 หลัก
        $code = rand(100000, 999999);

        DB::table('password_reset_codes')->updateOrInsert(
            ['email' => $user->email],
            [
                'code' => $code,
                'created_at' => Carbon::now()
            ]
        );

        Mail::to($user->email)->send(new ResetPasswordMail($code));

        return response()->json([
            'status' => 'success',
            'message' => 'Code sent'
        ], 200);
    }

    public function verifyResetCode(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required'
        ]);

        $record = DB::table('password_reset_codes')
            ->where('email', $request->email)
            ->where('code', $request->code)
            ->first();

        if (!$record) {
            return response()->json(['message' => 'Invalid code'], 400);
        }

        return response()->json(['message' => 'Code verified'], 200);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required',
            'password' => 'required|min:6'
        ]);

        $record = DB::table('password_reset_codes')
            ->where('email', $request->email)
            ->where('code', $request->code)
            ->first();

        if (!$record) {
            return response()->json(['message' => 'Invalid code'], 400);
        }

        $user = User::where('email', $request->email)->first();
        $user->password = Hash::make($request->password);
        $user->save();

        DB::table('password_reset_codes')->where('email', $request->email)->delete();

        return response()->json(['message' => 'Password reset successful'], 200);
    }
}