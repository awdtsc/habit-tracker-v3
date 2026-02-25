<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
  public function up(): void
  {
    // 既に壊れているデータ（所有者不明）を先に除去してから制約を強制する
    DB::statement('DELETE FROM push_subscriptions WHERE user_id IS NULL');

    // MySQL 前提：user_id を NOT NULL に固定
    DB::statement('ALTER TABLE push_subscriptions MODIFY user_id BIGINT UNSIGNED NOT NULL');
  }

  public function down(): void
  {
    // 差し戻し可能にしておく（ただし NULL 行が復活するリスクはある）
    DB::statement('ALTER TABLE push_subscriptions MODIFY user_id BIGINT UNSIGNED NULL');
  }
};
