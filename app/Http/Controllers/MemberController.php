<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MemberController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::query()
            ->whereIn('role', [User::ROLE_MEMBER, User::ROLE_TEAM_MANAGER])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'active', 'created_at']);

        return response()->json([
            'users' => $users,
            'members' => $users->where('role', User::ROLE_MEMBER)->values(),
            'managers' => $users->where('role', User::ROLE_TEAM_MANAGER)->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['sometimes', Rule::in([User::ROLE_MEMBER, User::ROLE_TEAM_MANAGER])],
            'active' => ['sometimes', 'boolean'],
        ]);

        $user = User::query()->create([
            ...$data,
            'role' => $data['role'] ?? User::ROLE_MEMBER,
            'active' => $data['active'] ?? true,
        ]);

        return response()->json(['member' => $user, 'user' => $user], 201);
    }

    public function update(Request $request, User $member): JsonResponse
    {
        abort_unless(in_array($member->role, [User::ROLE_MEMBER, User::ROLE_TEAM_MANAGER], true), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($member->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['required', Rule::in([User::ROLE_MEMBER, User::ROLE_TEAM_MANAGER])],
            'active' => ['required', 'boolean'],
        ]);

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $member->update($data);

        return response()->json(['member' => $member->fresh(), 'user' => $member->fresh()]);
    }
}
