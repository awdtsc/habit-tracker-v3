<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // MariaDB/MySQL: ON UPDATE を外すには MODIFY が確実（Schema::change は環境依存）
        // “通知予定時刻”なので default/current_timestamp も付けない（手動で値を入れる前提）
        DB::statement("
            ALTER TABLE remind_tasks
            MODIFY COLUMN remind_at TIMESTAMP NOT NULL
        ");
    }

    public function down(): void
    {
        // 元に戻す必要がある場合の復元（※本来は推奨しないが、downとして用意）
        DB::statement("
            ALTER TABLE remind_tasks
            MODIFY COLUMN remind_at TIMESTAMP NOT NULL
            DEFAULT CURRENT_TIMESTAMP
            ON UPDATE CURRENT_TIMESTAMP
        ");
    }
};