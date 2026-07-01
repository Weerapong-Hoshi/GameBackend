<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\GameSave;
use App\Models\Character;

class GachaController extends Controller
{
    // Config Rates
    const GEM_COST = 10;
    const PITY_COUNT = 40;

    public function pull(Request $request)
    {
        $user = $request->user();

        $amount = (int) $request->input('pullAmount');
        if (!in_array($amount, [1, 10])) {
            return response()->json(['success' => false, 'message' => 'Invalid pull amount'], 400);
        }

        $targetId = (int) $request->input('target_character_id');

        // 1. Load Data
        $gameSave = GameSave::firstOrCreate(['user_id' => $user->id]);
        $saveData = json_decode($gameSave->data, true);

        if (!$saveData) {
            return response()->json(['success' => false, 'message' => 'No save data found'], 400);
        }

        $currentGems = $saveData['playerData']['gems'] ?? 0;
        $totalCost = $amount * self::GEM_COST;

        if ($currentGems < $totalCost) {
            return response()->json(['success' => false, 'message' => 'Not enough gems'], 400);
        }

        // ตรวจสอบว่ามีตัวละครเป้าหมาย (Featured) ที่เลือกจาก Unity จริงไหม
        $targetChar = Character::where('id', $targetId)->first();
        if (!$targetChar) {
            return response()->json(['success' => false, 'message' => 'Invalid Target'], 400);
        }

        // 2. Prepare Pools (ดึงตัวละครทั้งหมดใน Database มารวมกันเพื่อป้องกัน Pool ว่างแล้วล่ม)
        $basePool = Character::all();

        if ($basePool->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'No characters available in database'], 500);
        }

        $pullResults = [];
        $currentPity = $gameSave->pity_count;

        // 3. Start Rolling
        for ($i = 0; $i < $amount; $i++) {
            $currentPity++;
            $obtainedChar = null;

            // ระบบการันตี Pity (เมื่อกดครบตามกำหนด ให้ได้ตัวเลือกจาก Unity ทันที)
            if ($currentPity >= self::PITY_COUNT) {
                $obtainedChar = $targetChar;
                $currentPity = 0;
            } else {
                // คลอนข้อมูล Pool ทั้งหมดมาทำกล่องสุ่มชั่วคราว
                $tempPool = clone $basePool;

                // 🌟 [RATE UP LOGIC] ยัดตัวละครที่เลือกเพิ่มเข้าไปในกล่องสุ่ม 5 สิทธิ์เพื่อเพิ่มโอกาสออก (ปรับเพิ่ม/ลดได้ตามใจชอบ)
                for ($j = 0; $j < 5; $j++) {
                    $tempPool->push($targetChar);
                }

                // สุ่มหยิบจากกล่องรวม (ได้ตัวไหนก็ได้ตัวนั้น ไม่มีทาง Error 0 items)
                $obtainedChar = $tempPool->random();

                // ถ้าสุ่มได้ตัวหน้าตู้พอดี ให้รีเซ็ตค่า Pity คืนเป็น 0
                if ($obtainedChar->id === $targetChar->id) {
                    $currentPity = 0;
                }
            }

            // ใช้ Rarity จริงของตัวละครตัวนั้นไปแสดงผล
            $rarityRoll = $obtainedChar->rarity;

            // Process Ownership (ระบบบันทึกข้อมูลตัวละครของเดิม)
            $ownedChars = $saveData['playerData']['ownedCharacters'] ?? [];
            $isNew = !array_key_exists($obtainedChar->id, $ownedChars);

            if ($isNew) {
                $saveData['playerData']['ownedCharacters'][$obtainedChar->id] = [
                    'characterId' => $obtainedChar->id,
                    'level' => 1,
                    'exp' => 0,
                    'currentEvolutionStep' => 1,
                ];
                $saveData['playerData']['encounteredCharacterIds'][] = $obtainedChar->id;
            } else {
                $matId = $obtainedChar->fallback_material_id;
                $fallbackAmount = $obtainedChar->fallback_amount;

                if ($matId) {
                    if (!isset($saveData['playerData']['ownedMaterials']) || empty($saveData['playerData']['ownedMaterials'])) {
                        $saveData['playerData']['ownedMaterials'] = [];
                    }
                    if (!isset($saveData['playerData']['ownedMaterials'][$matId])) {
                        $saveData['playerData']['ownedMaterials'][$matId] = 0;
                    }
                    $saveData['playerData']['ownedMaterials'][$matId] += $fallbackAmount;
                }
            }

            $pullResults[] = [
                'id' => $obtainedChar->id,
                'isCharacter' => true,
                'rarity' => $rarityRoll,
                'is_new' => $isNew,
                'fallback_id' => $obtainedChar->fallback_material_id,
                'fallback_amount' => $obtainedChar->fallback_amount
            ];
        }

        // ตัดเพชร
        $saveData['playerData']['gems'] -= $totalCost;

        // Update Pity
        $gameSave->pity_count = $currentPity;

        // แก้ไข Dictionary ว่างก่อน Save (Material)
        if (
            !isset($saveData['playerData']['ownedMaterials']) ||
            (is_array($saveData['playerData']['ownedMaterials']) && empty($saveData['playerData']['ownedMaterials']))
        ) {
            $saveData['playerData']['ownedMaterials'] = new \stdClass();
        }

        // แก้ไข Dictionary ว่างก่อน Save (Characters)
        if (
            !isset($saveData['playerData']['ownedCharacters']) ||
            (is_array($saveData['playerData']['ownedCharacters']) && empty($saveData['playerData']['ownedCharacters']))
        ) {
            $saveData['playerData']['ownedCharacters'] = new \stdClass();
        }

        $gameSave->data = json_encode($saveData, JSON_UNESCAPED_UNICODE);
        $gameSave->save();

        return response()->json([
            'success' => true,
            'results' => $pullResults,
            'newPityCount' => $currentPity,
            'remainingGems' => $saveData['playerData']['gems']
        ]);
    }
}