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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Services\ChannelOrderPullLeaseService;
use Modules\Channel\Support\ChannelErrorClassifier;

final class PullChannelOrdersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

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

        try {
            $exitCode = Artisan::call('channel:pull-orders', [
                '--shop' => $shop->shop_id,
                '--from' => Carbon::parse($this->from)->toIso8601String(),
                '--to' => Carbon::parse($this->to)->toIso8601String(),
            ]);

            if ($exitCode !== 0) {
                throw new \RuntimeException(trim(Artisan::output()) ?: 'Channel order pull gagal.');
            }

            $completed = $shops->markScheduledOrderPullCompleted(
                $shop->id,
                $this->leaseToken,
                Carbon::parse($this->to),
            );

            if (! $completed) {
                throw new \RuntimeException('Lease pull order hilang sebelum cursor dapat disimpan.');
            }

            Log::info('Scheduled channel order pull completed.', [
                'channel_shop_id' => $shop->id,
                'shop_id' => $shop->shop_id,
                'from' => $this->from,
                'to' => $this->to,
            ]);
        } catch (\Throwable $e) {
            $shops->markScheduledOrderPullFailed($shop->id, $this->leaseToken, $e->getMessage());

            $channel = strtolower(trim((string) ($this->channel ?: ($shop->channel?->code ?? ''))));
            if ($channel === 'lazada' && ChannelErrorClassifier::isRetryable($channel, $e)) {
                Log::warning('Scheduled Lazada order pull deferred after transient failure.', [
                    'channel_shop_id' => $shop->id,
                    'shop_id' => $shop->shop_id,
                    'from' => $this->from,
                    'to' => $this->to,
                    'error' => $e->getMessage(),
                ]);

                return;
            }

            throw $e;
        } finally {
            $leases->release($this->channelShopId, $this->leaseToken);
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
