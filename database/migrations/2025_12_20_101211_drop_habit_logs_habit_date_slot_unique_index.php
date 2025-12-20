<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('habit_logs', function (Blueprint $table) {
            // 余計な UNIQUE（user_id が含まれない）を削除
            // SHOW INDEX で見えていた name: habit_logs_habit_date_slot_unique
            $table->dropUnique('habit_logs_habit_date_slot_unique');
        });
    }

    public function down(): void
    {
        Schema::table('habit_logs', function (Blueprint $table) {
            // 戻す場合（元の UNIQUE を復元）
            $table->unique(['habit_id', 'date', 'time_slot'], 'habit_logs_habit_date_slot_unique');
        });
    }
};
