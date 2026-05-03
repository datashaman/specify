<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('status')->default('active')->after('description');
        });

        Schema::table('features', function (Blueprint $table) {
            $table->string('status')->default('proposed')->after('description');
        });

        Schema::table('stories', function (Blueprint $table) {
            $table->string('status')->default('draft')->after('description');
        });

    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        Schema::table('features', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        Schema::table('stories', function (Blueprint $table) {
            $table->dropColumn('status');
        });

    }
};
