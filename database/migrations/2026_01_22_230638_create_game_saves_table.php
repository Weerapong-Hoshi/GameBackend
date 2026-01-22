<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('game_saves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->longText('save_data')->nullable(); // เก็บ JSON ก้อนใหญ่จาก Unity
            $table->integer('pity_count')->default(0); // เก็บ Pity แยกไว้ที่ Server เพื่อความชัวร์
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_saves');
    }
};
