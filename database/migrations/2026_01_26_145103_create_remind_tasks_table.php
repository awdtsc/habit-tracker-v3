<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ★ v2 DB引き継ぎ環境では何もしない（安全）
        if (Schema::hasTable('remind_tasks')) {
            return;
        }

        Schema::create('remind_tasks', function (Blueprint $table) {
            $table->id();

            // まずは最低限（後続migrationで拡張される前提）
            $table->dateTime('remind_at')->index();
            $table->string('status', 32)->default('pending')->index();

            // 運用上よく使う（無くても後続で足せるが、初期に置いて害が少ない）
            $table->dateTime('sent_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remind_tasks');
    }
};