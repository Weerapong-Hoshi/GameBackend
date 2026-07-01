<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GameSave extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'data',
        'pity_count'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * ค่าเริ่มต้นของ Save Data สำหรับผู้เล่นใหม่ (Single Source of Truth)
     * ทุกจุดที่ต้องการสร้าง save data ใหม่ ต้องเรียกใช้ method นี้เท่านั้น!
     */
    public static function defaultSaveData(): array
    {
        return [
            'allPresets' => [],
            'progressData' => ['progressEntries' => []],
            'playerData' => [
                'playerName' => 'นักผจญภัย',
                'coins' => 5000,
                'gems' => 100,
                'playerRank' => 1,
                'currentExp' => 0,
                'expToNextRank' => 100,
                'maxTeamCost' => 50,
                'currentEnergy' => 240,
                'lastEnergyUpdateTime' => now()->timestamp,
                'isTutorialCompleted' => false,
                'tutorialPhaseIndex' => 0,
                'gachaPityCounters' => new \stdClass(),
                'usedRedeemCodes' => [],
                'dailyShopPurchases' => new \stdClass(),
                'lastShopResetDate' => now()->format('Y-m-d'),
                'ownedCharacters' => new \stdClass(),
                'ownedMaterials' => new \stdClass(),
                'encounteredCharacterIds' => [],
            ],
            'questData' => ['questProgress' => new \stdClass()]
        ];
    }

    /**
     * สร้าง GameSave เริ่มต้นสำหรับ User ใหม่
     */
    public static function createDefaultForUser(User $user): self
    {
        return self::create([
            'user_id' => $user->id,
            'data' => json_encode(self::defaultSaveData(), JSON_UNESCAPED_UNICODE),
            'pity_count' => 0,
        ]);
    }
}
