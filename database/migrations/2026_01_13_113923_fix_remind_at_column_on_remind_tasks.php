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

        // NULL が残っていると NOT NULL 化で落ちるので、最低限の救済を入れる。
        // ここでは migrate を止めないことを優先し、NULL は CURRENT_TIMESTAMP で埋める。
        DB::statement("
            UPDATE remind_tasks
            SET remind_at = CURRENT_TIMESTAMP
            WHERE remind_at IS NULL
        ");

        // MariaDB/MySQL: ON UPDATE を外すには MODIFY が確実（Schema::change は環境依存）
        // “通知予定時刻”なので default/current_timestamp は付けない（手動で値を入れる前提）
        DB::statement("
            ALTER TABLE remind_tasks
            MODIFY COLUMN remind_at TIMESTAMP NOT NULL
        ");
    }

    public function down(): void
    {
        // 運用事故防止：rollbackで ON UPDATE を復活させるのは危険。
        // この migration の目的は安全化なので down は no-op とする。
        return;
    }
};
