<?php

namespace App\Http\Controllers;

use App\Models\DailyTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'tasks' => $request->user()->dailyTasks()
                ->whereDate('work_date', $this->today())
                ->latest()
                ->get(),
            'today' => $this->today(),
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date'],
        ]);

        return response()->json([
            'tasks' => $request->user()->dailyTasks()
                ->when($filters['date'] ?? null, fn ($query, $date) => $query->whereDate('work_date', $date))
                ->orderByDesc('work_date')
                ->latest()
                ->get(),
            'today' => $this->today(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateTask($request);

        $task = $request->user()->dailyTasks()->create([
            ...$data,
            'work_date' => $this->today(),
        ]);

        return response()->json(['task' => $task], 201);
    }

    public function update(Request $request, DailyTask $task): JsonResponse
    {
        $this->authorizeTask($request, $task);

        $task->update($this->validateTask($request));

        return response()->json(['task' => $task->fresh()]);
    }

    public function destroy(Request $request, DailyTask $task): JsonResponse
    {
        $this->authorizeTask($request, $task);

        $task->delete();

        return response()->json(['message' => 'Task deleted.']);
    }

    private function validateTask(Request $request): array
    {
        return $request->validate([
            'project_name' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(DailyTask::STATUSES)],
        ]);
    }

    private function authorizeTask(Request $request, DailyTask $task): void
    {
        abort_unless($task->user_id === $request->user()->id, 403);
        abort_if($task->work_date->toDateString() !== $this->today(), 422, 'Only current-day tasks can be changed.');
    }

    private function today(): string
    {
        return today(config('app.timezone'))->toDateString();
    }
}
