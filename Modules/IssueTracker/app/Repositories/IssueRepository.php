<?php

namespace Modules\IssueTracker\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\IssueTracker\Models\Issue;
use Modules\IssueTracker\Models\IssueCategory;

class IssueRepository
{
    public function getActiveCategories(): Collection
    {
        return IssueCategory::active()->orderBy('sort_order')->get();
    }

    public function findByTrackingToken(string $token): Issue
    {
        return Issue::where('tracking_token', $token)->firstOrFail();
    }

    public function getStatusCounts(): array
    {
        return [
            'total' => Issue::count(),
            'open' => Issue::where('status', 'open')->count(),
            'in_review' => Issue::where('status', 'in_review')->count(),
            'in_progress' => Issue::where('status', 'in_progress')->count(),
            'resolved' => Issue::where('status', 'resolved')->count(),
            'closed' => Issue::where('status', 'closed')->count(),
        ];
    }

    public function getPaginated(array $filters): LengthAwarePaginator
    {
        return $this->buildFilteredQuery($filters)
            ->paginate(request('per_page', 20))
            ->withQueryString();
    }

    public function getForExport(?string $status): Collection
    {
        return $this->buildFilteredQuery(['status' => $status])->get();
    }

    protected function buildFilteredQuery(array $filters)
    {
        $query = Issue::with('category')->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['platform'])) {
            $query->where('platform', $filters['platform']);
        }
        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }
        $query->allowedSearch('title', 'reporter_name', 'tracking_token');

        return $query;
    }
}
