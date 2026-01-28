<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Fresh install ordering: create early, no-op for existing environments.
        if (Schema::hasTable('remind_tasks')) {
            return;
        }

        Schema::create('remind_tasks', function (Blueprint $table) {
            $table->id();
            $table->timestamp('remind_at')->index();
            $table->string('status', 32)->default('pending')->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Safety-first: avoid dropping a production table by accident.
        return;
    }
};
