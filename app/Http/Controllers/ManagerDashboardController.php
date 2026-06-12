<?php

namespace App\Http\Controllers;

use App\Models\DailyTask;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ManagerDashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', Rule::in(DailyTask::STATUSES)],
        ]);

        $date = $filters['date'] ?? today(config('app.timezone'))->toDateString();

        $teams = $request->user()->managedTeams()
            ->with(['members' => fn ($query) => $query
                ->orderBy('name')
                ->with(['dailyTasks' => fn ($taskQuery) => $taskQuery
                    ->whereDate('work_date', $date)
                    ->when($filters['status'] ?? null, fn ($statusQuery, $status) => $statusQuery->where('status', $status))
                    ->latest()])
                ->select('users.id', 'users.name', 'users.email', 'users.active')])
            ->orderBy('name')
            ->get();

        return response()->json([
            'date' => $date,
            'statuses' => DailyTask::STATUSES,
            'teams' => $teams->map(fn (Team $team) => [
                'id' => $team->id,
                'name' => $team->name,
                'members' => $team->members->map(fn (User $member) => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'active' => $member->active,
                    'counts' => collect(DailyTask::STATUSES)
                        ->mapWithKeys(fn ($status) => [$status => $member->dailyTasks->where('status', $status)->count()]),
                    'tasks' => $member->dailyTasks->values(),
                ])->values(),
            ])->values(),
        ]);
    }
}
