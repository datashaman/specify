<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('lead_user_id')->nullable()->after('description')->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->after('lead_user_id')->constrained('users')->nullOnDelete();
        });

        Schema::table('stories', function (Blueprint $table) {
            $table->foreignId('assignee_id')->nullable()->after('description')->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->after('assignee_id')->constrained('users')->nullOnDelete();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('assignee_id')->nullable()->after('description')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assignee_id');
        });

        Schema::table('stories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_id');
            $table->dropConstrainedForeignId('assignee_id');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_id');
            $table->dropConstrainedForeignId('lead_user_id');
        });
    }
};
