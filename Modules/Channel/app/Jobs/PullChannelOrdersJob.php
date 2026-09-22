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
        public readonly bool $recovery = false,
        public readonly ?string $recoveryTo = null,
    ) {
        $routingKey = $this->recovery ? 'channel_order_recovery' : 'channel_sync';
        $this->onConnection((string) config("queue.routing.{$routingKey}.connection", 'redis-channel-sync'));
        $this->onQueue((string) config("queue.routing.{$routingKey}.queue", 'channel-sync'));
        $this->timeout = max(60, min(240, (int) config("queue.routing.{$routingKey}.job_timeout", 210)));
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

        $settings = app(ChannelSyncSettingService::class);
        if ($settings->isPaused() && ! $this->recovery) {
            $leases->release($this->channelShopId, $this->leaseToken);

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
            if (! $this->recovery) {
                $from = $settings->effectiveInboundStart($from);
            }
            $to = Carbon::parse($this->to);
            $pullPage = fn () => match ($channel) {
                'shopee' => app(ShopeeOrderService::class)->pullOrdersPage($shop->shop_id, $from->timestamp, $to->timestamp, $cursor),
                'tiktok' => app(TikTokOrderService::class)->pullOrdersPage($shop->shop_id, $from->timestamp, $to->timestamp, $cursor),
                'lazada' => app(LazadaOrderService::class)->pullOrdersPage($shop->shop_id, $from->toIso8601String(), $to->toIso8601String(), $cursor),
                'woocommerce' => app(WooCommerceOrderService::class)->pullOrdersPage($shop->shop_id, $from->timestamp, $cursor),
                default => throw new \RuntimeException("Channel {$channel} belum mendukung pull order per halaman."),
            };
            $result = $this->recovery
                ? ChannelSyncSettingService::withInboundRecoveryBypass($pullPage)
                : $pullPage();

            if ($result->done) {
                $recoveryTo = $this->recoveryTo ? Carbon::parse($this->recoveryTo) : null;

                if ($this->recovery && $recoveryTo && $to->lessThan($recoveryTo)) {
                    $nextFrom = $to->copy();
                    $nextTo = $nextFrom->copy()->addMinutes((int) config(
                        'queue.routing.channel_order_recovery.window_minutes',
                        5,
                    ));
                    if ($nextTo->greaterThan($recoveryTo)) {
                        $nextTo = $recoveryTo->copy();
                    }

                    if (! $shops->advanceScheduledOrderRecoveryWindow(
                        $shop->id,
                        $this->leaseToken,
                        $nextFrom,
                        $nextTo,
                    )) {
                        throw new \RuntimeException('Lease recovery hilang sebelum jendela berikutnya diantrikan.');
                    }

                    if (! $leases->renew(
                        $this->channelShopId,
                        $this->leaseToken,
                        (int) config('queue.routing.channel_order_recovery.lease_seconds', 600),
                    )) {
                        throw new \RuntimeException('Lease recovery hilang sebelum jendela berikutnya diantrikan.');
                    }

                    self::dispatch(
                        $this->channelShopId,
                        $this->leaseToken,
                        $nextFrom->toIso8601String(),
                        $nextTo->toIso8601String(),
                        $channel,
                        true,
                        $recoveryTo->toIso8601String(),
                    )->onQueue($this->queueName());
                    $keepLease = true;

                    Log::info('Recovery channel order window completed; next window queued.', [
                        'channel_shop_id' => $shop->id,
                        'shop_id' => $shop->shop_id,
                        'channel' => $channel,
                        'orders' => $result->count,
                        'window_from' => $from->toIso8601String(),
                        'window_to' => $to->toIso8601String(),
                        'next_window_to' => $nextTo->toIso8601String(),
                    ]);
                } else {
                    $completed = $this->recovery
                        ? $shops->markScheduledOrderRecoveryCompleted($shop->id, $this->leaseToken)
                        : $shops->markScheduledOrderPullCompleted($shop->id, $this->leaseToken, $to);
                    if (! $completed) {
                        throw new \RuntimeException('Lease pull order hilang sebelum cursor dapat disimpan.');
                    }

                    Log::info($this->recovery
                        ? 'Recovery channel order pull completed.'
                        : 'Scheduled channel order pull completed.', [
                            'channel_shop_id' => $shop->id,
                            'shop_id' => $shop->shop_id,
                            'channel' => $channel,
                            'orders' => $result->count,
                        ]);
                }
            } else {
                if (! $shops->saveOrderPullCursor($shop->id, $this->leaseToken, $result->cursor)) {
                    throw new \RuntimeException('Cursor pull order tidak dapat disimpan.');
                }

                $leaseSeconds = $this->recovery
                    ? (int) config('queue.routing.channel_order_recovery.lease_seconds', 600)
                    : (int) config('queue.routing.channel_sync.lease_seconds', 420);
                if (! $leases->renew($this->channelShopId, $this->leaseToken, max(60, $leaseSeconds))) {
                    throw new \RuntimeException('Lease pull order hilang sebelum halaman berikutnya diantrikan.');
                }

                self::dispatch(
                    $this->channelShopId,
                    $this->leaseToken,
                    $this->from,
                    $this->to,
                    $channel,
                    $this->recovery,
                    $this->recoveryTo,
                )->onQueue($this->queueName());
                $keepLease = true;

                Log::info($this->recovery
                    ? 'Recovery channel order page completed; next page queued.'
                    : 'Scheduled channel order page completed; next page queued.', [
                        'channel_shop_id' => $shop->id,
                        'shop_id' => $shop->shop_id,
                        'channel' => $channel,
                        'orders' => $result->count,
                        'cursor' => $result->cursor,
                    ]);
            }
        } catch (\Throwable $e) {
            $shops->markScheduledOrderPullFailed($shop->id, $this->leaseToken, $e->getMessage());

            if ($this->isLazadaRateLimit($channel ?? '', $e->getMessage())) {
                Log::warning('Lazada order pull ditunda karena rate limit channel.', [
                    'channel_shop_id' => $shop->id,
                    'shop_id' => $shop->shop_id,
                    'message' => $e->getMessage(),
                ]);

                return;
            }

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

    private function queueName(): string
    {
        return (string) config(
            'queue.routing.'.($this->recovery ? 'channel_order_recovery' : 'channel_sync').'.queue',
            $this->recovery ? 'channel-order-recovery' : 'channel-sync',
        );
    }

    private function isLazadaRateLimit(string $channel, string $message): bool
    {
        if ($channel !== 'lazada') {
            return false;
        }

        return preg_match('/api access frequency|apicalllimit|too many requests|rate limit/i', $message) === 1;
    }
}
