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
            $table->unsignedInteger('next_story_position')->default(1)->after('project_id');
        });

        DB::table('features')
            ->leftJoin('stories', 'stories.feature_id', '=', 'features.id')
            ->groupBy('features.id')
            ->select('features.id', DB::raw('COALESCE(MAX(stories.position), 0) + 1 as next_position'))
            ->orderBy('features.id')
            ->get()
            ->each(function ($feature) {
                DB::table('features')
                    ->where('id', $feature->id)
                    ->update(['next_story_position' => $feature->next_position]);
            });
    }

    public function down(): void
    {
        Schema::table('features', function (Blueprint $table) {
            $table->dropColumn('next_story_position');
        });
    }
};
