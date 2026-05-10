<?php

use App\Http\Controllers\Api\ContextAssetUploadController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('assets/upload', ContextAssetUploadController::class)->name('api.assets.upload');
});
