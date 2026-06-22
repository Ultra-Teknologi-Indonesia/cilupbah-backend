<?php

namespace Modules\IssueTracker\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IssueComment extends Model
{
    protected $table = 'issue_tracker_comments';

    protected $fillable = [
        'issue_id',
        'body',
        'author_type',
        'author_name',
        'is_internal',
    ];

    protected $casts = [
        'is_internal' => 'boolean',
    ];

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'issue_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(IssueAttachment::class, 'comment_id');
    }
}
