<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $table = 'push_subscriptions';
    private string $indexName = 'push_subscriptions_last_seen_at_index';

    private function indexExists(string $table, string $indexName): bool
    {
        // MySQL/MariaDB: INFORMATION_SCHEMA.STATISTICS
        $dbName = DB::getDatabaseName();

        $count = DB::table('information_schema.statistics')
            ->where('table_schema', $dbName)
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->count();

        return $count > 0;
    }

    public function up(): void
    {
        if ($this->indexExists($this->table, $this->indexName)) {
            return; // already present: no-op
        }

        Schema::table($this->table, function (Blueprint $table) {
            $table->index('last_seen_at', $this->indexName);
        });
    }

    public function down(): void
    {
        if (!$this->indexExists($this->table, $this->indexName)) {
            return; // missing: no-op
        }

        Schema::table($this->table, function (Blueprint $table) {
            $table->dropIndex($this->indexName);
        });
    }
};