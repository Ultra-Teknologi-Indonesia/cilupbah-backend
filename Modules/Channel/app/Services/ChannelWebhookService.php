<?php

declare(strict_types=1);

namespace Modules\Channel\Services;

use Modules\Channel\Models\ChannelWebhookInbox;
use Modules\Channel\Repositories\ChannelWebhookInboxRepository;
use Modules\Channel\Jobs\ProcessLazadaWebhook;
use Modules\Channel\Jobs\ProcessShopeeWebhook;
use Modules\Channel\Jobs\ProcessTikTokWebhook;
use Modules\Channel\Jobs\ProcessWooCommerceWebhook;
use Modules\Sales\Jobs\AdminAlertJob;

final class ChannelWebhookService
{
    public function __construct(
        private readonly ChannelWebhookInboxRepository $repository,
    ) {}

    public function recordFirstDelivery(
        string $channel,
        ?string $shopId,
        string $eventKey,
        ?string $eventType,
        array $payload,
    ): ?ChannelWebhookInbox {
        return $this->repository->recordFirstDelivery($channel, $shopId, $eventKey, $eventType, $payload);
    }

    public function quarantine(
        string $topic,
        string $source,
        string $rawBody,
        string $reason,
        ?array $payload = null,
    ): void {
        $eventKey = 'woocommerce:anomaly:' . md5($source . '|' . $topic . '|' . $rawBody);
        $row = $this->recordFirstDelivery(
            'woocommerce',
            null,
            $eventKey,
            $topic,
            $payload ?? ['_source' => $source, '_raw' => mb_substr($rawBody, 0, 5000)],
        );

        if ($row === null) {
            return;
        }

        $row->markFailed($reason);

        AdminAlertJob::dispatch(
            'WooCommerce webhook tidak dapat diproses',
            $reason,
            ['source' => $source, 'topic' => $topic, 'inbox_id' => $row->id],
        );
    }

    public function dispatchLazada(array $payload): void
    {
        ProcessLazadaWebhook::dispatch($payload);
    }

    public function dispatchShopee(array $payload): void
    {
        ProcessShopeeWebhook::dispatch($payload);
    }

    public function dispatchTikTok(array $payload): void
    {
        ProcessTikTokWebhook::dispatch($payload)
            ->onQueue(ProcessTikTokWebhook::resolveQueueName($payload));
    }

    public function dispatchWooCommerce(string $shopId, string $topic, array $payload): void
    {
        ProcessWooCommerceWebhook::dispatch(
            $shopId,
            $topic,
            (string) $payload['id'],
            $payload,
        );
    }
}
