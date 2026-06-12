<?php

namespace App\Http\Controllers;

use App\Models\DailyTask;
use App\Models\Team;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    /**
     * Get the available teams and members for filters depending on the logged-in user's role.
     */
    public function filters(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return response()->json([
                'teams' => Team::orderBy('name')->get(['id', 'name']),
                'members' => User::where('role', User::ROLE_MEMBER)->orderBy('name')->get(['id', 'name']),
            ]);
        }

        // Manager: Fetch only their managed teams and members in those teams
        $teams = $user->managedTeams()->orderBy('name')->get(['id', 'name']);
        $managedTeamIds = $teams->pluck('id');
        $members = User::where('role', User::ROLE_MEMBER)
            ->whereHas('teams', fn ($q) => $q->whereIn('team_id', $managedTeamIds))
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'teams' => $teams,
            'members' => $members,
        ]);
    }

    /**
     * Preview the report data in JSON format for rendering on screen.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $this->getReportData($request);
        return response()->json($data);
    }

    /**
     * Export the PDF report.
     */
    public function export(Request $request): Response
    {
        $data = $this->getReportData($request);

        $pdf = Pdf::loadView('reports.pdf', $data)
            ->setPaper('a4', 'portrait')
            ->setWarnings(false);

        $filename = 'work-report-' . $data['start_date'] . '-to-' . $data['end_date'] . '.pdf';
        
        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Consolidates report data based on parameters and security restrictions.
     */
    private function getReportData(Request $request): array
    {
        $user = $request->user();

        $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'member_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $teamId = $request->input('team_id');
        $memberId = $request->input('member_id');

        $query = User::query()->where('role', User::ROLE_MEMBER);

        // Apply team manager scope restriction
        if ($user->isTeamManager()) {
            $managedTeamIds = $user->managedTeams()->pluck('id');
            $query->whereHas('teams', fn ($q) => $q->whereIn('team_id', $managedTeamIds));

            // If team manager requested a specific member, verify they belong to managed teams
            if ($memberId) {
                $query->whereKey($memberId);
            }
        } else {
            // Admin filters
            if ($teamId) {
                $query->whereHas('teams', fn ($q) => $q->where('team_id', $teamId));
            }
            if ($memberId) {
                $query->whereKey($memberId);
            }
        }

        // Retrieve members with filtered daily tasks
        $members = $query->with(['dailyTasks' => function ($taskQuery) use ($startDate, $endDate) {
            $taskQuery->whereBetween('work_date', [$startDate, $endDate])
                      ->orderBy('work_date', 'asc')
                      ->orderBy('created_at', 'asc');
        }])->orderBy('name')->get();

        // Calculate summary counts
        $totalTasks = 0;
        $statusCounts = collect(DailyTask::STATUSES)->mapWithKeys(fn ($s) => [$s => 0])->toArray();

        $reportMembers = $members->map(function ($member) use (&$totalTasks, &$statusCounts) {
            $tasks = $member->dailyTasks;
            $totalTasks += $tasks->count();

            foreach ($tasks as $task) {
                if (isset($statusCounts[$task->status])) {
                    $statusCounts[$task->status]++;
                }
            }

            return [
                'name' => $member->name,
                'email' => $member->email,
                'tasks' => $tasks,
                'counts' => collect(DailyTask::STATUSES)
                    ->mapWithKeys(fn ($s) => [$s => $tasks->where('status', $s)->count()]),
            ];
        });

        // Resolve optional team name and member name for report subtitles
        $filteredTeamName = $teamId ? Team::find($teamId)?->name : 'All Teams';
        $filteredMemberName = $memberId ? User::find($memberId)?->name : 'All Members';

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'filtered_team' => $filteredTeamName,
            'filtered_member' => $filteredMemberName,
            'members' => $reportMembers,
            'total_tasks' => $totalTasks,
            'status_counts' => $statusCounts,
            'generated_at' => now(config('app.timezone'))->format('Y-m-d H:i:s'),
            'generated_by' => $user->name,
        ];
    }
}
