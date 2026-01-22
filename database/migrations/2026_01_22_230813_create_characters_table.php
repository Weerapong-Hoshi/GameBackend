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
        Schema::create('characters', function (Blueprint $table) {
            $table->id(); // ตรงกับ ID ใน Unity
            $table->string('name');
            $table->string('rarity'); // R, SR, SSR
            $table->string('pool_type'); // Standard, Featured, Limited
            $table->float('rate_multiplier')->default(1.0); // ตัวคูณเรท
            $table->integer('fallback_material_id')->nullable();
            $table->integer('fallback_amount')->default(10);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('characters');
    }
};
