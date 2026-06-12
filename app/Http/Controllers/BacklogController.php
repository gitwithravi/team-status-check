<?php

namespace App\Http\Controllers;

use App\Models\BacklogTask;
use App\Models\DailyTask;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BacklogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            $backlogs = BacklogTask::query()
                ->with('team:id,name')
                ->latest()
                ->get();
        } elseif ($user->isTeamManager()) {
            $teamIds = $user->managedTeams()->pluck('id');
            $backlogs = BacklogTask::query()
                ->whereIn('team_id', $teamIds)
                ->with('team:id,name')
                ->latest()
                ->get();
        } else {
            $teamIds = $user->teams()->pluck('teams.id');
            $backlogs = BacklogTask::query()
                ->whereIn('team_id', $teamIds)
                ->with('team:id,name')
                ->latest()
                ->get();
        }

        return response()->json([
            'backlogs' => $backlogs,
            'teams' => $user->isAdmin()
                ? Team::query()->orderBy('name')->get(['id', 'name'])
                : ($user->isTeamManager()
                    ? $user->managedTeams()->orderBy('name')->get(['id', 'name'])
                    : []),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->isTeamManager(), 403, 'Unauthorized action.');

        $data = $request->validate([
            'project_name' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'team_id' => [
                'required',
                'integer',
                Rule::exists('teams', 'id'),
            ],
        ]);

        if ($user->isTeamManager()) {
            abort_unless(
                $user->managedTeams()->whereKey($data['team_id'])->exists(),
                403,
                'You do not manage this team.'
            );
        }

        $backlogTask = BacklogTask::create($data);

        return response()->json([
            'backlog' => $backlogTask->load('team:id,name')
        ], 201);
    }

    public function move(Request $request, BacklogTask $backlogTask): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isMember(), 403, 'Only team members can move tasks.');

        $belongsToTeam = $user->teams()->whereKey($backlogTask->team_id)->exists();
        abort_unless($belongsToTeam, 403, 'You are not a member of the team assigned to this task.');

        $task = DB::transaction(function () use ($user, $backlogTask) {
            $dailyTask = $user->dailyTasks()->create([
                'work_date' => today(config('app.timezone'))->toDateString(),
                'project_name' => $backlogTask->project_name,
                'title' => $backlogTask->title,
                'notes' => $backlogTask->description,
                'status' => 'planned',
            ]);

            $backlogTask->delete();

            return $dailyTask;
        });

        return response()->json([
            'message' => 'Task moved to today.',
            'task' => $task,
        ]);
    }

    public function destroy(Request $request, BacklogTask $backlogTask): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->isTeamManager(), 403, 'Unauthorized action.');

        if ($user->isTeamManager()) {
            abort_unless(
                $user->managedTeams()->whereKey($backlogTask->team_id)->exists(),
                403,
                'You do not manage this team.'
            );
        }

        $backlogTask->delete();

        return response()->json(['message' => 'Backlog task deleted.']);
    }
}
