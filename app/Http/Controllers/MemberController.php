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
        return response()->json([
            'members' => User::query()
                ->where('role', 'member')
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'active', 'created_at']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $member = User::query()->create([
            ...$data,
            'role' => 'member',
            'active' => $data['active'] ?? true,
        ]);

        return response()->json(['member' => $member], 201);
    }

    public function update(Request $request, User $member): JsonResponse
    {
        abort_unless($member->role === 'member', 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($member->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'active' => ['required', 'boolean'],
        ]);

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $member->update($data);

        return response()->json(['member' => $member->fresh()]);
    }
}
