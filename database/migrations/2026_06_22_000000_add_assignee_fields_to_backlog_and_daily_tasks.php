<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backlog_tasks', function (Blueprint $table) {
            $table->foreignId('assigned_user_id')
                ->nullable()
                ->after('team_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(['team_id', 'assigned_user_id']);
        });

        Schema::table('daily_tasks', function (Blueprint $table) {
            $table->foreignId('backlog_assigned_user_id')
                ->nullable()
                ->after('team_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('daily_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('backlog_assigned_user_id');
        });

        Schema::table('backlog_tasks', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'assigned_user_id']);
            $table->dropConstrainedForeignId('assigned_user_id');
        });
    }
};
