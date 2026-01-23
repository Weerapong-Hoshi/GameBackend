<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GameDataController extends Controller
{
    // Helper แปลงเป็น Object (เพื่อไม่ให้ Unity ได้ Array ว่าง [] ในจุดที่ควรเป็น Dictionary {})
    private function asObject($value)
    {
        if (is_array($value))
            return (object) $value;
        return $value === null ? new \stdClass() : $value;
    }

    // Helper แปลงเป็น Array List
    private function asArray($value)
    {
        if (is_array($value))
            return array_values($value);
        return [];
    }

    // =========================================================================
    // 1. LOAD: โหลดข้อมูล และบังคับค่าให้ถูกต้อง
    // =========================================================================
    public function load(Request $request)
    {
        $user = $request->user();
        $save = $user->gameSave;

        // ถ้าไม่มี Save ให้แจ้ง Error หรือ Return โครงสร้างว่าง (แต่ควรมีจากการ Register)
        if (!$save || !$save->data) {
            return response()->json([
                'success' => false,
                'message' => 'Save data not found',
                'data' => null
            ]);
        }

        $data = json_decode($save->data, true) ?? [];

        // แตก Array ย่อยออกมาเพื่อความอ่านง่าย
        $playerData = $data['playerData'] ?? [];
        $questData = $data['questData'] ?? [];
        $progressData = $data['progressData'] ?? [];

        // ⭐ จัดโครงสร้างข้อมูลใหม่ ให้มั่นใจว่ามี Key ครบ และ Type ถูกต้อง
        $fixedData = [
            'playerData' => [
                'playerName' => $playerData['playerName'] ?? 'Unknown',

                // ⭐ สำคัญ: บังคับเป็น (int) เพื่อให้ JSON ออกมาเป็นตัวเลข 100 ไม่ใช่ "100"
                'playerRank' => (int) ($playerData['playerRank'] ?? 1),
                'coins' => (int) ($playerData['coins'] ?? 0),
                'gems' => (int) ($playerData['gems'] ?? 0),
                'currentExp' => (int) ($playerData['currentExp'] ?? 0),
                'expToNextRank' => (int) ($playerData['expToNextRank'] ?? 100),
                'maxTeamCost' => (int) ($playerData['maxTeamCost'] ?? 50),

                // Energy
                'currentEnergy' => (int) ($playerData['currentEnergy'] ?? 240),
                'lastEnergyUpdateTime' => $playerData['lastEnergyUpdateTime'] ?? now()->timestamp,

                // Collections (ใช้ Helper เพื่อป้องกัน [] แทน {})
                'ownedCharacters' => $this->asObject($playerData['ownedCharacters'] ?? null),
                'ownedMaterials' => $this->asObject($playerData['ownedMaterials'] ?? null),
                'encounteredCharacterIds' => $this->asArray($playerData['encounteredCharacterIds'] ?? []),
                'usedRedeemCodes' => $this->asArray($playerData['usedRedeemCodes'] ?? []),

                // Gacha & Shop
                'gachaPityCounters' => $this->asObject($playerData['gachaPityCounters'] ?? null),
                'dailyShopPurchases' => $this->asObject($playerData['dailyShopPurchases'] ?? null),
                'lastShopResetDate' => $playerData['lastShopResetDate'] ?? now()->format('Y-m-d'),
            ],
            'questData' => [
                'questProgress' => $this->asObject($questData['questProgress'] ?? null)
            ],
            'progressData' => [
                'progressEntries' => $this->asArray($progressData['progressEntries'] ?? [])
            ],
            'allPresets' => $this->asArray($data['allPresets'] ?? [])
        ];

        return response()->json([
            'success' => true,
            'data' => $fixedData, // ส่งข้อมูลที่ Clean แล้วกลับไป
            'pity_count' => $save->pity_count
        ]);
    }

    // =========================================================================
    // 2. SAVE: บันทึก และ ส่งค่าล่าสุดกลับไปยืนยันกับ Unity
    // =========================================================================
    public function save(Request $request)
    {
        $user = $request->user();

        // รับ Data
        $rawData = $request->input('data');

        // แปลง JSON String เป็น Array (ถ้ามาเป็น String)
        if (is_string($rawData)) {
            $decodedData = json_decode($rawData, true);
        } else {
            $decodedData = $rawData;
        }

        if (!$decodedData) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid JSON Format'
            ], 400);
        }

        $newPlayerName = $decodedData['playerData']['playerName'] ?? null;
        $defaultPlaceholders = ['นักผจญภัย', 'New Player', 'Unknown', 'Player']; // ชื่อเริ่มต้นทั้งหมด

        // อัปเดตตาราง users.name ก็ต่อเมื่อชื่อที่ส่งมาไม่ใช่ชื่อเริ่มต้น และชื่อมีการเปลี่ยนแปลง
        if ($newPlayerName && !in_array($newPlayerName, $defaultPlaceholders) && $user->name !== $newPlayerName) {
            $user->name = $newPlayerName;
            $user->save();
            \Log::info("User {$user->id} name updated to: {$newPlayerName}"); // Debug Log
        }

        // 1. บันทึกลง Database
        // ใช้ updateOrCreate เพื่อรองรับทั้ง User เก่าและใหม่
        $gameSave = $user->gameSave()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'data' => json_encode($decodedData, JSON_UNESCAPED_UNICODE),
                'pity_count' => $request->input('pity_count', 0)
            ]
        );

        // 2. ⭐ หัวใจสำคัญ: อ่านค่าที่เพิ่งเซฟลงไป เพื่อส่งกลับให้ Unity
        // การทำแบบนี้คือการยืนยันว่า Server รับทราบยอดเงินนี้แล้วจริงๆ
        $savedData = json_decode($gameSave->data, true);

        $confirmedCoins = (int) ($savedData['playerData']['coins'] ?? 0);
        $confirmedGems = (int) ($savedData['playerData']['gems'] ?? 0);

        return response()->json([
            'success' => true,
            'message' => 'Save Synced',
            // ส่งค่ากลับไปเพื่อให้ SaveLoadManager ใน Unity อัปเดต Display
            'coins' => $confirmedCoins,
            'gems' => $confirmedGems
        ]);
    }
}