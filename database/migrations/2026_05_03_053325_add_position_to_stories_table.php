<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0)->after('feature_id');
        });

        DB::table('stories')
            ->select('id', 'feature_id')
            ->orderBy('feature_id')
            ->orderBy('id')
            ->get()
            ->groupBy('feature_id')
            ->each(function ($rows) {
                $rows->values()->each(function ($row, $i) {
                    DB::table('stories')->where('id', $row->id)->update(['position' => $i + 1]);
                });
            });

        Schema::table('stories', function (Blueprint $table) {
            $table->unique(['feature_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->dropUnique(['feature_id', 'position']);
            $table->dropColumn('position');
        });
    }
};
