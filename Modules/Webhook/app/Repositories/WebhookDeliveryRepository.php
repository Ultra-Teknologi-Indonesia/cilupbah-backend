<?php

namespace Modules\Webhook\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Webhook\Models\WebhookDelivery;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class WebhookDeliveryRepository
{

    public function paginateForSubscription(string $subscriptionId): LengthAwarePaginator
    {
        return QueryBuilder::for(WebhookDelivery::class)
            ->where('subscription_id', $subscriptionId)
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('event'),
            )
            ->allowedSorts('created_at', 'delivered_at', 'status')
            ->defaultSort('-created_at')
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    public function find(string $id): ?WebhookDelivery
    {
        return WebhookDelivery::find($id);
    }

    public function findWithSubscription(string $id): ?WebhookDelivery
    {
        return WebhookDelivery::with('subscription')->find($id);
    }

    public function firstOrCreateForEvent(string $subscriptionId, string $event, string $eventId, array $payload): WebhookDelivery
    {
        return WebhookDelivery::firstOrCreate(
            [
                'subscription_id' => $subscriptionId,
                'event_id' => $eventId,
            ],
            [
                'event' => $event,
                'payload' => $payload,
                'status' => WebhookDelivery::STATUS_PENDING,
            ]
        );
    }

    public function incrementAttempts(WebhookDelivery $delivery): void
    {
        $delivery->increment('attempts');
    }

    public function markSuccess(WebhookDelivery $delivery, int $statusCode): void
    {
        $delivery->update([
            'status' => WebhookDelivery::STATUS_SUCCESS,
            'status_code' => $statusCode,
            'last_error' => null,
            'delivered_at' => now(),
        ]);
    }

    public function markFailed(WebhookDelivery $delivery, ?int $statusCode, string $error): void
    {
        $delivery->update([
            'status' => WebhookDelivery::STATUS_FAILED,
            'status_code' => $statusCode,
            'last_error' => mb_substr($error, 0, 500),
        ]);
    }

    public function resetForRedelivery(WebhookDelivery $delivery): void
    {
        $delivery->update([
            'status' => WebhookDelivery::STATUS_PENDING,
            'status_code' => null,
            'last_error' => null,
            'delivered_at' => null,
        ]);
    }
}
