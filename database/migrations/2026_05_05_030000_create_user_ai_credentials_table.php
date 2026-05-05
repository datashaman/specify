<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
