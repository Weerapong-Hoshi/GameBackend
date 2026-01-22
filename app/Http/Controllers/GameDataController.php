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

        if (!$save || empty($save->save_data)) {
            return response()->json([]); // ส่ง empty ให้ Unity ไปสร้าง New Game
        }

        // SaveLoadManager.cs บรรทัด 122: คาดหวัง Object หรือ Wrapper
        // เราส่ง Wrapper ไปเพื่อให้ตรงกับ Code Client
        return response()->json([
            'data' => $save->save_data
        ]);
    }
}