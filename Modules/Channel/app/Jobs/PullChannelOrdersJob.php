<?php

declare(strict_types=1);

namespace Modules\Channel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Services\ChannelOrderPullLeaseService;
use Modules\Channel\Services\ChannelSyncSettingService;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Channel\Services\WooCommerceOrderService;

final class PullChannelOrdersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public int $timeout;

    public function __construct(
        public readonly string $channelShopId,
        public readonly string $leaseToken,
        public readonly string $from,
        public readonly string $to,
        public readonly ?string $channel = null,
    ) {
        $this->onConnection(config('queue.routing.channel_sync.connection', 'redis-channel-sync'));
        $this->timeout = max(60, min(240, (int) config('queue.routing.channel_sync.job_timeout', 210)));
    }

    public function middleware(): array
    {
        return [
            (new RateLimited('channel_api'))->releaseAfter(10),
        ];
    }

    public function handle(
        ChannelOrderPullLeaseService $leases,
        ChannelShopRepository $shops,
    ): void {
        $shop = ChannelShop::query()->find($this->channelShopId);

        if (! $shop || ! $leases->owns($shop, $this->leaseToken)) {
            return;
        }

        $keepLease = false;

        try {
            $channel = strtolower(trim((string) ($this->channel ?: ($shop->channel?->code ?? ''))));
            $cursor = [];
            if (is_string($shop->order_pull_cursor) && $shop->order_pull_cursor !== '') {
                $decoded = json_decode($shop->order_pull_cursor, true);
                $cursor = is_array($decoded) ? $decoded : [];
            }

            $from = Carbon::parse($this->from);
            $to = Carbon::parse($this->to);
            $result = ChannelSyncSettingService::withInboundBypass(fn () => match ($channel) {
                'shopee' => app(ShopeeOrderService::class)->pullOrdersPage($shop->shop_id, $from->timestamp, $to->timestamp, $cursor),
                'tiktok' => app(TikTokOrderService::class)->pullOrdersPage($shop->shop_id, $from->timestamp, $to->timestamp, $cursor),
                'lazada' => app(LazadaOrderService::class)->pullOrdersPage($shop->shop_id, $from->toIso8601String(), $to->toIso8601String(), $cursor),
                'woocommerce' => app(WooCommerceOrderService::class)->pullOrdersPage($shop->shop_id, $from->timestamp, $cursor),
                default => throw new \RuntimeException("Channel {$channel} belum mendukung pull order per halaman."),
            });

            if ($result->done) {
                $completed = $shops->markScheduledOrderPullCompleted($shop->id, $this->leaseToken, $to);
                if (! $completed) {
                    throw new \RuntimeException('Lease pull order hilang sebelum cursor dapat disimpan.');
                }

                Log::info('Scheduled channel order pull completed.', [
                    'channel_shop_id' => $shop->id,
                    'shop_id' => $shop->shop_id,
                    'channel' => $channel,
                    'orders' => $result->count,
                ]);
            } else {
                if (! $shops->saveOrderPullCursor($shop->id, $this->leaseToken, $result->cursor)) {
                    throw new \RuntimeException('Cursor pull order tidak dapat disimpan.');
                }

                if (! $leases->renew($this->channelShopId, $this->leaseToken, max(60, (int) config('queue.routing.channel_sync.lease_seconds', 420)))) {
                    throw new \RuntimeException('Lease pull order hilang sebelum halaman berikutnya diantrikan.');
                }

                self::dispatch($this->channelShopId, $this->leaseToken, $this->from, $this->to, $channel)
                    ->onQueue((string) config('queue.names.channel_sync', 'channel-sync'));
                $keepLease = true;

                Log::info('Scheduled channel order page completed; next page queued.', [
                    'channel_shop_id' => $shop->id,
                    'shop_id' => $shop->shop_id,
                    'channel' => $channel,
                    'orders' => $result->count,
                    'cursor' => $result->cursor,
                ]);
            }
        } catch (\Throwable $e) {

            $shops->markScheduledOrderPullFailed($shop->id, $this->leaseToken, $e->getMessage());
            throw $e;
        } finally {
            if (! $keepLease) {
                $leases->release($this->channelShopId, $this->leaseToken);
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        $shop = ChannelShop::query()->find($this->channelShopId);
        if ($shop) {
            app(ChannelShopRepository::class)->markScheduledOrderPullFailed(
                $shop->id,
                $this->leaseToken,
                $e->getMessage(),
            );
        }

        app(ChannelOrderPullLeaseService::class)->release($this->channelShopId, $this->leaseToken);
    }
}
