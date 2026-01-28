<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('remind_tasks')) {
            return;
        }

        Schema::table('remind_tasks', function (Blueprint $table) {
            // 送信失敗→再試行回数（0=未失敗）
            if (!Schema::hasColumn('remind_tasks', 'attempts')) {
                $table->unsignedTinyInteger('attempts')->default(0)->after('status');
            }
        });
    }

    public function down(): void
    {
        // 運用事故防止：rollbackでの破壊的操作は行わない
        return;
    }
};
