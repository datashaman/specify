<?php

use App\Http\Controllers\Webhooks\GithubWebhookController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::post('webhooks/github/{repo}', GithubWebhookController::class)->name('webhooks.github');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::livewire('inbox', 'pages::inbox')->name('inbox');
    Route::livewire('stories/create', 'pages::stories.create')->name('stories.create');
});

require __DIR__.'/settings.php';
