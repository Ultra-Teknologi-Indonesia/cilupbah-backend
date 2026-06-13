<?php

namespace Modules\Webhook\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Modules\Webhook\Models\WebhookSubscription;
use Modules\Webhook\Repositories\WebhookSubscriptionRepository;

class WebhookSubscriptionService
{
    public function __construct(
        private readonly WebhookSubscriptionRepository $repository,
    ) {
    }

    public function list(): LengthAwarePaginator
    {
        return $this->repository->paginate();
    }

    public function find(string $id): ?WebhookSubscription
    {
        return $this->repository->findById($id);
    }

    public function create(array $data): WebhookSubscription
    {
        $data['secret'] = $data['secret'] ?? Str::random(40);
        $data['is_active'] = $data['is_active'] ?? true;
        $subscription = $this->repository->create($data);
        app(WebhookDispatcherService::class)->refreshCache();

        return $subscription;
    }

    public function update(WebhookSubscription $subscription, array $data): WebhookSubscription
    {
        $subscription = $this->repository->update($subscription, $data);
        app(WebhookDispatcherService::class)->refreshCache();

        return $subscription;
    }

    public function delete(WebhookSubscription $subscription): void
    {
        $this->repository->delete($subscription);
        app(WebhookDispatcherService::class)->refreshCache();
    }
}
