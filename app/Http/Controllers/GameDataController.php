<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\GameSave;
use Illuminate\Support\Facades\Log;

class GameDataController extends Controller
{
    private $stageConfig = [
        1 => 11,
        2 => 11,
        3 => 11,
        4 => 11,
        5 => 11,
        6 => 11,
        7 => 11,
        8 => 11,
        9 => 11,
        10 => 11,
    ];

    public function save(Request $request)
    {
        $user = $request->user();
        $jsonData = $request->input('data');
        $clientSig = $request->input('sig');
        $secretKey = env('GAME_SECRET_KEY');

        // 1. ตรวจสอบลายเซ็น (Signature)
        $serverSig = md5($jsonData . $secretKey);
        if ($clientSig !== $serverSig) {
            return response()->json(['success' => false, 'message' => 'Data tampered!'], 403);
        }

        $newData = json_decode($jsonData, true);
        if (!$newData) {
            return response()->json(['success' => false, 'message' => 'Invalid JSON'], 400);
        }

        // 2. ดึงข้อมูล Save เดิม
        $save = GameSave::firstOrCreate(['user_id' => $user->id]);
        $oldData = json_decode($save->save_data, true);

        // 3. [ANTI-CHEAT] บังคับใช้เงินจาก Database เท่านั้น
        if ($oldData) {
            $newData['playerData']['gems'] = $oldData['playerData']['gems'] ?? 0;
            $newData['playerData']['coins'] = $oldData['playerData']['coins'] ?? 0;
        }

        // 4. [ANTI-CHEAT] ตรวจสอบการข้ามด่าน
        if ($oldData && isset($newData['progressData']['progressEntries'])) {
            foreach ($newData['progressData']['progressEntries'] as $entry) {
                $stageId = $entry['stageId'];
                if ($stageId > 1) {
                    $prevStageId = $stageId - 1;
                    $prevStageMaxIndex = $this->stageConfig[$prevStageId] ?? 999;
                    $prevStatus = collect($oldData['progressData']['progressEntries'] ?? [])->firstWhere('stageId', $prevStageId);
                    $highestCompleted = $prevStatus['highestSubStageCompleted'] ?? -1;

                    if ($highestCompleted < $prevStageMaxIndex) {
                        Log::warning("User {$user->id} tried to skip to Stage {$stageId}");
                        return response()->json(['success' => false, 'message' => 'Cannot skip stages!'], 403);
                    }
                }
            }
        }

        // 5. บันทึกข้อมูล
        $save->save_data = json_encode($newData);
        if (isset($newData['playerData']['currentPityCount'])) {
            $save->pity_count = $newData['playerData']['currentPityCount'];
        }
        $save->save();

        return response()->json([
            'success' => true,
            'gems' => $newData['playerData']['gems'],
            'coins' => $newData['playerData']['coins']
        ]);
    }

    public function load(Request $request)
    {
        $save = GameSave::where('user_id', $request->user()->id)->first();
        if (!$save || empty($save->save_data))
            return response()->json(['data' => null], 200);
        return response()->json(['data' => $save->save_data]);
    }
}