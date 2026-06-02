<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\GameSave;

class GameDataController extends Controller
{
    // Helper Functions
    private function asObject($value)
    {
        if (is_array($value))
            return (object) $value;
        return $value === null ? new \stdClass() : $value;
    }

    private function asArray($value)
    {
        if (is_array($value))
            return array_values($value);
        return [];
    }

    // =========================================================================
    // 1. LOAD (ปรับปรุง Error Handling)
    // =========================================================================
    public function load(Request $request)
    {
        try {
            $user = $request->user();
            $save = $user->gameSave;

            // ✅ ตรวจสอบและสร้าง Save Data สำรองหากไม่มี
            if (!$save || !$save->data) {
                \Log::warning("User {$user->id} ({$user->email}) has no save data. Creating default save with starting resources.");
                
                // สร้าง Save Data เริ่มต้น (เหมือนตอน Register)
                $defaultSaveData = [
                    'allPresets' => [],
                    'progressData' => ['progressEntries' => []],
                    'playerData' => [
                        'playerName' => 'นักผจญภัย',
                        'coins' => 5000, // เงินเริ่มต้น
                        'gems' => 100,   // เพชรเริ่มต้น
                        'playerRank' => 1,
                        'currentExp' => 0,
                        'expToNextRank' => 100,
                        'maxTeamCost' => 50,
                        'currentEnergy' => 240,
                        'lastEnergyUpdateTime' => now()->timestamp,
                        'isTutorialCompleted' => false,
                        'tutorialPhaseIndex'  => 0,
                        'ownedCharacters' => new \stdClass(),
                        'ownedMaterials' => new \stdClass(),
                        'encounteredCharacterIds' => [],
                        'usedRedeemCodes' => [],
                        'gachaPityCounters' => new \stdClass(),
                        'dailyShopPurchases' => new \stdClass(),
                        'lastShopResetDate' => now()->format('Y-m-d'),
                    ],
                    'questData' => ['questProgress' => new \stdClass()]
                ];

                // สร้าง GameSave ใหม่
                $save = GameSave::create([
                    'user_id' => $user->id,
                    'data' => json_encode($defaultSaveData, JSON_UNESCAPED_UNICODE),
                    'pity_count' => 0
                ]);

                \Log::info("Created default save for user {$user->id} with starting resources: 5000 coins, 100 gems.");
            }

            // ✅ เพิ่ม try-catch สำหรับ JSON decode
            try {
                $data = json_decode($save->data, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('Invalid JSON: ' . json_last_error_msg());
                }
            } catch (\Exception $e) {
                Log::error("JSON Decode Error for User {$user->id}: " . $e->getMessage());

                return response()->json([
                    'success' => false,
                    'message' => 'Corrupted save data',
                    'data' => null
                ], 500);
            }

            // เติมค่า difficulty ให้ผู้เล่นเก่า
            $progressData = $data['progressData'] ?? [];
            $entries = $progressData['progressEntries'] ?? [];

            foreach ($entries as $key => $entry) {
                if (!isset($entry['difficulty'])) {
                    $entries[$key]['difficulty'] = 0;
                }

                // ✅ บังคับเป็น int
                $entries[$key]['stageId'] = (int) ($entry['stageId'] ?? 0);
                $entries[$key]['difficulty'] = (int) ($entry['difficulty'] ?? 0);
                $entries[$key]['highestSubStageCompleted'] = (int) ($entry['highestSubStageCompleted'] ?? -1);
            }

            $playerData = $data['playerData'] ?? [];
            $questData = $data['questData'] ?? [];

            // ✅ ปรับโครงสร้างให้สมบูรณ์
            $fixedData = [
                'playerData' => [
                    'playerName' => $playerData['playerName'] ?? 'Unknown',
                    'playerRank' => (int) ($playerData['playerRank'] ?? 1),
                    'coins' => (int) ($playerData['coins'] ?? 0),
                    'gems' => (int) ($playerData['gems'] ?? 0),
                    'currentExp' => (int) ($playerData['currentExp'] ?? 0),
                    'expToNextRank' => (int) ($playerData['expToNextRank'] ?? 100),
                    'maxTeamCost' => (int) ($playerData['maxTeamCost'] ?? 50),
                    'currentEnergy' => (int) ($playerData['currentEnergy'] ?? 240),
                    'lastEnergyUpdateTime' => $playerData['lastEnergyUpdateTime'] ?? now()->timestamp,
                    'isTutorialCompleted' => (bool) ($playerData['isTutorialCompleted'] ?? false),
                    'tutorialPhaseIndex'  => (int)  ($playerData['tutorialPhaseIndex']  ?? 0),

                    'ownedCharacters' => $this->asObject($playerData['ownedCharacters'] ?? null),
                    'ownedMaterials' => $this->asObject($playerData['ownedMaterials'] ?? null),
                    'encounteredCharacterIds' => $this->asArray($playerData['encounteredCharacterIds'] ?? []),
                    'usedRedeemCodes' => $this->asArray($playerData['usedRedeemCodes'] ?? []),

                    'gachaPityCounters' => $this->asObject($playerData['gachaPityCounters'] ?? null),
                    'dailyShopPurchases' => $this->asObject($playerData['dailyShopPurchases'] ?? null),
                    'lastShopResetDate' => $playerData['lastShopResetDate'] ?? now()->format('Y-m-d'),
                ],
                'questData' => [
                    'questProgress' => $this->asObject($questData['questProgress'] ?? null)
                ],
                'progressData' => [
                    'progressEntries' => $this->asArray($entries)
                ],
                'allPresets' => $this->asArray($data['allPresets'] ?? [])
            ];

            return response()->json([
                'success' => true,
                'data' => $fixedData,
                'pity_count' => (int) $save->pity_count
            ]);

        } catch (\Exception $e) {
            Log::error("Load Error: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Server error',
                'data' => null
            ], 500);
        }
    }

    // =========================================================================
    // 2. SAVE (แก้ไข Race Condition + Validation)
    // =========================================================================
    public function save(Request $request)
    {
        // ✅ ใช้ Transaction เพื่อป้องกัน Race Condition
        return DB::transaction(function () use ($request) {
            try {
                $user = $request->user();
                $rawData = $request->input('data');

                // ✅ Validate JSON
                if (is_string($rawData)) {
                    $decodedData = json_decode($rawData, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Invalid JSON: ' . json_last_error_msg()
                        ], 400);
                    }
                } else {
                    $decodedData = $rawData;
                }

                if (!$decodedData || !isset($decodedData['playerData'])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid data structure'
                    ], 400);
                }

                // ✅ Validate ค่าเงิน (ป้องกัน Negative)
                $coins = (int) ($decodedData['playerData']['coins'] ?? 0);
                $gems = (int) ($decodedData['playerData']['gems'] ?? 0);

                if ($coins < 0 || $gems < 0) {
                    Log::warning("User {$user->id} attempted negative currency. Coins: {$coins}, Gems: {$gems}");

                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid currency values'
                    ], 400);
                }

                // อัปเดต Username (ถ้าจำเป็น)
                $newPlayerName = $decodedData['playerData']['playerName'] ?? null;
                $defaultPlaceholders = ['นักผจญภัย', 'New Player', 'Unknown', 'Player'];

                if (
                    $newPlayerName &&
                    !in_array($newPlayerName, $defaultPlaceholders) &&
                    $user->name !== $newPlayerName
                ) {
                    $user->name = $newPlayerName;
                    $user->save();
                }

                // ✅ Lock Row ขณะ Update (ป้องกันเขียนทับ)
                $gameSave = $user->gameSave()->lockForUpdate()->first();

                if (!$gameSave) {
                    $gameSave = $user->gameSave()->create([
                        'data' => '{}',
                        'pity_count' => 0
                    ]);
                }

                // บันทึก
                $gameSave->data = json_encode($decodedData, JSON_UNESCAPED_UNICODE);
                $gameSave->pity_count = $request->input('pity_count', $gameSave->pity_count);
                $gameSave->save();

                // ✅ อ่านค่ากลับมาจาก DB เพื่อยืนยัน
                $savedData = json_decode($gameSave->fresh()->data, true);
                $confirmedCoins = (int) ($savedData['playerData']['coins'] ?? 0);
                $confirmedGems = (int) ($savedData['playerData']['gems'] ?? 0);

                return response()->json([
                    'success' => true,
                    'message' => 'Save synced',
                    'coins' => $confirmedCoins,
                    'gems' => $confirmedGems
                ]);

            } catch (\Exception $e) {
                Log::error("Save Error for User {$request->user()->id}: " . $e->getMessage());

                return response()->json([
                    'success' => false,
                    'message' => 'Save failed'
                ], 500);
            }
        });
    }
}