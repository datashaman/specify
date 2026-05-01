<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subtasks', function (Blueprint $table) {
            $table->foreignId('proposed_by_run_id')
                ->nullable()
                ->after('description')
                ->constrained('agent_runs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subtasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proposed_by_run_id');
        });
    }
};
