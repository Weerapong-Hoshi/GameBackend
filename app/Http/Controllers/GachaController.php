<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\GameSave;
use App\Models\Character;

class GachaController extends Controller
{
    // Config Rates - ปรับอัตราดรอปใหม่ให้เหมาะสมกับการไม่มีหลุดเรท 
    const RATE_SSR = 3;  // 3% (รวม Standard SSR และ Featured SSR)
    const RATE_SR = 17;  // 17%
    const RATE_R = 80;   // 80%
    const GEM_COST = 10;
    const PITY_COUNT = 40; // การันตีที่ 40

    public function pull(Request $request)
    {
        $user = $request->user();
        $amount = (int) $request->input('pullAmount', 1); // 1 หรือ 10
        $targetId = (int) $request->input('target_character_id');

        // 1. Load Data (Load JSON และ Gems)
        $gameSave = GameSave::firstOrCreate(['user_id' => $user->id]);
        $saveData = json_decode($gameSave->save_data, true);

        if (!$saveData) {
            return response()->json(['success' => false, 'message' => 'No save data found'], 400);
        }

        $currentGems = $saveData['playerData']['gems'] ?? 0;
        $totalCost = $amount * self::GEM_COST;

        if ($currentGems < $totalCost) {
            return response()->json(['success' => false, 'message' => 'Not enough gems'], 400);
        }

        // 2. Prepare Pools
        // ดึงตัวละคร Standard R, SR ทั้งหมด
        $rPool = Character::where('pool_type', 'Standard')->where('rarity', 'R')->get();
        $srPool = Character::where('pool_type', 'Standard')->where('rarity', 'SR')->get();

        // ดึงตัว Target (Featured)
        $targetChar = Character::where('id', $targetId)->first();
        if (!$targetChar || $targetChar->pool_type !== 'Featured') {
            return response()->json(['success' => false, 'message' => 'Invalid or Non-Featured Target Selected'], 400);
        }

        // ดึง Standard SSRs ทั้งหมด (ที่ไม่ใช่ Limited)
        $standardSsrPool = Character::where('pool_type', 'Standard')->where('rarity', 'SSR')->get();

        // รวม Pool SSR ทั้งหมดที่สุ่มได้ (Featured + Standard SSR)
        $fullSsrPool = $standardSsrPool->push($targetChar);
        // *หมายเหตุ: หาก Featured มีหลายตัว ต้องใช้ logic การคำนวณ rateMultiplier ตรงนี้ด้วย*

        $pullResults = [];
        $currentPity = $gameSave->pity_count;

        // 3. Start Rolling
        for ($i = 0; $i < $amount; $i++) {
            $currentPity++;
            $obtainedChar = null;
            $rarityRoll = null;

            // --- PITY CHECK (Hard Pity at 40) ---
            if ($currentPity >= self::PITY_COUNT) {
                // การันตี 100% ได้ Target
                $obtainedChar = $targetChar;
                $rarityRoll = 'SSR'; // กำหนด Rarity ให้เป็น SSR
                $currentPity = 0; // **Reset Pity เมื่อถึงการันตี**
            } else {
                // --- NORMAL ROLL ---
                $rand = rand(1, 100);

                if ($rand <= self::RATE_SSR) {
                    // ออก SSR (3%)
                    $rarityRoll = 'SSR';
                    // **[LOGIC 1: ไม่มีหลุดเรท]** สุ่มจาก Pool SSR ที่กำหนดไว้ (Featured + Standard)
                    // ใช้การสุ่มแบบถ่วงน้ำหนัก (Weighted Random) เพื่อให้ตัว Featured ออกง่ายขึ้น
                    // แต่ในตัวอย่างนี้ใช้แบบ Random ธรรมดาจาก Pool รวมก่อน
                    $obtainedChar = $fullSsrPool->random();

                    // ***[IMPORTANT]*** หากได้ Target ก่อนการันตี Pity จะไม่รีเซ็ต
                    // if ($obtainedChar->id === $targetChar->id) { $currentPity ไม่ถูก reset; } 

                } elseif ($rand <= self::RATE_SSR + self::RATE_SR) {
                    // ออก SR (17%)
                    $rarityRoll = 'SR';
                    $obtainedChar = $srPool->random();
                } else {
                    // ออก R (80%)
                    $rarityRoll = 'R';
                    $obtainedChar = $rPool->random();
                }
            }

            // 4. Fallback (ป้องกัน Error ถ้า Pool ว่าง)
            if (!$obtainedChar) {
                // ในกรณีที่ Pool ว่าง (ไม่ควรเกิดขึ้น) ให้ได้ Target Char ไปเลย
                $obtainedChar = $targetChar;
            }

            // 5. Process Character (New/Duplicate & Fallback Logic)
            $ownedChars = $saveData['playerData']['ownedCharacters'] ?? [];
            $isNew = !array_key_exists($obtainedChar->id, $ownedChars);

            if ($isNew) {
                // Add Character to SaveData (Minimal structure)
                $saveData['playerData']['ownedCharacters'][$obtainedChar->id] = [
                    'characterId' => $obtainedChar->id,
                    'level' => 1,
                    'exp' => 0,
                    'currentEvolutionStep' => 1,
                ];
                $saveData['playerData']['encounteredCharacterIds'][] = $obtainedChar->id;

                // ***[LOGIC 2: Pity ไม่รีเซ็ตเมื่อได้ Target]***
                // หากได้ Target (Featured) ก่อนการันตี 40 Pity จะยังคงนับต่อไป
                // เนื่องจากเราไม่ได้ทำการ reset $currentPity ใน step 3. เราไม่ต้องทำอะไรเพิ่มตรงนี้

            } else {
                // Duplicate: Add Fallback Material
                $matId = $obtainedChar->fallback_material_id;
                $amount = $obtainedChar->fallback_amount;

                if ($matId) { // Check if fallback material exists
                    if (!isset($saveData['playerData']['ownedMaterials'][$matId])) {
                        $saveData['playerData']['ownedMaterials'][$matId] = 0;
                    }
                    $saveData['playerData']['ownedMaterials'][$matId] += $amount;
                }
            }

            $pullResults[] = [
                'id' => $obtainedChar->id,
                'isCharacter' => true,
                'rarity' => $rarityRoll ?? $obtainedChar->rarity, // ใช้ Rarity ที่ Roll ได้
                'is_new' => $isNew,
                'fallback_id' => $obtainedChar->fallback_material_id,
                'fallback_amount' => $obtainedChar->fallback_amount
            ];
        }

        // 6. Deduct Gems & Update Save
        $saveData['playerData']['gems'] -= $totalCost;

        $gameSave->save_data = json_encode($saveData);
        $gameSave->pity_count = $currentPity; // Save Pity ที่ Server
        $gameSave->save();

        // 7. Return Response
        return response()->json([
            'success' => true,
            'results' => $pullResults,
            'newPityCount' => $currentPity,
            'remainingGems' => $saveData['playerData']['gems']
        ]);
    }
}