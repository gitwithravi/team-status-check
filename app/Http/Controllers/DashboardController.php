<?php

namespace App\Http\Controllers;

use App\Models\DailyTask;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'member_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', Rule::in(DailyTask::STATUSES)],
        ]);

        $date = $filters['date'] ?? today(config('app.timezone'))->toDateString();

        $members = User::query()
            ->where('role', 'member')
            ->when($filters['member_id'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->with(['dailyTasks' => fn ($query) => $query
                ->whereDate('work_date', $date)
                ->when($filters['status'] ?? null, fn ($taskQuery, $status) => $taskQuery->where('status', $status))
                ->latest()])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'active']);

        return response()->json([
            'date' => $date,
            'statuses' => DailyTask::STATUSES,
            'members' => $members->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'active' => $member->active,
                'counts' => collect(DailyTask::STATUSES)
                    ->mapWithKeys(fn ($status) => [$status => $member->dailyTasks->where('status', $status)->count()]),
                'tasks' => $member->dailyTasks->values(),
            ]),
        ]);
    }
}
