<?php

namespace Modules\IssueTracker\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssueActivity extends Model
{
    protected $table = 'issue_tracker_activities';

    protected $fillable = [
        'issue_id',
        'action',
        'actor_name',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'issue_id');
    }
}
