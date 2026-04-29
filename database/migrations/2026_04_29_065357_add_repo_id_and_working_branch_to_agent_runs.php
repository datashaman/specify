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
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->foreignId('repo_id')->nullable()->after('runnable_id')->constrained('repos')->nullOnDelete();
            $table->string('working_branch')->nullable()->after('model_id');
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn('working_branch');
            $table->dropConstrainedForeignId('repo_id');
        });
    }
};
