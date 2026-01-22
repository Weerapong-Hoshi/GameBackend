<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\GameSave;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'email' => 'required|email|unique:users',
            'password' => 'required|min:6'
        ]);

        $user = User::create([
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'name' => 'Player',
        ]);

        // สร้าง Save เปล่าเตรียมไว้
        GameSave::create(['user_id' => $user->id]);

        $token = $user->createToken('unity-game')->plainTextToken;

        return response()->json(['token' => $token, 'message' => 'Register Successful']);
    }

    public function login(Request $request)
    {
        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $user = User::where('email', $request->email)->first();
        $user->tokens()->delete(); // ลบ Token เก่า (Optional)
        $token = $user->createToken('unity-game')->plainTextToken;

        return response()->json(['token' => $token, 'message' => 'Login Successful']);
    }

    public function user(Request $request)
    {
        return response()->json($request->user());
    }

    // --- Password Reset Logic (Simplified for Unity) ---

    public function sendResetCode(Request $request)
    {
        $user = User::where('email', $request->email)->first();
        if (!$user)
            return response()->json(['message' => 'email_not_found'], 404);

        $code = rand(100000, 999999);
        // ในระบบจริงควรเก็บใน Table password_reset_tokens
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $request->email],
            ['token' => $code, 'created_at' => now()]
        );

        // TODO: Send Email Here (Mail::to($user)->send(...));
        // เพื่อการทดสอบ ให้ Return Code กลับไปเลย (Production ห้ามทำ)
        return response()->json(['message' => 'Code sent', 'debug_code' => $code]);
    }

    public function verifyResetCode(Request $request)
    {
        $record = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->where('token', $request->code)
            ->first();

        if ($record)
            return response()->json(['message' => 'Code Valid']);
        return response()->json(['message' => 'Invalid Code'], 400);
    }

    public function resetPassword(Request $request)
    {
        $record = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->where('token', $request->code)
            ->first();

        if (!$record)
            return response()->json(['message' => 'Invalid Request'], 400);

        User::where('email', $request->email)->update([
            'password' => Hash::make($request->password)
        ]);

        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return response()->json(['message' => 'Password Changed']);
    }
}