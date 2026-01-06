<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Safety gate
        $nullCount = DB::table('habit_logs')->whereNull('habit_time_id')->count();
        if ($nullCount > 0) {
            throw new RuntimeException("habit_logs.habit_time_id has {$nullCount} NULL rows. Abort.");
        }

        // If FK exists, drop → modify → re-add
        DB::statement("ALTER TABLE `habit_logs` DROP FOREIGN KEY `habit_logs_habit_time_id_foreign`");
        DB::statement("ALTER TABLE `habit_logs` MODIFY `habit_time_id` BIGINT(20) UNSIGNED NOT NULL");
        DB::statement(
            "ALTER TABLE `habit_logs`
             ADD CONSTRAINT `habit_logs_habit_time_id_foreign`
             FOREIGN KEY (`habit_time_id`) REFERENCES `habit_times`(`id`) ON DELETE CASCADE"
        );
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `habit_logs` DROP FOREIGN KEY `habit_logs_habit_time_id_foreign`");
        DB::statement("ALTER TABLE `habit_logs` MODIFY `habit_time_id` BIGINT(20) UNSIGNED NULL");
        DB::statement(
            "ALTER TABLE `habit_logs`
             ADD CONSTRAINT `habit_logs_habit_time_id_foreign`
             FOREIGN KEY (`habit_time_id`) REFERENCES `habit_times`(`id`) ON DELETE CASCADE"
        );
    }
};