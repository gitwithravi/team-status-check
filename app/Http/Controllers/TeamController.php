<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeamController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'teams' => Team::query()
                ->with([
                    'manager:id,name,email,active',
                    'members' => fn ($query) => $query->orderBy('name')->select('users.id', 'users.name', 'users.email', 'users.active'),
                ])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateTeam($request);

        $team = Team::query()->create([
            'name' => $data['name'],
            'manager_id' => $data['manager_id'],
        ]);

        $team->members()->sync($data['member_ids'] ?? []);

        return response()->json([
            'team' => $team->load(['manager:id,name,email,active', 'members:id,name,email,active']),
        ], 201);
    }

    public function update(Request $request, Team $team): JsonResponse
    {
        $data = $this->validateTeam($request, $team);

        $team->update([
            'name' => $data['name'],
            'manager_id' => $data['manager_id'],
        ]);

        $team->members()->sync($data['member_ids'] ?? []);

        return response()->json([
            'team' => $team->fresh()->load(['manager:id,name,email,active', 'members:id,name,email,active']),
        ]);
    }

    private function validateTeam(Request $request, ?Team $team = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('teams', 'name')->ignore($team?->id)],
            'manager_id' => [
                'required',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', User::ROLE_TEAM_MANAGER)),
            ],
            'member_ids' => ['sometimes', 'array'],
            'member_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', User::ROLE_MEMBER)),
            ],
        ]);
    }
}
