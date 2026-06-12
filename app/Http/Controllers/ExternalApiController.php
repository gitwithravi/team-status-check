<?php

namespace App\Http\Controllers;

use App\Models\DailyTask;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExternalApiController extends Controller
{
    public function teamMembers(): JsonResponse
    {
        return response()->json([
            'members' => User::query()
                ->where('role', User::ROLE_MEMBER)
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
        ]);
    }

    public function tasks(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'member_id' => ['required', 'integer', 'exists:users,id'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $member = User::query()
            ->where('role', User::ROLE_MEMBER)
            ->where('active', true)
            ->findOrFail($filters['member_id']);

        return response()->json([
            'member' => $member->only(['id', 'name', 'email']),
            'date' => $filters['date'],
            'tasks' => DailyTask::query()
                ->where('user_id', $member->id)
                ->whereDate('work_date', $filters['date'])
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['id', 'work_date', 'project_name', 'title', 'notes', 'status', 'created_at', 'updated_at']),
        ]);
    }
}
