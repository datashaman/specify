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
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};
