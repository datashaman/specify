<?php

use App\Enums\AgentRunKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->string('kind')->default(AgentRunKind::Execute->value)->after('executor_driver');
            $table->index(['runnable_type', 'runnable_id', 'kind']);
        });

        Schema::table('repos', function (Blueprint $table) {
            $table->boolean('review_response_enabled')->default(false)->after('webhook_secret');
            $table->unsignedSmallInteger('max_review_response_cycles')->default(3)->after('review_response_enabled');
        });

        Schema::table('webhook_events', function (Blueprint $table) {
            $table->string('delivery_id')->nullable()->after('action')->unique();
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropUnique(['delivery_id']);
            $table->dropColumn('delivery_id');
        });

        Schema::table('repos', function (Blueprint $table) {
            $table->dropColumn(['review_response_enabled', 'max_review_response_cycles']);
        });

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropIndex(['runnable_type', 'runnable_id', 'kind']);
            $table->dropColumn('kind');
        });
    }
};
