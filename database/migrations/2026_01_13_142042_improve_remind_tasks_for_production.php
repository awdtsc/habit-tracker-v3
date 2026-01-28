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

        Schema::table('remind_tasks', function (Blueprint $table) {
            // --- series management ---
            if (!Schema::hasColumn('remind_tasks', 'root_task_id')) {
                $after = Schema::hasColumn('remind_tasks', 'parent_task_id') ? 'parent_task_id' : 'id';
                $table->unsignedBigInteger('root_task_id')->nullable()->after($after);
            }

            // --- dispatch operations ---
            if (!Schema::hasColumn('remind_tasks', 'claim_token')) {
                $table->string('claim_token', 64)->nullable()->after('status');
            }

            // Keep sent_at as DATETIME for consistency with remind_at.
            if (!Schema::hasColumn('remind_tasks', 'sent_at')) {
                $table->dateTime('sent_at')->nullable()->after('remind_at');
            }

            if (!Schema::hasColumn('remind_tasks', 'last_error')) {
                $table->text('last_error')->nullable()->after('claim_token');
            }
        });

        // Add indexes safely (check column existence and existing index names)
        $indexes = DB::select("SHOW INDEX FROM remind_tasks");
        $indexNames = array_unique(array_map(static fn ($idx) => $idx->Key_name, $indexes));

        if (
            Schema::hasColumn('remind_tasks', 'status')
            && Schema::hasColumn('remind_tasks', 'remind_at')
            && !in_array('rt_status_remind_at_idx', $indexNames, true)
        ) {
            DB::statement("CREATE INDEX rt_status_remind_at_idx ON remind_tasks (status, remind_at)");
        }

        if (Schema::hasColumn('remind_tasks', 'parent_task_id') && !in_array('rt_parent_idx', $indexNames, true)) {
            DB::statement("CREATE INDEX rt_parent_idx ON remind_tasks (parent_task_id)");
        }

        if (Schema::hasColumn('remind_tasks', 'root_task_id') && !in_array('rt_root_idx', $indexNames, true)) {
            DB::statement("CREATE INDEX rt_root_idx ON remind_tasks (root_task_id)");
        }

        if (Schema::hasColumn('remind_tasks', 'claim_token') && !in_array('rt_claim_token_idx', $indexNames, true)) {
            DB::statement("CREATE INDEX rt_claim_token_idx ON remind_tasks (claim_token)");
        }

        if (Schema::hasColumn('remind_tasks', 'habit_log_id') && !in_array('rt_habit_log_idx', $indexNames, true)) {
            DB::statement("CREATE INDEX rt_habit_log_idx ON remind_tasks (habit_log_id)");
        }
    }

    public function down(): void
    {
        // Safety-first: do not perform destructive rollback operations in production.
        return;
    }
};
