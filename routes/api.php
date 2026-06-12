<?php

use App\Http\Controllers\ExternalApiController;
use Illuminate\Support\Facades\Route;

Route::middleware('external-api')->group(function () {
    Route::get('/team-members', [ExternalApiController::class, 'teamMembers']);
    Route::get('/tasks', [ExternalApiController::class, 'tasks']);
});
