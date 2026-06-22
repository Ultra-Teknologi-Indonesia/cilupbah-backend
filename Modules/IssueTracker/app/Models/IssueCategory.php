<?php

namespace Modules\IssueTracker\Models;

use Illuminate\Database\Eloquent\Model;

class IssueCategory extends Model
{
    protected $table = 'issue_tracker_categories';

    protected $fillable = ['name', 'platform', 'is_active', 'sort_order'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
