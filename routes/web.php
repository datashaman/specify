<?php

use App\Http\Controllers\Auth\SocialiteController;
use App\Http\Controllers\RunEventsController;
use App\Http\Controllers\StoryContextItemsController;
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
    Route::get('runs/{run}/events', RunEventsController::class)->name('runs.events');

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
    Route::post('projects/{project}/stories/{story}/context-items', [StoryContextItemsController::class, 'store'])->name('stories.context-items.store');
    Route::livewire('projects/{project}/stories/{story}/tasks/{task}', 'pages::tasks.show')->name('tasks.show');
    Route::livewire('projects/{project}/stories/{story}/subtasks/{subtask}', 'pages::subtasks.show')->name('subtasks.show');
    Route::livewire('projects/{project}/stories/{story}/subtasks/{subtask}/runs/{run}', 'pages::runs.show')->name('runs.show');

    // Legacy /stories/{story} → /projects/{p}/stories/{s} (resolve via story).
    Route::get('stories/{story}', function (int $story) {
        $s = Story::query()
            ->whereHas('feature', fn ($q) => $q->whereIn('project_id', auth()->user()->accessibleProjectIds()))
            ->find($story);
        abort_unless($s, 404);

        return redirect()->route('stories.show', ['project' => $s->feature->project_id, 'story' => $s->id], 301);
    });

    // Legacy /runs/{run} → canonical nested run console. Plan-generation
    // runs (Story-runnable) have no Subtask parent and redirect to the
    // Story document instead.
    Route::get('runs/{run}', function (int $run) {
        $r = AgentRun::query()->find($run);
        abort_unless($r, 404);

        if ($r->runnable_type === Story::class) {
            $story = Story::find($r->runnable_id);
            abort_unless($story, 404);
            abort_unless(in_array($story->feature->project_id, auth()->user()->accessibleProjectIds(), true), 404);

            return redirect()->route('stories.show', ['project' => $story->feature->project_id, 'story' => $story->id], 301);
        }

        if ($r->runnable_type === Subtask::class) {
            $subtask = Subtask::with('task.story.feature')->find($r->runnable_id);
            abort_unless($subtask?->task?->story, 404);
            $story = $subtask->task->story;
            abort_unless(in_array($story->feature->project_id, auth()->user()->accessibleProjectIds(), true), 404);

            return redirect()->route('runs.show', [
                'project' => $story->feature->project_id,
                'story' => $story->id,
                'subtask' => $subtask->id,
                'run' => $r->id,
            ], 301);
        }

        abort(404);
    });

    // Legacy URL redirects (ADR-0012). PR descriptions, Slack links, and
    // notification emails written against the old paths must continue to resolve.
    Route::permanentRedirect('inbox', 'triage');
    Route::permanentRedirect('events', 'activity');

    // Project-scoped legacy redirects: resolve via the user's current project,
    // but only if it's still accessible. If pinned project was deleted or
    // membership revoked, fall back to the project picker rather than a
    // broken project URL.
    $resolveActiveProjectId = function () {
        $user = auth()->user();
        $pinned = $user->current_project_id;
        if (! $pinned) {
            return null;
        }

        return in_array((int) $pinned, $user->accessibleProjectIds(), true)
            ? (int) $pinned
            : null;
    };
    foreach (['stories' => 'stories.index', 'runs' => 'runs.index', 'repos' => 'repos.index'] as $legacy => $named) {
        Route::get($legacy, function () use ($named, $resolveActiveProjectId) {
            $projectId = $resolveActiveProjectId();

            return $projectId
                ? redirect()->route($named, ['project' => $projectId], 301)
                : redirect()->route('projects.index', [], 301);
        });
    }
    Route::get('stories/create', function () use ($resolveActiveProjectId) {
        $projectId = $resolveActiveProjectId();

        return $projectId
            ? redirect()->route('stories.create', ['project' => $projectId], 301)
            : redirect()->route('projects.index', [], 301);
    });
});

require __DIR__.'/settings.php';
