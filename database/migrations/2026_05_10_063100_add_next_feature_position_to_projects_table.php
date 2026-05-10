<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedInteger('next_feature_position')->default(1)->after('team_id');
        });

        DB::table('projects')
            ->leftJoin('features', 'features.project_id', '=', 'projects.id')
            ->groupBy('projects.id')
            ->select('projects.id', DB::raw('COALESCE(MAX(features.position), 0) + 1 as next_position'))
            ->orderBy('projects.id')
            ->get()
            ->each(function ($project) {
                DB::table('projects')
                    ->where('id', $project->id)
                    ->update(['next_feature_position' => $project->next_position]);
            });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('next_feature_position');
        });
    }
};
