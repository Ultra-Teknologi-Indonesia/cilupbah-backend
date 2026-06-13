<?php

namespace Modules\Webhook\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Webhook\Jobs\SendWebhookJob;
use Modules\Webhook\Models\WebhookDelivery;
use Modules\Webhook\Repositories\WebhookDeliveryRepository;

class WebhookDeliveryService
{
    public function __construct(
        private readonly WebhookDeliveryRepository $repository,
    ) {
    }

    public function listForSubscription(string $subscriptionId): LengthAwarePaginator
    {
        return $this->repository->paginateForSubscription($subscriptionId);
    }

    public function find(string $id): ?WebhookDelivery
    {
        return $this->repository->find($id);
    }

    public function redeliver(WebhookDelivery $delivery): void
    {
        $this->repository->resetForRedelivery($delivery);

        SendWebhookJob::dispatch($delivery->id)
            ->onQueue(config('webhook.queue', 'webhooks'));
    }
}
