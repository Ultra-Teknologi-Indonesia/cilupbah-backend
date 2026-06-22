<?php

namespace Modules\IssueTracker\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssueAttachment extends Model
{
    protected $table = 'issue_tracker_attachments';

    protected $fillable = [
        'issue_id',
        'comment_id',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
    ];

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'issue_id');
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(IssueComment::class, 'comment_id');
    }
}
