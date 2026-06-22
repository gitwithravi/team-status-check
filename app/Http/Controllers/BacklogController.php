<?php

namespace App\Http\Controllers;

use App\Models\BacklogTask;
use App\Models\DailyTask;
use App\Models\Team;
use App\Models\User;
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
                ->whereNull('assigned_user_id')
                ->with('team:id,name')
                ->latest()
                ->get();
        } elseif ($user->isTeamManager()) {
            $teamIds = $user->managedTeams()->pluck('id');
            $backlogs = BacklogTask::query()
                ->whereIn('team_id', $teamIds)
                ->whereNull('assigned_user_id')
                ->with('team:id,name')
                ->latest()
                ->get();
        } else {
            $teamIds = $user->teams()->pluck('teams.id');
            $backlogs = BacklogTask::query()
                ->whereIn('team_id', $teamIds)
                ->whereNull('assigned_user_id')
                ->with('team:id,name')
                ->latest()
                ->get();
        }

        return response()->json([
            'backlogs' => $backlogs,
            'teams' => $user->isAdmin()
                ? Team::query()
                    ->with(['members' => fn ($query) => $query
                        ->where('role', User::ROLE_MEMBER)
                        ->where('active', true)
                        ->orderBy('name')
                    ])
                    ->orderBy('name')
                    ->get(['id', 'name'])
                : ($user->isTeamManager()
                    ? $user->managedTeams()
                        ->with(['members' => fn ($query) => $query
                            ->where('role', User::ROLE_MEMBER)
                            ->where('active', true)
                            ->orderBy('name')
                        ])
                        ->orderBy('name')
                        ->get(['id', 'name'])
                    : []),
        ]);
    }

    public function myBacklog(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isMember(), 403, 'Only team members can view personal backlog.');

        return response()->json([
            'backlogs' => BacklogTask::query()
                ->where('assigned_user_id', $user->id)
                ->whereIn('team_id', $user->teams()->pluck('teams.id'))
                ->with('team:id,name')
                ->latest()
                ->get(),
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
            'assigned_user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id'),
            ],
        ]);

        if ($user->isTeamManager()) {
            abort_unless(
                $user->managedTeams()->whereKey($data['team_id'])->exists(),
                403,
                'You do not manage this team.'
            );
        }

        if (($data['assigned_user_id'] ?? null) !== null) {
            $isAssignableMember = Team::query()
                ->whereKey($data['team_id'])
                ->whereHas('members', fn ($query) => $query
                    ->where('users.id', $data['assigned_user_id'])
                    ->where('role', User::ROLE_MEMBER)
                    ->where('active', true))
                ->exists();

            abort_unless($isAssignableMember, 422, 'Assigned user must be an active member of the selected team.');
        }

        $backlogTask = BacklogTask::create($data);

        return response()->json([
            'backlog' => $backlogTask->load('team:id,name', 'assignedUser:id,name')
        ], 201);
    }

    public function move(Request $request, BacklogTask $backlogTask): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isMember(), 403, 'Only team members can move tasks.');

        $belongsToTeam = $user->teams()->whereKey($backlogTask->team_id)->exists();
        abort_unless($belongsToTeam, 403, 'You are not a member of the team assigned to this task.');
        abort_if(
            $backlogTask->assigned_user_id !== null && $backlogTask->assigned_user_id !== $user->id,
            403,
            'This task is assigned to another team member.'
        );

        $task = DB::transaction(function () use ($user, $backlogTask) {
            $dailyTask = $user->dailyTasks()->create([
                'work_date' => today(config('app.timezone'))->toDateString(),
                'project_name' => $backlogTask->project_name,
                'title' => $backlogTask->title,
                'notes' => $backlogTask->description,
                'status' => 'planned',
                'team_id' => $backlogTask->team_id,
                'backlog_assigned_user_id' => $backlogTask->assigned_user_id,
            ]);

            $backlogTask->delete();

            return $dailyTask;
        });

        return response()->json([
            'message' => 'Task moved to today.',
            'task' => $task,
        ]);
    }

    public function returnToBacklog(Request $request, DailyTask $task): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isMember(), 403, 'Only team members can return tasks to backlog.');
        abort_unless($task->user_id === $user->id, 403, 'Unauthorized.');
        abort_unless($task->team_id !== null, 422, 'This task did not originate from the backlog.');
        abort_if($task->work_date->toDateString() !== today(config('app.timezone'))->toDateString(), 422, 'Only today\'s tasks can be returned to backlog.');

        $belongsToTeam = $user->teams()->whereKey($task->team_id)->exists();
        abort_unless($belongsToTeam, 403, 'You are not a member of the team assigned to this task.');

        $backlogTask = DB::transaction(function () use ($task) {
            $backlog = BacklogTask::create([
                'team_id' => $task->team_id,
                'project_name' => $task->project_name,
                'title' => $task->title,
                'description' => $task->notes,
                'assigned_user_id' => $task->backlog_assigned_user_id,
            ]);

            $task->delete();

            return $backlog;
        });

        return response()->json([
            'message' => 'Task returned to backlog.',
            'backlog' => $backlogTask->load('team:id,name', 'assignedUser:id,name'),
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
