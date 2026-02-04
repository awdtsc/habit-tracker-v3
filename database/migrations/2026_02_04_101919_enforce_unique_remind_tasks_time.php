<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_NAME = 'remind_tasks_time_unique';

    public function up(): void
    {
        if (!Schema::hasTable('remind_tasks')) {
            return;
        }

        // This migration uses MySQL/MariaDB-specific SQL (SHOW INDEX / ALTER TABLE / DELETE JOIN).
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (!Schema::hasColumn('remind_tasks', 'habit_time_id') || !Schema::hasColumn('remind_tasks', 'remind_at')) {
            return;
        }

        if ($this->hasIndex(self::UNIQUE_NAME)) {
            return;
        }

        // De-duplicate BEFORE adding UNIQUE.
        // - Keep a 'sent' row if any exist in the duplicate group; otherwise keep the smallest id.
        // - Avoid touching rows where habit_time_id is NULL.
        DB::statement("
            DELETE rt
            FROM remind_tasks rt
            JOIN (
                SELECT habit_time_id, remind_at,
                       COALESCE(MIN(CASE WHEN status = 'sent' THEN id END), MIN(id)) AS keep_id
                FROM remind_tasks
                WHERE habit_time_id IS NOT NULL
                GROUP BY habit_time_id, remind_at
                HAVING COUNT(*) > 1
            ) dup
              ON dup.habit_time_id = rt.habit_time_id
             AND dup.remind_at = rt.remind_at
            WHERE rt.id <> dup.keep_id
        ");

        // Add UNIQUE(habit_time_id, remind_at)
        if (!$this->hasIndex(self::UNIQUE_NAME)) {
            DB::statement(sprintf(
                'ALTER TABLE remind_tasks ADD UNIQUE KEY %s (habit_time_id, remind_at)',
                self::UNIQUE_NAME
            ));
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('remind_tasks')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if ($this->hasIndex(self::UNIQUE_NAME)) {
            DB::statement(sprintf(
                'ALTER TABLE remind_tasks DROP INDEX %s',
                self::UNIQUE_NAME
            ));
        }
    }

    private function hasIndex(string $name): bool
    {
        $indexes = DB::select('SHOW INDEX FROM remind_tasks');
        foreach ($indexes as $idx) {
            if (($idx->Key_name ?? null) === $name) {
                return true;
            }
        }
        return false;
    }
};
