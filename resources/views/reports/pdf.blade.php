<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Team Status Report</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #1e293b;
            font-size: 10pt;
            line-height: 1.4;
            margin: 0;
            padding: 0;
        }
        @page {
            margin: 1.5cm;
        }
        .header {
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 12px;
            margin-bottom: 20px;
        }
        .logo-title {
            font-size: 20pt;
            font-weight: bold;
            color: #0f172a;
            letter-spacing: -0.5px;
        }
        .meta-table {
            width: 100%;
            margin-top: 8px;
        }
        .meta-table td {
            font-size: 9pt;
            color: #475569;
            padding: 2px 0;
        }
        .summary-container {
            margin-bottom: 25px;
        }
        .summary-title {
            font-size: 12pt;
            font-weight: bold;
            margin-bottom: 8px;
            color: #334155;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .summary-table {
            width: 100%;
            border-collapse: collapse;
        }
        .summary-card {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            text-align: center;
            padding: 10px;
        }
        .summary-card.total { border-top: 3px solid #6366f1; }
        .summary-card.done { border-top: 3px solid #10b981; }
        .summary-card.in-progress { border-top: 3px solid #0ea5e9; }
        .summary-card.blocked { border-top: 3px solid #f43f5e; }
        .summary-card.planned { border-top: 3px solid #64748b; }
        
        .summary-card-val {
            font-size: 16pt;
            font-weight: bold;
            color: #0f172a;
        }
        .summary-card-lbl {
            font-size: 7.5pt;
            text-transform: uppercase;
            color: #64748b;
            margin-top: 2px;
        }
        .member-section {
            margin-bottom: 30px;
            page-break-inside: avoid;
        }
        .member-header {
            background-color: #f1f5f9;
            border-left: 4px solid #4f46e5;
            padding: 8px 12px;
            margin-bottom: 10px;
            border-radius: 0 4px 4px 0;
        }
        .member-name {
            font-size: 12pt;
            font-weight: bold;
            color: #0f172a;
        }
        .member-email {
            font-size: 8.5pt;
            color: #64748b;
        }
        .tasks-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 5px;
        }
        .tasks-table th, .tasks-table td {
            text-align: left;
            padding: 7px 9px;
            font-size: 9pt;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }
        .tasks-table th {
            background-color: #f8fafc;
            color: #475569;
            font-weight: 600;
            border-bottom: 2px solid #e2e8f0;
            text-transform: uppercase;
            font-size: 8pt;
            letter-spacing: 0.5px;
        }
        .pill {
            display: inline-block;
            padding: 2px 6px;
            font-size: 7pt;
            font-weight: bold;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .pill.planned { background-color: #f1f5f9; color: #475569; }
        .pill.in_progress { background-color: #e0f2fe; color: #0369a1; }
        .pill.done { background-color: #d1fae5; color: #065f46; }
        .pill.blocked { background-color: #ffe4e6; color: #9f1239; }
        .footer {
            position: fixed;
            bottom: -30px;
            left: 0px;
            right: 0px;
            height: 20px;
            text-align: center;
            font-size: 8pt;
            color: #94a3b8;
            border-top: 1px solid #f1f5f9;
            padding-top: 5px;
        }
    </style>
</head>
<body>
    <div class="header">
        <table style="width: 100%">
            <tr>
                <td><span class="logo-title">Team Status Report</span></td>
                <td style="text-align: right; font-size: 9pt; color: #64748b; vertical-align: bottom;">
                    Generated: {{ $generated_at }}
                </td>
            </tr>
        </table>
        
        <table class="meta-table">
            <tr>
                <td style="width: 50%">
                    <strong>Period:</strong> {{ $start_date }} to {{ $end_date }}
                </td>
                <td style="width: 50%; text-align: right;">
                    <strong>Filtered By:</strong> 
                    @if(isset($filtered_team) && $filtered_team !== 'All Teams')
                        Team: {{ $filtered_team }}
                    @endif
                    @if(isset($filtered_member) && $filtered_member !== 'All Members')
                        @if(isset($filtered_team) && $filtered_team !== 'All Teams') | @endif
                        Member: {{ $filtered_member }}
                    @endif
                    @if((!isset($filtered_team) || $filtered_team === 'All Teams') && (!isset($filtered_member) || $filtered_member === 'All Members'))
                        All Active Teams & Members
                    @endif
                </td>
            </tr>
            <tr>
                <td><strong>Generated By:</strong> {{ $generated_by }}</td>
                <td></td>
            </tr>
        </table>
    </div>

    <div class="summary-container">
        <div class="summary-title">Summary Metrics</div>
        <table style="width: 100%;">
            <tr>
                <td class="summary-card total" style="width: 18%;">
                    <div class="summary-card-val">{{ $total_tasks }}</div>
                    <div class="summary-card-lbl">Total Tasks</div>
                </td>
                <td style="width: 2%;"></td>
                <td class="summary-card done" style="width: 18%;">
                    <div class="summary-card-val">{{ $status_counts['done'] ?? 0 }}</div>
                    <div class="summary-card-lbl">Done</div>
                </td>
                <td style="width: 2%;"></td>
                <td class="summary-card in-progress" style="width: 18%;">
                    <div class="summary-card-val">{{ $status_counts['in_progress'] ?? 0 }}</div>
                    <div class="summary-card-lbl">In Progress</div>
                </td>
                <td style="width: 2%;"></td>
                <td class="summary-card blocked" style="width: 18%;">
                    <div class="summary-card-val">{{ $status_counts['blocked'] ?? 0 }}</div>
                    <div class="summary-card-lbl">Blocked</div>
                </td>
                <td style="width: 2%;"></td>
                <td class="summary-card planned" style="width: 18%;">
                    <div class="summary-card-val">{{ $status_counts['planned'] ?? 0 }}</div>
                    <div class="summary-card-lbl">Planned</div>
                </td>
            </tr>
        </table>
    </div>

    @foreach($members as $member)
        <div class="member-section">
            <div class="member-header">
                <table style="width: 100%">
                    <tr>
                        <td>
                            <div class="member-name">{{ $member['name'] }}</div>
                            <div class="member-email">{{ $member['email'] }}</div>
                        </td>
                        <td style="text-align: right; font-size: 8.5pt; color: #475569; vertical-align: bottom;">
                            <strong>Done:</strong> {{ $member['counts']['done'] }} |
                            <strong>In Progress:</strong> {{ $member['counts']['in_progress'] }} |
                            <strong>Blocked:</strong> {{ $member['counts']['blocked'] }} |
                            <strong>Planned:</strong> {{ $member['counts']['planned'] }}
                        </td>
                    </tr>
                </table>
            </div>

            @if(count($member['tasks']) > 0)
                <table class="tasks-table">
                    <thead>
                        <tr>
                            <th style="width: 12%;">Date</th>
                            <th style="width: 18%;">Project</th>
                            <th style="width: 56%;">Task Details</th>
                            <th style="width: 14%; text-align: center;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($member['tasks'] as $task)
                            <tr>
                                <td>{{ \Carbon\Carbon::parse($task->work_date)->format('Y-m-d') }}</td>
                                <td><strong>{{ $task->project_name ?? 'General' }}</strong></td>
                                <td>
                                    <strong>{{ $task->title }}</strong>
                                    @if($task->notes)
                                        <div style="margin-top: 4px; font-size: 8.5pt; color: #475569; font-style: italic;">
                                            {!! nl2br(e($task->notes)) !!}
                                        </div>
                                    @endif
                                </td>
                                <td style="text-align: center;">
                                    <span class="pill {{ $task->status }}">{{ str_replace('_', ' ', $task->status) }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div style="padding: 12px; text-align: center; color: #64748b; font-size: 9pt; border: 1px dashed #cbd5e1; border-radius: 4px;">
                    No tasks logged for this member during the selected period.
                </div>
            @endif
        </div>
    @endforeach

    <div class="footer">
        Team Status Report &middot; Confidential
    </div>
</body>
</html>
