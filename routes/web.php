<?php

use App\Http\Controllers\Webhooks\GithubWebhookController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::post('webhooks/github/{repo}', GithubWebhookController::class)->name('webhooks.github');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
    Route::livewire('inbox', 'pages::inbox')->name('inbox');
    Route::livewire('projects', 'pages::projects.index')->name('projects.index');
    Route::livewire('projects/{project}', 'pages::projects.show')->name('projects.show');
    Route::livewire('projects/{project}/repos', 'pages::projects.repos')->name('projects.repos');
    Route::livewire('projects/{project}/features/{feature}', 'pages::features.show')->name('features.show');
    Route::livewire('stories', 'pages::stories.index')->name('stories.index');
    Route::livewire('stories/create', 'pages::stories.create')->name('stories.create');
    Route::livewire('stories/{story}', 'pages::stories.show')->name('stories.show');
    Route::livewire('runs', 'pages::runs.index')->name('runs.index');
});

require __DIR__.'/settings.php';
