<?php

declare(strict_types=1);

namespace Modules\Channel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Services\ChannelOrderPullLeaseService;

final class PullChannelOrdersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(
        public readonly string $channelShopId,
        public readonly string $leaseToken,
        public readonly string $from,
        public readonly string $to,
    ) {
        $this->onConnection(config('queue.routing.channel_sync.connection', 'redis-channel-sync'));
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
