<?php

use App\Http\Controllers\Api\ContextAssetUploadController;
use App\Http\Middleware\AuthenticateApiKey;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticateApiKey::class)->group(function () {
    Route::post('assets/upload', ContextAssetUploadController::class)->name('api.assets.upload');
});
