<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->string('kind')->default('user_story')->after('slug');
            $table->text('actor')->nullable()->after('kind');
            $table->text('intent')->nullable()->after('actor');
            $table->text('outcome')->nullable()->after('intent');
        });

        Schema::create('scenarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->foreignId('acceptance_criterion_id')->nullable()->constrained('acceptance_criteria')->nullOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('name');
            $table->text('given_text')->nullable();
            $table->text('when_text')->nullable();
            $table->text('then_text')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['story_id', 'position']);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->string('name')->nullable()->after('version');
            $table->longText('design_notes')->nullable()->after('summary');
            $table->longText('implementation_notes')->nullable()->after('design_notes');
            $table->longText('risks')->nullable()->after('implementation_notes');
            $table->longText('assumptions')->nullable()->after('risks');
            $table->string('source')->default('human')->after('assumptions');
            $table->string('source_label')->nullable()->after('source');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('acceptance_criterion_id')->nullable()->after('plan_id')
                ->constrained('acceptance_criteria')->nullOnDelete();
            $table->foreignId('scenario_id')->nullable()->after('acceptance_criterion_id')
                ->constrained('scenarios')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scenario_id');
            $table->dropConstrainedForeignId('acceptance_criterion_id');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['name', 'design_notes', 'implementation_notes', 'risks', 'assumptions', 'source', 'source_label']);
        });

        Schema::dropIfExists('scenarios');

        Schema::table('stories', function (Blueprint $table) {
            $table->dropColumn(['kind', 'actor', 'intent', 'outcome']);
        });
    }
};
