<?php

declare(strict_types=1);

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Channel\Support\ChannelFulfillmentGuard;
use Modules\Channel\Support\ChannelQueue;
use Modules\Sales\Models\BulkShippingLabelItem;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\BulkShippingLabelService;
use Modules\Sales\Services\ShippingLabelPreparationDispatcher;
use Modules\Sales\Support\ChannelOperationLedger;
use Modules\Sales\Support\ChannelOrderSideEffectGuard;
use Throwable;

final class RequestTikTokMassAwbJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const RETRYABLE_ERROR_CODES = [36009003, 21001003, 21001011, 21001028];

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public int $timeout = 150;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly string $batchId,
        public readonly string $shopId,
        public readonly array $orderIds,
    ) {
        $this->onConnection(config('queue.routing.label_awb_request.connection', 'redis-long'));
        $this->onQueue(ChannelQueue::for('tiktok', 'awb_request'));
    }

    public function uniqueId(): string
    {
        $orderIds = $this->orderIds;
        sort($orderIds);

        return implode(':', [
            'tiktok-mass-awb',
            $this->shopId,
            hash('sha256', implode('|', $orderIds)),
        ]);
    }

    public function handle(
        TikTokOrderService $tiktok,
        ChannelShopRepository $shops,
        BulkShippingLabelService $bulkLabels,
        ShippingLabelPreparationDispatcher $labelDispatcher,
    ): void {
        $shop = $shops->findByShopId($this->shopId);
        if ($shop === null || blank($shop->access_token)) {
            Log::warning('RequestTikTokMassAwbJob: toko TikTok/token tidak ditemukan.', [
                'batch_id' => $this->batchId,
                'shop_id' => $this->shopId,
            ]);

            return;
        }

        $orders = SalesOrder::query()
            ->whereIn('id', $this->orderIds)
            ->where('source', 'tiktok')
            ->where('channel_shop_id', $this->shopId)
            ->get()
            ->values();

        $candidates = [];
        foreach ($orders as $order) {
            $fresh = ChannelOrderSideEffectGuard::active((string) $order->id, 'request_mass_awb');
            if ($fresh === null) {
                continue;
            }

            if (filled($fresh->tracking_number)) {
                $this->completeOrder($fresh, (string) $fresh->tracking_number, $bulkLabels, $labelDispatcher);

                continue;
            }

            if ($bulkLabels->isInstantCourier($fresh) && ! $this->verificationOnly) {
                $bulkLabels->onOrderAwbSkippedInstant((string) $fresh->id);

                continue;
            }

            if (ChannelFulfillmentGuard::blocks($fresh->channel_shop_id, 'ready_to_ship', $fresh->salesorder_no)) {
                $bulkLabels->onOrderAwbGaveUp((string) $fresh->id, BulkShippingLabelItem::REASON_FULFILLMENT_DISABLED);

                continue;
            }

            $candidates[] = $fresh;
        }

        if ($candidates === []) {
            return;
        }

        $snapshots = [];
        $missing = [];
        foreach ($candidates as $order) {
            $packages = array_values(array_unique(array_filter(array_map('strval', (array) $order->channel_package_ids))));
            $status = strtoupper((string) $order->channel_status);
            if (config('bulk-labels.tiktok_reuse_package_ids', true)
                && $packages !== []
                && in_array($status, ['AWAITING_SHIPMENT', 'READY_TO_SHIP'], true)) {
                $snapshots[(string) $order->channel_order_no] = [
                    'order_found' => true,
                    'status' => $status,
                    'tracking_number' => null,
                    'packages' => array_map(static fn (string $id): array => ['id' => $id, 'status' => $status], $packages),
                ];
            } else {
                $missing[] = (string) $order->channel_order_no;
            }
        }

        try {
            $snapshots += $missing === [] ? [] : $tiktok->getOrderFulfillmentSnapshots(
                $shop,
                $missing,
            );
        } catch (Throwable $exception) {
            Log::warning('RequestTikTokMassAwbJob: preflight batch gagal; pindah ke verifikasi baca-saja.', [
                'batch_id' => $this->batchId,
                'exception' => $exception->getMessage(),
            ]);
        }

        $entries = [];
        foreach ($candidates as $order) {

            $fresh = ChannelOrderSideEffectGuard::active((string) $order->id, 'request_mass_awb');
            if ($fresh === null) {
                continue;
            }

            if (filled($fresh->tracking_number)) {
                $this->completeOrder($fresh, (string) $fresh->tracking_number, $bulkLabels, $labelDispatcher);

                continue;
            }

            if (ChannelFulfillmentGuard::blocks($fresh->channel_shop_id, 'ready_to_ship', $fresh->salesorder_no)) {
                $bulkLabels->onOrderAwbGaveUp((string) $fresh->id, BulkShippingLabelItem::REASON_FULFILLMENT_DISABLED);

                continue;
            }

            $snapshot = $snapshots[(string) $fresh->channel_order_no] ?? null;
            if (! ($snapshot['order_found'] ?? false)) {
                $this->dispatchVerification($fresh);

                continue;
            }

            $trackingNumber = trim((string) ($snapshot['tracking_number'] ?? ''));
            if ($trackingNumber !== '') {
                $this->completeOrder($fresh, $trackingNumber, $bulkLabels, $labelDispatcher);

                continue;
            }

            $orderIsReadyToShip = in_array(
                strtoupper((string) ($snapshot['status'] ?? '')),
                ['AWAITING_SHIPMENT', 'READY_TO_SHIP'],
                true,
            );
            $readyPackageIds = collect($snapshot['packages'] ?? [])
                ->filter(static function (array $package) use ($orderIsReadyToShip): bool {
                    $packageStatus = strtoupper((string) ($package['status'] ?? ''));

                    return in_array($packageStatus, ['AWAITING_SHIPMENT', 'READY_TO_SHIP'], true)
                        || ($packageStatus === '' && $orderIsReadyToShip);
                })
                ->pluck('id')
                ->filter(static fn ($id): bool => filled($id))
                ->map(static fn ($id): string => (string) $id)
                ->unique()
                ->values()
                ->all();

            if ($readyPackageIds === []) {
                $this->dispatchVerification($fresh);

                continue;
            }

            $claim = ChannelOperationLedger::claim($fresh, 'request_awb');
            if (! $claim['should_execute']) {
                $this->dispatchVerification($fresh);

                continue;
            }

            $entries[] = [
                'order' => $fresh,
                'attempt' => $claim['attempt'],
                'package_ids' => $readyPackageIds,
            ];
        }

        if ($entries === []) {
            return;
        }

        $resultsByPackage = collect($tiktok->requestTrackingNumbersMass(
            $this->shopId,
            collect($entries)->pluck('package_ids')->flatten()->unique()->values()->all(),
        ))->keyBy(static fn (array $result): string => (string) $result['package_id']);

        foreach ($entries as $entry) {
            $packageResults = collect($entry['package_ids'])
                ->map(fn (string $packageId): array => $resultsByPackage->get($packageId, [
                    'package_id' => $packageId,
                    'shipped' => false,
                    'message' => 'TikTok tidak mengembalikan hasil untuk package pada batch shipment.',
                    'error_category' => 'retryable',
                    'uncertain' => true,
                ]))
                ->values();

            $failed = $packageResults->where('shipped', false)->values();
            if ($failed->isEmpty()) {
                ChannelOperationLedger::markAccepted($entry['attempt'], [
                    'mass_request' => true,
                    'packages' => $packageResults->all(),
                ]);
                $this->dispatchVerification($entry['order']);

                continue;
            }

            if ($packageResults->contains('shipped', true) || $failed->contains(fn (array $result): bool => ($result['uncertain'] ?? false)
                || in_array((int) ($result['error_code'] ?? 0), [21011020, 21011040, 21001003, 21001028], true))) {
                $response = [
                    'verification_required' => true,
                    'packages' => $packageResults->all(),
                ];
                if ($packageResults->contains('shipped', true)) {
                    ChannelOperationLedger::markAccepted($entry['attempt'], $response);
                } else {
                    ChannelOperationLedger::markUncertain($entry['attempt'], new \RuntimeException('Hasil shipment belum pasti; verifikasi channel sebelum mengirim ulang.'));
                    $entry['attempt']->forceFill(['last_response' => $response])->save();
                }
                $this->dispatchVerification($entry['order']);

                continue;
            }

            $reason = (string) ($failed->first()['message'] ?? 'TikTok menolak package pada batch shipment.');
            $retryable = $failed->contains(function (array $result): bool {
                return ($result['error_category'] ?? null) === 'retryable'
                    || in_array((int) ($result['error_code'] ?? 0), self::RETRYABLE_ERROR_CODES, true);
            });

            if ($retryable) {
                ChannelOperationLedger::markRetryable($entry['attempt'], $reason, [
                    'mass_request' => true,
                    'packages' => $packageResults->all(),
                ]);
                RequestChannelAwbJob::dispatch((string) $entry['order']->id, 0, true, false, false, 'tiktok')
                    ->delay(now()->addSeconds(5));

                continue;
            }

            ChannelOperationLedger::markRejected($entry['attempt'], $reason);
            $bulkLabels->onOrderAwbGaveUp((string) $entry['order']->id, $reason);
        }
    }

    private function completeOrder(
        SalesOrder $order,
        string $trackingNumber,
        BulkShippingLabelService $bulkLabels,
        ShippingLabelPreparationDispatcher $labelDispatcher,
    ): void {
        $fresh = ChannelOrderSideEffectGuard::active((string) $order->id, 'persist_mass_awb');
        if ($fresh === null) {
            return;
        }

        $fresh->forceFill(['tracking_number' => $trackingNumber])->save();
        ChannelOperationLedger::markSucceededWhenVerified($fresh, 'request_awb', [
            'tracking_number' => $trackingNumber,
            'mass_verification' => true,
        ]);
        $bulkLabels->onOrderAwbReady((string) $fresh->id);
        $labelDispatcher->dispatch($fresh->fresh());
    }

    private function dispatchVerification(SalesOrder $order): void
    {
        RequestChannelAwbJob::dispatch((string) $order->id, 1, false, false, true, 'tiktok');
    }
}
