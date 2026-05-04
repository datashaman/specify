<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subtasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->unique(['task_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subtasks');
    }
};
