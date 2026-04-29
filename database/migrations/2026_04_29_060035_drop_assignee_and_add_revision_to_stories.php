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
        Schema::table('stories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assignee_id');
            $table->unsignedInteger('revision')->default(1)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->dropColumn('revision');
            $table->foreignId('assignee_id')->nullable()->after('description')->constrained('users')->nullOnDelete();
        });
    }
};
