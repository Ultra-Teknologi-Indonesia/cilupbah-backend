<?php

namespace Modules\Webhook\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Webhook\Models\WebhookSubscription;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class WebhookSubscriptionRepository
{
    /** Listing subscription — Spatie Query Builder. */
    public function paginate(): LengthAwarePaginator
    {
        return QueryBuilder::for(WebhookSubscription::class)
            ->allowedFilters(
                AllowedFilter::exact('event'),
                AllowedFilter::exact('is_active'),
            )
            ->allowedSorts('event', 'created_at')
            ->defaultSort('-created_at')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }

    public function findById(string $id): ?WebhookSubscription
    {
        return WebhookSubscription::find($id);
    }

    public function create(array $data): WebhookSubscription
    {
        return WebhookSubscription::create($data);
    }

    public function update(WebhookSubscription $subscription, array $data): WebhookSubscription
    {
        $subscription->update($data);

        return $subscription;
    }

    public function delete(WebhookSubscription $subscription): void
    {
        $subscription->delete();
    }

    /** Subscriber aktif untuk satu event (termasuk wildcard '*'). */
    public function activeForEvent(string $event): Collection
    {
        return WebhookSubscription::query()
            ->where('is_active', true)
            ->whereIn('event', [$event, '*'])
            ->get();
    }

    /** Daftar nama event yang punya subscriber aktif (untuk cache di hot-path). */
    public function activeEventNames(): array
    {
        return WebhookSubscription::query()
            ->where('is_active', true)
            ->distinct()
            ->pluck('event')
            ->all();
    }
}
