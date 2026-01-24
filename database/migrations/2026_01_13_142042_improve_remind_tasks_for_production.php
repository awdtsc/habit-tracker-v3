<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remind_tasks', function (Blueprint $table) {
            // --- 系列管理（子レコード方式の要） ---
            // ルート系列ID：ルートは自分のid、子孫は同じroot_task_id
            if (!Schema::hasColumn('remind_tasks', 'root_task_id')) {
                $table->unsignedBigInteger('root_task_id')->nullable()->after('parent_task_id');
            }

            // --- ディスパッチ運用（並行実行/救済/観測） ---
            if (!Schema::hasColumn('remind_tasks', 'claim_token')) {
                $table->string('claim_token', 64)->nullable()->after('status');
            }

            if (!Schema::hasColumn('remind_tasks', 'sent_at')) {
                $table->timestamp('sent_at')->nullable()->after('remind_at');
            }

            if (!Schema::hasColumn('remind_tasks', 'last_error')) {
                $table->text('last_error')->nullable()->after('claim_token');
            }

            // --- Index（運用上効くやつだけ） ---
            // due 検索の主戦場
            $table->index(['status', 'remind_at'], 'rt_status_remind_at_idx');

            // 親→子の辿り（スヌーズ系列）
            $table->index(['parent_task_id'], 'rt_parent_idx');

            // 系列まとめ（rootで一括cancel等）
            $table->index(['root_task_id'], 'rt_root_idx');

            // claim_token で「自分が掴んだ分」だけ処理したいなら
            $table->index(['claim_token'], 'rt_claim_token_idx');

            // habit_log からの参照が多いなら（必要に応じて）
            $table->index(['habit_log_id'], 'rt_habit_log_idx');
        });
    }

    public function down(): void
    {
        Schema::table('remind_tasks', function (Blueprint $table) {
            // index を先に落とす（名前指定が安全）
            $table->dropIndex('rt_status_remind_at_idx');
            $table->dropIndex('rt_parent_idx');
            $table->dropIndex('rt_root_idx');
            $table->dropIndex('rt_claim_token_idx');
            $table->dropIndex('rt_habit_log_idx');

            // columns を落とす
            if (Schema::hasColumn('remind_tasks', 'root_task_id')) {
                $table->dropColumn('root_task_id');
            }
            if (Schema::hasColumn('remind_tasks', 'claim_token')) {
                $table->dropColumn('claim_token');
            }
            if (Schema::hasColumn('remind_tasks', 'sent_at')) {
                $table->dropColumn('sent_at');
            }
            if (Schema::hasColumn('remind_tasks', 'last_error')) {
                $table->dropColumn('last_error');
            }
        });
    }
};
