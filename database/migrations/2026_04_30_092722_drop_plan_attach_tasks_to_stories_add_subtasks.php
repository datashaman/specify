<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Wipe child rows that reference plans/tasks before structural changes.
        Schema::table('agent_runs', function (Blueprint $table) {
            // No structural change, but the morph rows tied to Plan are
            // about to point at nothing. Local-only data — truncate.
        });
        if (Schema::hasTable('agent_runs')) {
            DB::table('agent_runs')->delete();
        }
        if (Schema::hasTable('task_dependencies')) {
            DB::table('task_dependencies')->delete();
        }
        if (Schema::hasTable('plan_approvals')) {
            DB::table('plan_approvals')->delete();
        }
        if (Schema::hasTable('tasks')) {
            DB::table('tasks')->delete();
        }
        if (Schema::hasTable('plans')) {
            DB::table('plans')->delete();
        }

        // 2. Reshape tasks: drop plan_id, add story_id + acceptance_criterion_id.
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
        });
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('story_id')->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('acceptance_criterion_id')->nullable()->after('story_id')
                ->unique()
                ->constrained('acceptance_criteria')->nullOnDelete();
        });

        // 3. Drop stories.current_plan_id.
        Schema::table('stories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_plan_id');
        });

        // 4. Drop acceptance_criteria.met (status now derived from linked task).
        Schema::table('acceptance_criteria', function (Blueprint $table) {
            $table->dropColumn('met');
        });

        // 5. Drop plan_approvals + plans tables.
        Schema::dropIfExists('plan_approvals');
        Schema::dropIfExists('plans');

        // 6. Add subtasks table.
        Schema::create('subtasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->index(['task_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subtasks');

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->text('summary')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();
        });

        Schema::create('plan_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('plan_revision')->default(0);
            $table->foreignId('approver_id')->constrained('users')->restrictOnDelete();
            $table->string('decision');
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['plan_id', 'plan_revision']);
            $table->index('approver_id');
        });

        Schema::table('acceptance_criteria', function (Blueprint $table) {
            $table->boolean('met')->default(false);
        });

        Schema::table('stories', function (Blueprint $table) {
            $table->foreignId('current_plan_id')->nullable()->constrained('plans')->nullOnDelete();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('story_id');
            $table->dropConstrainedForeignId('acceptance_criterion_id');
        });
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('plan_id')->after('id')->constrained()->cascadeOnDelete();
        });
    }
};
