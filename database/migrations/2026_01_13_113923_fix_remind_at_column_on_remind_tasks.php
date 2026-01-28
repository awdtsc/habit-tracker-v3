<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('remind_tasks') || !Schema::hasColumn('remind_tasks', 'remind_at')) {
            return;
        }

        // If NULLs exist, keep remind_at nullable to avoid mutating historical meaning.
        // If no NULLs, enforce NOT NULL for stronger invariants.
        $nullCount = DB::table('remind_tasks')->whereNull('remind_at')->count();

        if ($nullCount === 0) {
            DB::statement("ALTER TABLE remind_tasks MODIFY COLUMN remind_at DATETIME NOT NULL");
        } else {
            DB::statement("ALTER TABLE remind_tasks MODIFY COLUMN remind_at DATETIME NULL");
        }
    }

    public function down(): void
    {
        // Safety-first: do not reintroduce dangerous defaults/ON UPDATE behavior on rollback.
        return;
    }
};
