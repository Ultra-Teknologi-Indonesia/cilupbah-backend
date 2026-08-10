<?php

namespace App\Services;

use App\Models\TrackingItem;
use Illuminate\Database\Eloquent\Collection;

class TrackingService
{
    public function list(array $filters): Collection
    {
        return $this->filteredQuery($filters)
            ->orderByRaw("CASE source WHEN 'legacy' THEN 0 WHEN 'epic' THEN 1 ELSE 2 END")
            ->orderBy('domain')
            ->orderBy('id')
            ->get();
    }

    public function exportList(array $filters): Collection
    {
        return $this->filteredQuery($filters)
            ->orderBy('domain')
            ->orderBy('id')
            ->get();
    }

    public function filterOptions(): array
    {
        return [
            'domains' => TrackingItem::query()->distinct()->orderBy('domain')->pluck('domain'),
            'pics' => TrackingItem::query()->whereNotNull('pic')->distinct()->orderBy('pic')->pluck('pic'),
            'statuses' => TrackingItem::STATUSES,
            'sources' => ['legacy', 'epic', 'omnichannel'],
        ];
    }

    public function update(TrackingItem $item, array $validated): TrackingItem
    {
        $item->fill($validated)->save();

        return $item->fresh();
    }

    public function summary(): array
    {
        $base = fn () => TrackingItem::query();
        $byStatus = fn ($q) => [
            'total' => (clone $q)->count(),
            'done' => (clone $q)->where('status', 'done')->count(),
            'in_progress' => (clone $q)->where('status', 'in_progress')->count(),
            'todo' => (clone $q)->where('status', 'todo')->count(),
            'blocked' => (clone $q)->where('status', 'blocked')->count(),
        ];

        $perDomain = [];
        foreach (TrackingItem::query()->distinct()->orderBy('domain')->pluck('domain') as $dom) {
            $perDomain[$dom] = $byStatus($base()->where('domain', $dom));
        }

        $perPic = [];
        foreach (TrackingItem::PICS as $pic) {
            $perPic[$pic] = $byStatus($base()->where('pic', $pic));
        }

        return [
            'overall' => $byStatus($base()),
            'per_domain' => $perDomain,
            'per_pic' => $perPic,
        ];
    }

    private function filteredQuery(array $filters)
    {
        return TrackingItem::query()
            ->when(! empty($filters['domain']), fn ($q) => $q->where('domain', $filters['domain']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['pic']), fn ($q) => $q->where('pic', $filters['pic']))
            ->when(! empty($filters['source']), fn ($q) => $q->where('source', $filters['source']))
            ->when(! empty($filters['q']), function ($q) use ($filters) {
                $term = '%'.$filters['q'].'%';
                $q->where(fn ($w) => $w->where('endpoint', 'ilike', $term)->orWhere('function_id', 'ilike', $term));
            });
    }
}
