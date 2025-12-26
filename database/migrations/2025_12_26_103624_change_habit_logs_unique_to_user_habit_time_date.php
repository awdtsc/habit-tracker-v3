<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
                    'habit_logs_user_habit_date_slot_unique'
                );
            });
        }
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
            $seq = (int) $index->Seq_in_index;
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