<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('remind_tasks')) {
            return;
        }

        // 現在のindex名を取得（存在確認用）
        $indexes = DB::select("SHOW INDEX FROM remind_tasks");
        $indexNames = array_unique(array_map(static fn ($idx) => $idx->Key_name, $indexes));

        // 1) habit_time_id 追加（まず nullable）
        Schema::table('remind_tasks', function (Blueprint $table) use ($indexNames) {
            if (!Schema::hasColumn('remind_tasks', 'habit_time_id')) {
                // fresh/variant schema 対策：after対象が無いと落ちるので安全に決める
                $after = Schema::hasColumn('remind_tasks', 'habit_log_id') ? 'habit_log_id' : 'id';
                $table->unsignedBigInteger('habit_time_id')->nullable()->after($after);

                // indexは重複作成で落ちるので存在確認
                if (!in_array('rt_habit_time_idx', $indexNames, true)) {
                    $table->index('habit_time_id', 'rt_habit_time_idx');
                }
            }
        });

        // 2) backfill（habit_log_id -> habit_logs.habit_time_id）
        if (
            Schema::hasTable('habit_logs')
            && Schema::hasColumn('remind_tasks', 'habit_log_id')
            && Schema::hasColumn('habit_logs', 'habit_time_id')
            && Schema::hasColumn('remind_tasks', 'habit_time_id')
        ) {
            DB::statement("
                UPDATE remind_tasks rt
                JOIN habit_logs hl ON hl.id = rt.habit_log_id
                SET rt.habit_time_id = hl.habit_time_id
                WHERE rt.habit_time_id IS NULL
                  AND rt.habit_log_id IS NOT NULL
            ");
        }

        // 3) habit_log_id を NULL許可に（運用上：新規taskはhabit_log_id NULLを許容）
        if (Schema::hasColumn('remind_tasks', 'habit_log_id')) {
            // FKがある場合は環境により失敗する可能性がある
            DB::statement("ALTER TABLE remind_tasks MODIFY habit_log_id BIGINT(20) UNSIGNED NULL");
        }

        // 4) 旧ユニーク（habit_log_id, remind_at）を落とす（存在する場合のみ）
        $indexes = DB::select("SHOW INDEX FROM remind_tasks");
        $uniqueNames = [];
        foreach ($indexes as $idx) {
            if ((int) $idx->Non_unique === 0 && $idx->Key_name !== 'PRIMARY') {
                $uniqueNames[$idx->Key_name] = true;
            }
        }
        if (isset($uniqueNames['remind_tasks_business_unique'])) {
            DB::statement("ALTER TABLE remind_tasks DROP INDEX remind_tasks_business_unique");
        }
        if (isset($uniqueNames['uq_remind_log_at'])) {
            DB::statement("ALTER TABLE remind_tasks DROP INDEX uq_remind_log_at");
        }

        // 5) habit_time_id を NOT NULL 化（NULLが残るなら“やらない”）
        $notNullOk = false;
        if (Schema::hasColumn('remind_tasks', 'habit_time_id')) {
            $nullCount = DB::table('remind_tasks')->whereNull('habit_time_id')->count();
            if ($nullCount === 0) {
                DB::statement("ALTER TABLE remind_tasks MODIFY habit_time_id BIGINT(20) UNSIGNED NOT NULL");
                $notNullOk = true;
            }
        }

        // 6) 新ユニーク（habit_time_id, remind_at）
        // NOT NULL 化できた場合のみ UNIQUE を検討（安全側）
        if (
            $notNullOk
            && Schema::hasColumn('remind_tasks', 'habit_time_id')
            && Schema::hasColumn('remind_tasks', 'remind_at')
        ) {
            $duplicate = DB::table('remind_tasks')
                ->select('habit_time_id', 'remind_at')
                ->groupBy('habit_time_id', 'remind_at')
                ->havingRaw('COUNT(*) > 1')
                ->limit(1)
                ->exists();

            $indexes = DB::select("SHOW INDEX FROM remind_tasks");
            $indexNames = array_unique(array_map(static fn ($idx) => $idx->Key_name, $indexes));

            if (!$duplicate) {
                if (!in_array('remind_tasks_time_unique', $indexNames, true)) {
                    DB::statement("ALTER TABLE remind_tasks ADD UNIQUE KEY remind_tasks_time_unique (habit_time_id, remind_at)");
                }
            } else {
                // 重複があるなら UNIQUE を貼らず、性能目的のINDEXだけにする
                if (!in_array('rt_habit_time_remind_idx', $indexNames, true)) {
                    DB::statement("ALTER TABLE remind_tasks ADD INDEX rt_habit_time_remind_idx (habit_time_id, remind_at)");
                }
            }
        }
    }

    public function down(): void
    {
        // 運用事故防止：本番で rollback を想定しない（破壊的になりやすい）
        // 必要なら手動で戻す方針とし、down は no-op にする。
        return;
    }
};
