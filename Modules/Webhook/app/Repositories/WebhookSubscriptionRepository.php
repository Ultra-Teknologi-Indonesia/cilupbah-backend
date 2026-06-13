<?php

namespace Modules\Webhook\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Modules\Webhook\Models\WebhookSubscription;
use Modules\Webhook\Services\WebhookDispatcherService;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class WebhookSubscriptionRepository
{

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

    public function activeForEvent(string $event): Collection
    {
        return WebhookSubscription::query()
            ->where('is_active', true)
            ->whereIn('event', [$event, '*'])
            ->get();
    }

    public function activeEventNames(): array
    {
        return WebhookSubscription::query()
            ->where('is_active', true)
            ->distinct()
            ->pluck('event')
            ->all();
    }

    public function resetFailures(WebhookSubscription $subscription): void
    {
        if ((int) $subscription->consecutive_failures !== 0) {
            $subscription->update(['consecutive_failures' => 0]);
        }
    }

    public function registerFailureAndMaybeDisable(string $subscriptionId, int $threshold): void
    {
        $subscription = WebhookSubscription::find($subscriptionId);
        if (! $subscription) {
            return;
        }

        $subscription->increment('consecutive_failures');

        if ($subscription->is_active && $subscription->consecutive_failures >= $threshold) {
            $subscription->update(['is_active' => false]);
            WebhookDispatcherService::flushCache();
            Log::warning("Webhook subscription {$subscriptionId} dinonaktifkan otomatis setelah {$subscription->consecutive_failures} kegagalan beruntun.");
        }
    }
}
