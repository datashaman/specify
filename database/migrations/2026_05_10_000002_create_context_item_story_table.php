<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('context_item_story', function (Blueprint $table) {
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->foreignId('context_item_id')->constrained()->cascadeOnDelete();
            $table->timestamp('included_at')->useCurrent();
            $table->foreignId('included_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->primary(['story_id', 'context_item_id']);
            $table->index('context_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('context_item_story');
    }
};
