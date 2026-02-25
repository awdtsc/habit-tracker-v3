<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::table('remind_tasks', function (Blueprint $table) {
      // root_task_id には既に index(rt_root_idx) があるので index() は作らない
      $table->foreign('root_task_id', 'rt_root_fk')
        ->references('id')
        ->on('remind_tasks')
        ->nullOnDelete();
    });
  }

  public function down(): void
  {
    Schema::table('remind_tasks', function (Blueprint $table) {
      $table->dropForeign('rt_root_fk');
    });
  }
};
