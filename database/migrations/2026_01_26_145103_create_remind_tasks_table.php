<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        // Intentionally no-op.
        // A proper early create migration exists:
        // - 2026_01_01_000000_create_remind_tasks_table.php
        //
        // Keep this file to avoid migration-history surprises, but never create/drop tables here.
        return;
    }

    public function down(): void
    {
        // no-op (safe)
        return;
    }
};