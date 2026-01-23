<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\GameSave;
use App\Models\Character;

class GachaController extends Controller
{
    // Config Rates
    const RATE_SSR = 3;
    const RATE_SR = 17;
    const RATE_R = 80;
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
        // ✅ ใช้ ->data ตาม Database จริง
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

        // 2. Prepare Pools
        $rPool = Character::where('pool_type', 'Standard')->where('rarity', 'R')->get();
        $srPool = Character::where('pool_type', 'Standard')->where('rarity', 'SR')->get();

        $targetChar = Character::where('id', $targetId)->first();
        if (!$targetChar || $targetChar->pool_type !== 'Featured') {
            return response()->json(['success' => false, 'message' => 'Invalid Target'], 400);
        }

        $standardSsrPool = Character::where('pool_type', 'Standard')->where('rarity', 'SSR')->get();
        $fullSsrPool = $standardSsrPool->push($targetChar);

        $pullResults = [];
        $currentPity = $gameSave->pity_count;

        // 3. Start Rolling
        for ($i = 0; $i < $amount; $i++) {
            $currentPity++;
            $obtainedChar = null;
            $rarityRoll = null;

            if ($currentPity >= self::PITY_COUNT) {
                $obtainedChar = $targetChar;
                $rarityRoll = 'SSR';
                $currentPity = 0;
            } else {
                $rand = rand(1, 100);

                if ($rand <= self::RATE_SSR) {
                    $rarityRoll = 'SSR';
                    $obtainedChar = $fullSsrPool->random();
                    if ($obtainedChar->id === $targetChar->id) {
                        $currentPity = 0;
                    }
                } elseif ($rand <= self::RATE_SSR + self::RATE_SR) {
                    $rarityRoll = 'SR';
                    $obtainedChar = $srPool->random();
                } else {
                    $rarityRoll = 'R';
                    $obtainedChar = $rPool->random();
                }
            }

            if (!$obtainedChar)
                $obtainedChar = $targetChar;

            // Process Ownership
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
                'rarity' => $rarityRoll ?? $obtainedChar->rarity,
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