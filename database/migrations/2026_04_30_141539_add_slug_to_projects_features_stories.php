<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name');
        });
        Schema::table('features', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name');
        });
        Schema::table('stories', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name');
        });

        $this->backfillProjects();
        $this->backfillFeatures();
        $this->backfillStories();

        Schema::table('projects', function (Blueprint $table) {
            $table->string('slug')->nullable(false)->change();
            $table->unique(['team_id', 'slug']);
        });
        Schema::table('features', function (Blueprint $table) {
            $table->string('slug')->nullable(false)->change();
            $table->unique(['project_id', 'slug']);
        });
        Schema::table('stories', function (Blueprint $table) {
            $table->string('slug')->nullable(false)->change();
            $table->unique(['feature_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'slug']);
            $table->dropColumn('slug');
        });
        Schema::table('features', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'slug']);
            $table->dropColumn('slug');
        });
        Schema::table('stories', function (Blueprint $table) {
            $table->dropUnique(['feature_id', 'slug']);
            $table->dropColumn('slug');
        });
    }

    private function backfillProjects(): void
    {
        foreach (DB::table('projects')->orderBy('id')->get() as $row) {
            $slug = $this->uniqueSlug('projects', 'team_id', $row->team_id, $row->name, $row->id);
            DB::table('projects')->where('id', $row->id)->update(['slug' => $slug]);
        }
    }

    private function backfillFeatures(): void
    {
        foreach (DB::table('features')->orderBy('id')->get() as $row) {
            $slug = $this->uniqueSlug('features', 'project_id', $row->project_id, $row->name, $row->id);
            DB::table('features')->where('id', $row->id)->update(['slug' => $slug]);
        }
    }

    private function backfillStories(): void
    {
        foreach (DB::table('stories')->orderBy('id')->get() as $row) {
            $slug = $this->uniqueSlug('stories', 'feature_id', $row->feature_id, $row->name, $row->id);
            DB::table('stories')->where('id', $row->id)->update(['slug' => $slug]);
        }
    }

    private function uniqueSlug(string $table, string $scopeColumn, mixed $scopeValue, string $name, int $rowId): string
    {
        $base = Str::slug($name) ?: 'item-'.$rowId;
        $slug = $base;
        $i = 2;
        while (
            DB::table($table)
                ->where($scopeColumn, $scopeValue)
                ->where('slug', $slug)
                ->where('id', '!=', $rowId)
                ->exists()
        ) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
};
