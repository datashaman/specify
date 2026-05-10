<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('features', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0)->after('project_id');
        });

        DB::table('features')
            ->select('id', 'project_id')
            ->orderBy('project_id')
            ->orderBy('id')
            ->get()
            ->groupBy('project_id')
            ->each(function ($rows) {
                $rows->values()->each(function ($row, $i) {
                    DB::table('features')->where('id', $row->id)->update(['position' => $i + 1]);
                });
            });

        Schema::table('features', function (Blueprint $table) {
            $table->unique(['project_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('features', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'position']);
            $table->dropColumn('position');
        });
    }
};
