<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repo_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider');
            $table->string('event');
            $table->string('action')->nullable();
            $table->boolean('signature_valid');
            $table->foreignId('matched_run_id')->nullable()->constrained('agent_runs')->nullOnDelete();
            $table->json('payload');
            $table->timestamps();
            $table->index(['repo_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
