<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) habit_time_id 追加（まず nullable）
        Schema::table('remind_tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('remind_tasks', 'habit_time_id')) {
                $table->unsignedBigInteger('habit_time_id')->nullable()->after('habit_log_id');
                $table->index('habit_time_id', 'rt_habit_time_idx');
            }
        });

        // 2) 既存の remind_tasks を backfill（habit_log_id -> habit_logs.habit_time_id）
        DB::statement("
            UPDATE remind_tasks rt
            JOIN habit_logs hl ON hl.id = rt.habit_log_id
            SET rt.habit_time_id = hl.habit_time_id
            WHERE rt.habit_time_id IS NULL
              AND rt.habit_log_id IS NOT NULL
        ");

        // 3) habit_log_id を NULL許可に（A方式では新規taskでNULLにする）
        // FKがあるが NULL は許容されるのが普通。もしここで失敗するなら、FKを一旦dropしてから再実行でOK。
        DB::statement("ALTER TABLE remind_tasks MODIFY habit_log_id BIGINT(20) UNSIGNED NULL");

        // 4) 旧ユニーク（habit_log_id, remind_at）を落とす
        // あなたの環境では remind_tasks_business_unique が該当
        $indexes = DB::select("SHOW INDEX FROM remind_tasks");
        $uniqueNames = [];
        foreach ($indexes as $idx) {
            if ((int)$idx->Non_unique === 0 && $idx->Key_name !== 'PRIMARY') {
                $uniqueNames[$idx->Key_name] = true;
            }
        }
        if (isset($uniqueNames['remind_tasks_business_unique'])) {
            DB::statement("ALTER TABLE remind_tasks DROP INDEX remind_tasks_business_unique");
        }
        if (isset($uniqueNames['uq_remind_log_at'])) {
            DB::statement("ALTER TABLE remind_tasks DROP INDEX uq_remind_log_at");
        }

        // 5) habit_time_id を NOT NULL 化（NULLが残るならデータ不整合なので先に掃除）
        DB::statement("ALTER TABLE remind_tasks MODIFY habit_time_id BIGINT(20) UNSIGNED NOT NULL");

        // 6) 新ユニーク（habit_time_id, remind_at）
        DB::statement("ALTER TABLE remind_tasks ADD UNIQUE KEY remind_tasks_time_unique (habit_time_id, remind_at)");
    }

    public function down(): void
    {
        // 新ユニークを落とす
        DB::statement("ALTER TABLE remind_tasks DROP INDEX remind_tasks_time_unique");

        // 旧ユニークを戻す（互換）
        DB::statement("ALTER TABLE remind_tasks ADD UNIQUE KEY remind_tasks_business_unique (habit_log_id, remind_at)");

        // habit_log_id を NOT NULL に戻す（戻せない可能性があるので注意）
        DB::statement("ALTER TABLE remind_tasks MODIFY habit_log_id BIGINT(20) UNSIGNED NOT NULL");

        // habit_time_id を落とす
        Schema::table('remind_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('remind_tasks', 'habit_time_id')) {
                $table->dropIndex('rt_habit_time_idx');
                $table->dropColumn('habit_time_id');
            }
        });
    }
};
