<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->text('summary')->nullable();
            $table->timestamps();
            $table->unique(['story_id', 'version']);
        });

        Schema::table('stories', function (Blueprint $table) {
            $table->foreignId('current_plan_id')->nullable()->after('description')->constrained('plans')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_plan_id');
        });

        Schema::dropIfExists('plans');
    }
};
