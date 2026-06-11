<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'app')->name('home');

Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth');

Route::middleware('auth')->group(function () {
    Route::get('/session', [AuthController::class, 'user']);

    Route::get('/tasks', [TaskController::class, 'index'])->middleware('role:member');
    Route::post('/tasks', [TaskController::class, 'store'])->middleware('role:member');
    Route::put('/tasks/{task}', [TaskController::class, 'update'])->middleware('role:member');
    Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->middleware('role:member');

    Route::get('/admin/dashboard', DashboardController::class)->middleware('role:admin');
    Route::get('/admin/members', [MemberController::class, 'index'])->middleware('role:admin');
    Route::post('/admin/members', [MemberController::class, 'store'])->middleware('role:admin');
    Route::put('/admin/members/{member}', [MemberController::class, 'update'])->middleware('role:admin');
});

Route::view('/{any}', 'app')->where('any', '.*');
