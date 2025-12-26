<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) FK が依存しがちな単体INDEXを先に保証（ここが今回の肝）
        $this->ensureIndex('habit_logs', ['user_id'], 'habit_logs_user_id_index');
        $this->ensureIndex('habit_logs', ['habit_id'], 'habit_logs_habit_id_index');
        $this->ensureIndex('habit_logs', ['habit_time_id'], 'habit_logs_habit_time_id_index');

        // 2) 旧 UNIQUE (user_id, habit_id, date, time_slot) を落とす
        $legacyUnique = $this->findUniqueIndex('habit_logs', [
            'user_id',
            'habit_id',
            'date',
            'time_slot',
        ]);

        if ($legacyUnique) {
            Schema::table('habit_logs', function (Blueprint $table) use ($legacyUnique) {
                $table->dropUnique($legacyUnique);
            });
        }

        // 3) 新 UNIQUE (user_id, habit_time_id, date) を張る
        $newUnique = $this->findUniqueIndex('habit_logs', [
            'user_id',
            'habit_time_id',
            'date',
        ]);

        if (!$newUnique) {
            Schema::table('habit_logs', function (Blueprint $table) {
                $table->unique(
                    ['user_id', 'habit_time_id', 'date'],
                    'habit_logs_user_habit_time_date_unique'
                );
            });
        }
    }

    public function down(): void
    {
        $newUnique = $this->findUniqueIndex('habit_logs', [
            'user_id',
            'habit_time_id',
            'date',
        ]);

        if ($newUnique) {
            Schema::table('habit_logs', function (Blueprint $table) use ($newUnique) {
                $table->dropUnique($newUnique);
            });
        }

        $legacyUnique = $this->findUniqueIndex('habit_logs', [
            'user_id',
            'habit_id',
            'date',
            'time_slot',
        ]);

        if (!$legacyUnique) {
            Schema::table('habit_logs', function (Blueprint $table) {
                $table->unique(
                    ['user_id', 'habit_id', 'date', 'time_slot'],
                    'uq_user_habit_date_slot'
                );
            });
        }
    }

    private function ensureIndex(string $table, array $columns, string $indexName): void
    {
        if ($this->findAnyIndex($table, $columns)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($columns, $indexName) {
            $table->index($columns, $indexName);
        });
    }

    private function findAnyIndex(string $table, array $columns): ?string
    {
        $indexes = DB::select("SHOW INDEX FROM {$table}");
        $targets = array_values($columns);

        $byName = [];
        foreach ($indexes as $index) {
            $name = $index->Key_name;
            $seq  = (int) $index->Seq_in_index;
            $byName[$name]['columns'][$seq] = $index->Column_name;
        }

        foreach ($byName as $name => $data) {
            ksort($data['columns']);
            $cols = array_values($data['columns']);
            if ($cols === $targets) {
                return $name;
            }
        }
        return null;
    }

    private function findUniqueIndex(string $table, array $columns): ?string
    {
        $indexes = DB::select("SHOW INDEX FROM {$table}");

        $targets = array_values($columns);
        $byName = [];

        foreach ($indexes as $index) {
            if ((int) $index->Non_unique !== 0) {
                continue;
            }

            $name = $index->Key_name;
            $seq  = (int) $index->Seq_in_index;
            $byName[$name]['columns'][$seq] = $index->Column_name;
        }

        foreach ($byName as $name => $data) {
            ksort($data['columns']);
            $cols = array_values($data['columns']);
            if ($cols === $targets) {
                return $name;
            }
        }

        return null;
    }
};