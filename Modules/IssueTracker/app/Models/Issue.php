<?php

namespace Modules\IssueTracker\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Issue extends Model
{
    protected $table = 'issue_tracker_issues';

    protected $fillable = [
        'tracking_token',
        'title',
        'description',
        'platform',
        'category_id',
        'priority',
        'status',
        'reporter_name',
        'reporter_contact',
        'assigned_to',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(IssueCategory::class, 'category_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(IssueComment::class, 'issue_id');
    }

    public function publicComments(): HasMany
    {
        return $this->hasMany(IssueComment::class, 'issue_id')->where('is_internal', false);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(IssueAttachment::class, 'issue_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(IssueActivity::class, 'issue_id');
    }

    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopePlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }
}
