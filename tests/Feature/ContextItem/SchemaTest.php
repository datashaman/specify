<?php

use Illuminate\Support\Facades\Schema;

test('context_items table has expected columns', function () {
    expect(Schema::hasTable('context_items'))->toBeTrue();

    foreach ([
        'id', 'project_id', 'story_id', 'type', 'title', 'description',
        'metadata', 'summary', 'summary_status', 'summary_error',
        'created_by_id', 'deleted_at', 'created_at', 'updated_at',
    ] as $column) {
        expect(Schema::hasColumn('context_items', $column))
            ->toBeTrue("missing column: {$column}");
    }
});

test('context_item_story pivot has expected columns', function () {
    expect(Schema::hasTable('context_item_story'))->toBeTrue();

    foreach (['story_id', 'context_item_id', 'included_at', 'included_by_id'] as $column) {
        expect(Schema::hasColumn('context_item_story', $column))
            ->toBeTrue("missing column: {$column}");
    }
});
