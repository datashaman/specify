<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('features', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('description');
        });

        Schema::table('stories', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('features', function (Blueprint $table) {
            $table->dropColumn('notes');
        });

        Schema::table('stories', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
