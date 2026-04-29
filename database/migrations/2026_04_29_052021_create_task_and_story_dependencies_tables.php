<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('depends_on_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->primary(['task_id', 'depends_on_task_id']);
            $table->index('depends_on_task_id');
        });

        Schema::create('story_dependencies', function (Blueprint $table) {
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->foreignId('depends_on_story_id')->constrained('stories')->cascadeOnDelete();
            $table->primary(['story_id', 'depends_on_story_id']);
            $table->index('depends_on_story_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_dependencies');
        Schema::dropIfExists('task_dependencies');
    }
};
