<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0010: cooperative cancel + retry chain for AgentRuns.
 *
 * - cancel_requested: flag the SubtaskRunPipeline polls between phases.
 * - retry_of_id: chain pointer; the new run that retries an earlier one
 *   records the prior run's id so reviewers can ask "how many attempts
 *   did this Subtask take?".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $t) {
            $t->boolean('cancel_requested')->default(false)->after('status');
            $t->foreignId('retry_of_id')->nullable()->after('cancel_requested')
                ->constrained('agent_runs')->nullOnDelete();
            $t->index('cancel_requested');
            $t->index('retry_of_id');
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $t) {
            $t->dropForeign(['retry_of_id']);
            $t->dropIndex(['retry_of_id']);
            $t->dropIndex(['cancel_requested']);
            $t->dropColumn(['cancel_requested', 'retry_of_id']);
        });
    }
};
