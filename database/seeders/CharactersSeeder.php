<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class CharactersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // 1. กำหนด Path ของไฟล์ JSON
        $jsonPath = database_path('data/gacha_config.json');

        if (!File::exists($jsonPath)) {
            $this->command->error("❌ Error: gacha_config.json not found at $jsonPath");
            return;
        }

        // 2. อ่านและ Decode ข้อมูล JSON
        $json = File::get($jsonPath);
        $data = json_decode($json, true);

        // ตรวจสอบโครงสร้างว่ามี key 'characters' หรือไม่
        if (!isset($data['characters']) || !is_array($data['characters'])) {
            $this->command->error("❌ Error: JSON structure is invalid. Missing 'characters' array.");
            return;
        }

        // 3. เตรียมข้อมูลสำหรับ Insert
        $charactersToInsert = [];

        foreach ($data['characters'] as $char) {
            // Mapping ข้อมูลจาก JSON สู่ Database Column
            $charactersToInsert[] = [
                'id' => $char['id'],
                'name' => $char['characterName'],
                'rarity' => $char['rarity'],
                'pool_type' => $char['poolType'],
                // rateMultiplier ไม่ได้ถูกใช้โดยตรงใน GachaController แต่เก็บไว้ใน DB ก็ได้
                'rate_multiplier' => $char['rateMultiplier'],
                'fallback_material_id' => $char['fallbackMaterialId'],
                'fallback_amount' => $char['fallbackAmount'],
                'created_at' => now(), // เพิ่ม Timestamp
                'updated_at' => now(), // เพิ่ม Timestamp
            ];
        }

        // 4. ล้างข้อมูลเก่า
        DB::table('characters')->truncate();

        // 5. Insert ข้อมูลใหม่
        // ใช้ insert แทน upsert เพราะเราทำการ truncate ไปแล้ว
        DB::table('characters')->insert($charactersToInsert);

        $this->command->info("✅ Successfully seeded " . count($charactersToInsert) . " characters from JSON.");
    }
}