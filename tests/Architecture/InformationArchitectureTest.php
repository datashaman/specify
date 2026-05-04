<?php

use Illuminate\Support\Facades\Route;

test('authenticated application page routes use the project-first IA', function () {
    $allowedExactUris = [
        'activity',
        'projects',
        'runs/{run}/events',
        'settings',
        'settings/appearance',
        'settings/profile',
        'settings/security',
        'triage',
    ];

    $unexpectedUris = collect(Route::getRoutes())
        ->filter(fn ($route) => in_array('GET', $route->methods(), true))
        ->filter(fn ($route) => in_array('auth', $route->gatherMiddleware(), true))
        ->map(fn ($route) => $route->uri())
        ->filter(fn (string $uri) => ! in_array($uri, $allowedExactUris, true))
        ->filter(fn (string $uri) => ! str_starts_with($uri, 'projects/{project}'))
        ->values()
        ->all();

    expect($unexpectedUris)->toBe([]);
});
