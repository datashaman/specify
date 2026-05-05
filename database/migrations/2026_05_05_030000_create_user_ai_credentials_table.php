<?php

use App\Models\Story;
use App\Models\Subtask;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('ai_provider')->nullable()->after('github_scopes');
        });

        Schema::create('user_ai_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->text('api_key');
            $table->string('model')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'provider']);
            $table->index(['user_id', 'enabled']);
        });

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        DB::table('agent_runs')
            ->whereNull('user_id')
            ->where('runnable_type', Story::class)
            ->update([
                'user_id' => DB::raw('(select stories.created_by_id from stories where stories.id = agent_runs.runnable_id)'),
            ]);

        DB::table('agent_runs')
            ->whereNull('user_id')
            ->where('runnable_type', Subtask::class)
            ->update([
                'user_id' => DB::raw('(
                    select stories.created_by_id
                    from subtasks
                    inner join tasks on tasks.id = subtasks.task_id
                    inner join plans on plans.id = tasks.plan_id
                    inner join stories on stories.id = plans.story_id
                    where subtasks.id = agent_runs.runnable_id
                    limit 1
                )'),
            ]);
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::dropIfExists('user_ai_credentials');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ai_provider');
        });
    }
};
