<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ManagerDashboardController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\BacklogController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'app')->name('home');

Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth');

Route::middleware('auth')->group(function () {
    Route::get('/session', [AuthController::class, 'user']);
    Route::put('/password', [AuthController::class, 'updatePassword']);

    Route::get('/tasks', [TaskController::class, 'index'])->middleware('role:member');
    Route::get('/tasks/history', [TaskController::class, 'history'])->middleware('role:member');
    Route::post('/tasks', [TaskController::class, 'store'])->middleware('role:member');
    Route::put('/tasks/{task}', [TaskController::class, 'update'])->middleware('role:member');
    Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->middleware('role:member');

    Route::get('/backlog', [BacklogController::class, 'index']);
    Route::post('/backlog', [BacklogController::class, 'store'])->middleware('role:admin,team-manager');
    Route::post('/backlog/{backlogTask}/move', [BacklogController::class, 'move'])->middleware('role:member');
    Route::post('/tasks/{task}/backlog', [BacklogController::class, 'returnToBacklog'])->middleware('role:member');
    Route::delete('/backlog/{backlogTask}', [BacklogController::class, 'destroy'])->middleware('role:admin,team-manager');

    Route::get('/admin/dashboard', DashboardController::class)->middleware('role:admin');
    Route::get('/admin/members', [MemberController::class, 'index'])->middleware('role:admin');
    Route::post('/admin/members', [MemberController::class, 'store'])->middleware('role:admin');
    Route::put('/admin/members/{member}', [MemberController::class, 'update'])->middleware('role:admin');
    Route::get('/admin/teams', [TeamController::class, 'index'])->middleware('role:admin');
    Route::post('/admin/teams', [TeamController::class, 'store'])->middleware('role:admin');
    Route::put('/admin/teams/{team}', [TeamController::class, 'update'])->middleware('role:admin');

    Route::get('/manager/dashboard', ManagerDashboardController::class)->middleware('role:team-manager');

    Route::get('/reports/filters', [ReportController::class, 'filters'])->middleware('role:admin,team-manager');
    Route::get('/reports/preview', [ReportController::class, 'preview'])->middleware('role:admin,team-manager');
    Route::get('/reports/export', [ReportController::class, 'export'])->middleware('role:admin,team-manager');
});

Route::view('/{any}', 'app')->where('any', '.*');

