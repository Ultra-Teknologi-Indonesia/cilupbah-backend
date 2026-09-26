<?php

declare(strict_types=1);

namespace Modules\Sales\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Sales\Jobs\RequestChannelAwbJob;
use Modules\Sales\Jobs\RequestTikTokMassAwbJob;
use Modules\Sales\Models\ChannelOperationAttempt;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\BulkShippingLabelService;
use Modules\Sales\Services\ShippingLabelPreparationDispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RequestTikTokMassAwbJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_unique_id_deduplicates_same_shop_and_orders_across_batches(): void
    {
        $first = new RequestTikTokMassAwbJob('BATCH-A', 'SHOP-TIKTOK-MASS', ['A', 'B']);
        $second = new RequestTikTokMassAwbJob('BATCH-B', 'SHOP-TIKTOK-MASS', ['B', 'A']);

        $this->assertSame($first->uniqueId(), $second->uniqueId());
    }

    public static function preflightCases(): array
    {
        return [
            'both ready' => ['ready', 2],
            'stored package fast path' => ['cached', 2],
            'uncertain fast path response' => ['cached_uncertain', 2],
            'missing order' => ['missing', 1],
            'cancelled upstream' => ['cancelled', 1],
            'batch unavailable' => ['unavailable', 0],
            'cancelled locally during preflight' => ['local_cancel', 1],
        ];
    }

    #[DataProvider('preflightCases')]
    public function test_preflights_orders_then_ships_only_verified_packages_in_one_request(string $scenario, int $accepted): void
    {
        $cached = str_starts_with($scenario, 'cached');
        config(['bulk-labels.tiktok_reuse_package_ids' => $cached]);
        Queue::fake();

        $channel = Channel::create(['code' => 'tiktok', 'name' => 'TikTok Shop', 'is_active' => true]);
        ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'SHOP-TIKTOK-MASS',
            'shop_name' => 'TikTok Mass AWB',
            'shop_cipher' => 'cipher',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'is_active' => true,
            'fulfillment_push_enabled' => true,
        ]);

        $first = $this->createOrder('TIKTOK-MASS-A', 'PKG-A');
        $second = $this->createOrder('TIKTOK-MASS-B', 'PKG-B');

        $tiktok = Mockery::mock(TikTokOrderService::class);
        $tiktok->shouldNotReceive('getOrderFulfillmentSnapshot');
        $tiktok->shouldReceive('getOrderFulfillmentSnapshots')
            ->times($cached ? 0 : 1)
            ->withArgs(fn ($shop, array $ids): bool => $shop->shop_id === 'SHOP-TIKTOK-MASS'
                && count($ids) === 2 && in_array('TIKTOK-MASS-A', $ids, true) && in_array('TIKTOK-MASS-B', $ids, true))
            ->andReturnUsing(function () use ($scenario, $second): array {
                if ($scenario === 'unavailable') {
                    throw new \RuntimeException('Channel unavailable');
                }
                if ($scenario === 'local_cancel') {
                    $second->forceFill(['status' => 'cancelled', 'channel_status' => 'CANCELLED'])->save();
                }

                return match ($scenario) {
                    'missing' => ['TIKTOK-MASS-A' => $this->snapshot('PKG-A')],
                    'cancelled' => [
                        'TIKTOK-MASS-B' => ['order_found' => true, 'status' => 'CANCELLED', 'packages' => []],
                        'TIKTOK-MASS-A' => $this->snapshot('PKG-A'),
                    ],
                    default => [
                        'TIKTOK-MASS-B' => $this->snapshot('PKG-B'),
                        'TIKTOK-MASS-A' => $this->snapshot('PKG-A'),
                    ],
                };
            });
        if ($accepted > 0) {
            $expectedPackages = $accepted === 2 ? ['PKG-A', 'PKG-B'] : ['PKG-A'];
            $tiktok->shouldReceive('requestTrackingNumbersMass')
                ->once()
                ->withArgs(function (string $shopId, array $packages) use ($expectedPackages): bool {
                    sort($packages);

                    return $shopId === 'SHOP-TIKTOK-MASS' && $packages === $expectedPackages;
                })
                ->andReturn(array_map(static fn (string $id): array => [
                    'package_id' => $id, 'shipped' => $scenario !== 'cached_uncertain',
                    'uncertain' => $scenario === 'cached_uncertain',
                ], $expectedPackages));
        } else {
            $tiktok->shouldNotReceive('requestTrackingNumbersMass');
        }

        (new RequestTikTokMassAwbJob(
            'BATCH-MASS',
            'SHOP-TIKTOK-MASS',
            [(string) $first->id, (string) $second->id],
        ))->handle(
            $tiktok,
            app(ChannelShopRepository::class),
            app(BulkShippingLabelService::class),
            app(ShippingLabelPreparationDispatcher::class),
        );

        $this->assertSame($accepted, ChannelOperationAttempt::query()
            ->where('operation', 'request_awb')
            ->where('status', $scenario === 'cached_uncertain' ? ChannelOperationAttempt::STATUS_UNCERTAIN : ChannelOperationAttempt::STATUS_ACCEPTED)
            ->count());
        Queue::assertPushed(
            RequestChannelAwbJob::class,
            fn (RequestChannelAwbJob $job): bool => $job->verificationOnly && $job->trackingAttempt === 1,
        );
        Queue::assertPushed(RequestChannelAwbJob::class, $scenario === 'local_cancel' ? 1 : 2);
    }

    private function createOrder(string $channelOrderNo, string $packageId): SalesOrder
    {
        return SalesOrder::factory()->create([
            'channel_instant' => false,
            'source' => 'tiktok',
            'channel_shop_id' => 'SHOP-TIKTOK-MASS',
            'channel_order_no' => $channelOrderNo,
            'channel_package_ids' => [$packageId],
            'channel_status' => 'AWAITING_SHIPMENT',
            'tracking_number' => null,
            'status' => 'reserved',
        ]);
    }

    private function snapshot(string $packageId): array
    {
        return [
            'order_found' => true,
            'status' => 'AWAITING_SHIPMENT',
            'tracking_number' => null,
            'packages' => [[
                'id' => $packageId,
                'status' => 'AWAITING_SHIPMENT',
                'tracking_number' => null,
            ]],
        ];
    }
}
