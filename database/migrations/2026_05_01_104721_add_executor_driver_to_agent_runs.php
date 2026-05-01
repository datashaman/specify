<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->string('executor_driver')->nullable()->after('working_branch');
        });

        // Backfill so analytics queries can treat executor_driver as
        // load-bearing for every Subtask run, not just race runs.
        $default = (string) config('specify.executor.default', config('specify.executor.driver', 'laravel-ai'));
        DB::table('agent_runs')
            ->where('runnable_type', 'App\\Models\\Subtask')
            ->whereNull('executor_driver')
            ->update(['executor_driver' => $default]);
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn('executor_driver');
        });
    }
};
