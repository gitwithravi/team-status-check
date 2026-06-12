<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyTask extends Model
{
    public const STATUSES = ['planned', 'in_progress', 'done', 'blocked'];

    protected $fillable = [
        'user_id',
        'work_date',
        'project_name',
        'title',
        'notes',
        'status',
        'team_id',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
