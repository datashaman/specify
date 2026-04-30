<?php

use App\Http\Controllers\Auth\SocialiteController;
use App\Http\Controllers\ProjectContextItemController;
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
    Route::livewire('inbox', 'pages::inbox')->name('inbox');
    Route::livewire('projects', 'pages::projects.index')->name('projects.index');
    Route::livewire('projects/{project}/context', 'pages::projects.context.index')->name('projects.context.index');
    Route::get('projects/{project}/context-items', ProjectContextItemController::class)->name('projects.context-items.index');
    Route::livewire('projects/{project}', 'pages::projects.show')->name('projects.show');
    Route::livewire('projects/{project}/features/{feature}', 'pages::features.show')->name('features.show');
    Route::livewire('stories', 'pages::stories.index')->name('stories.index');
    Route::livewire('stories/create', 'pages::stories.create')->name('stories.create');
    Route::livewire('stories/{story}', 'pages::stories.show')->name('stories.show');
    Route::livewire('runs', 'pages::runs.index')->name('runs.index');
    Route::livewire('events', 'pages::events.index')->name('events.index');
    Route::livewire('repos', 'pages::repos.index')->name('repos.index');
});

require __DIR__.'/settings.php';
