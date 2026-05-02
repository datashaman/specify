<?php

use App\Http\Controllers\Auth\SocialiteController;
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
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
    Route::livewire('triage', 'pages::triage')->name('triage');
    Route::livewire('activity', 'pages::activity.index')->name('activity.index');
    Route::livewire('projects', 'pages::projects.index')->name('projects.index');
    Route::livewire('projects/{project}', 'pages::projects.show')->name('projects.show');
    Route::livewire('projects/{project}/features/{feature}', 'pages::features.show')->name('features.show');
    Route::livewire('stories', 'pages::stories.index')->name('stories.index');
    Route::livewire('stories/create', 'pages::stories.create')->name('stories.create');
    Route::livewire('stories/{story}', 'pages::stories.show')->name('stories.show');
    Route::livewire('runs', 'pages::runs.index')->name('runs.index');
    Route::livewire('repos', 'pages::repos.index')->name('repos.index');

    // Legacy URL redirects (ADR-0012). PR descriptions, Slack links, and
    // notification emails written against the old paths must continue to resolve.
    Route::permanentRedirect('inbox', 'triage');
    Route::permanentRedirect('events', 'activity');
});

require __DIR__.'/settings.php';
