<?php

use App\Http\Controllers\Auth\SocialiteController;
use App\Http\Controllers\RunEventsController;
use App\Http\Controllers\Webhooks\GithubWebhookController;
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

    Route::livewire('triage', 'pages::triage')->name('triage');
    Route::livewire('activity', 'pages::activity.index')->name('activity.index');
    Route::livewire('projects', 'pages::projects.index')->name('projects.index');
    Route::livewire('projects/{project}', 'pages::projects.show')->name('projects.show');
    Route::livewire('projects/{project}/features/{feature}', 'pages::features.show')->name('features.show');
    Route::livewire('projects/{project}/stories', 'pages::stories.index')->name('stories.index');
    Route::livewire('projects/{project}/stories/create', 'pages::stories.create')->name('stories.create');
    Route::livewire('projects/{project}/plans', 'pages::plans.index')->name('plans.index');
    Route::livewire('projects/{project}/plans/{plan}', 'pages::plans.show')->name('plans.show');
    Route::livewire('projects/{project}/approvals', 'pages::approvals.index')->name('approvals.index');
    Route::livewire('projects/{project}/runs', 'pages::runs.index')->name('runs.index');
    Route::livewire('projects/{project}/repos', 'pages::repos.index')->name('repos.index');
    Route::livewire('projects/{project}/stories/{story}', 'pages::stories.show')->name('stories.show');
    Route::livewire('projects/{project}/stories/{story}/tasks/{task}', 'pages::tasks.show')->name('tasks.show');
    Route::livewire('projects/{project}/stories/{story}/subtasks/{subtask}', 'pages::subtasks.show')->name('subtasks.show');
    Route::livewire('projects/{project}/stories/{story}/subtasks/{subtask}/runs/{run}', 'pages::runs.show')->name('runs.show');

});

require __DIR__.'/settings.php';
