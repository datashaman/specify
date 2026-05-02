<?php

use App\Http\Controllers\Auth\SocialiteController;
use App\Http\Controllers\Webhooks\GithubWebhookController;
use App\Models\AgentRun;
use App\Models\Story;
use App\Models\Subtask;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::post('webhooks/github/{repo}', GithubWebhookController::class)->name('webhooks.github');

Route::get('auth/{provider}/redirect', [SocialiteController::class, 'redirect'])
    ->whereIn('provider', ['github'])
    ->name('socialite.redirect');
Route::get('auth/{provider}/callback', [SocialiteController::class, 'callback'])
    ->whereIn('provider', ['github'])
    ->name('socialite.callback');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
    Route::livewire('triage', 'pages::triage')->name('triage');
    Route::livewire('activity', 'pages::activity.index')->name('activity.index');
    Route::livewire('projects', 'pages::projects.index')->name('projects.index');
    Route::livewire('projects/{project}', 'pages::projects.show')->name('projects.show');
    Route::livewire('projects/{project}/features/{feature}', 'pages::features.show')->name('features.show');
    Route::livewire('projects/{project}/stories', 'pages::stories.index')->name('stories.index');
    Route::livewire('projects/{project}/stories/create', 'pages::stories.create')->name('stories.create');
    Route::livewire('projects/{project}/runs', 'pages::runs.index')->name('runs.index');
    Route::livewire('projects/{project}/repos', 'pages::repos.index')->name('repos.index');
    Route::livewire('projects/{project}/stories/{story}', 'pages::stories.show')->name('stories.show');

    // Legacy /stories/{story} → /projects/{p}/stories/{s} (resolve via story).
    Route::get('stories/{story}', function (int $story) {
        $s = Story::query()
            ->whereHas('feature', fn ($q) => $q->whereIn('project_id', auth()->user()->accessibleProjectIds()))
            ->find($story);
        abort_unless($s, 404);

        return redirect()->route('stories.show', ['project' => $s->feature->project_id, 'story' => $s->id], 301);
    });

    // Legacy /runs/{run} → canonical run console URL (Slice 3 lands the
    // nested route; for now redirect to the parent Story document).
    Route::get('runs/{run}', function (int $run) {
        $r = AgentRun::query()->find($run);
        abort_unless($r, 404);

        $story = match ($r->runnable_type) {
            Story::class => Story::find($r->runnable_id),
            Subtask::class => Subtask::find($r->runnable_id)?->task?->story,
            default => null,
        };
        abort_unless($story, 404);
        abort_unless(in_array($story->feature->project_id, auth()->user()->accessibleProjectIds(), true), 404);

        return redirect()->route('stories.show', ['project' => $story->feature->project_id, 'story' => $story->id], 301);
    });

    // Legacy URL redirects (ADR-0012). PR descriptions, Slack links, and
    // notification emails written against the old paths must continue to resolve.
    Route::permanentRedirect('inbox', 'triage');
    Route::permanentRedirect('events', 'activity');

    // Project-scoped legacy redirects: resolve via the user's current project.
    // If no current project is set, fall back to the project picker.
    foreach (['stories' => 'stories.index', 'runs' => 'runs.index', 'repos' => 'repos.index'] as $legacy => $named) {
        Route::get($legacy, function () use ($named) {
            $projectId = auth()->user()->current_project_id;

            return $projectId
                ? redirect()->route($named, ['project' => $projectId], 301)
                : redirect()->route('projects.index', [], 301);
        });
    }
    Route::get('stories/create', function () {
        $projectId = auth()->user()->current_project_id;

        return $projectId
            ? redirect()->route('stories.create', ['project' => $projectId], 301)
            : redirect()->route('projects.index', [], 301);
    });
});

require __DIR__.'/settings.php';
