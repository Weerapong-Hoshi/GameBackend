<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\GameSave;

class GameDataController extends Controller
{
    public function save(Request $request)
    {
        // Unity ส่งมาเป็น form-data field ชื่อ "data" (string json)
        $jsonData = $request->input('data');

        $save = GameSave::firstOrCreate(['user_id' => $request->user()->id]);
        $save->save_data = $jsonData;
        $save->save();

        return response()->json(['success' => true]);
    }

    public function load(Request $request)
{
    $save = GameSave::where('user_id', $request->user()->id)->first();

    // 1. ตรวจสอบว่ามีข้อมูลใน Database หรือไม่
    if (!$save || empty($save->save_data)) {
        // ส่ง response เป็น null (หรือ HTTP 204 No Content)
        // เพื่อให้ Unity (SaveLoadManager.cs) เข้าเงื่อนไข onComplete?.Invoke(null);
        return response(null, 200); // 200 OK with null body is generally safe
    }

    // 2. ถ้ามีข้อมูล
    // เรายังคงต้องส่งในรูปแบบ Wrapper ที่ Unity คาดหวัง
    return response()->json([
        'data' => $save->save_data
    ]);
}
}