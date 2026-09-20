<?php

declare(strict_types=1);

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Support\ChannelFulfillmentGuard;
use Modules\Sales\Models\BulkShippingLabelItem;
use Modules\Sales\Models\ChannelOperationAttempt;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\BulkShippingLabelService;
use Modules\Sales\Services\ShippingLabelPreparationDispatcher;
use Modules\Sales\Support\ChannelOperationLedger;
use Modules\Sales\Support\ChannelOrderSideEffectGuard;
use Throwable;

final class RequestShopeeMassAwbJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public int $timeout = 150;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly string $batchId,
        public readonly string $shopId,
        public readonly array $orderIds,
        public readonly int $trackingAttempt = 0,
        public readonly bool $verificationOnly = false,
    ) {
        $this->onConnection(config('queue.routing.label_awb.connection', 'redis-long'));
        $this->onQueue(config('queue.routing.label_awb.queue', 'label-awb'));
    }

    public function uniqueId(): string
    {
        $orderIds = $this->orderIds;
        sort($orderIds);

        return implode(':', [
            'shopee-mass-awb',
            $this->batchId,
            $this->shopId,
            hash('sha256', implode('|', $orderIds)),
            (string) $this->trackingAttempt,
            $this->verificationOnly ? 'verify' : 'request',
        ]);
    }

    public function handle(
        ShopeeOrderService $shopee,
        BulkShippingLabelService $bulkLabels,
        ShippingLabelPreparationDispatcher $labelDispatcher,
    ): void {
        $orders = SalesOrder::query()
            ->whereIn('id', $this->orderIds)
            ->where('source', 'shopee')
            ->where('channel_shop_id', $this->shopId)
            ->get()
            ->filter(fn (SalesOrder $order): bool => ChannelOrderSideEffectGuard::active(
                (string) $order->id,
                'request_mass_awb',
            ) !== null)
            ->values();

        if ($orders->isEmpty()) {
            return;
        }

        foreach ($orders->whereNotNull('tracking_number')->filter(
            fn (SalesOrder $order): bool => filled($order->tracking_number),
        ) as $order) {
            $this->completeOrder($order, (string) $order->tracking_number, null, $bulkLabels, $labelDispatcher);
        }

        $orders = $orders->filter(fn (SalesOrder $order): bool => blank($order->tracking_number))->values();
        if ($orders->isEmpty()) {
            return;
        }

        $packagesByOrder = $shopee->resolveMassPackages(
            $this->shopId,
            $orders->pluck('channel_order_no')->map(static fn ($value): string => (string) $value)->all(),
        );

        [$massOrders, $fallbackOrders] = $this->mapSinglePackageOrders($orders, $packagesByOrder);

        foreach ($fallbackOrders as $order) {
            RequestChannelAwbJob::dispatch((string) $order->id);
        }

        if ($massOrders->isEmpty()) {
            return;
        }

        $remaining = $this->readTrackingNumbers(
            $shopee,
            $massOrders,
            $bulkLabels,
            $labelDispatcher,
        );

        if ($remaining->isEmpty()) {
            return;
        }

        if (! $this->verificationOnly) {
            $remaining = $this->requestMassShipment($shopee, $remaining, $bulkLabels);

            if ($remaining->isEmpty()) {
                return;
            }

            $remaining = $this->readTrackingNumbers(
                $shopee,
                $remaining,
                $bulkLabels,
                $labelDispatcher,
            );
        }

        if ($remaining->isNotEmpty()) {
            $this->scheduleVerificationOrFail($remaining, $bulkLabels);
        }
    }

    private function mapSinglePackageOrders(Collection $orders, array $packagesByOrder): array
    {
        $massOrders = collect();
        $fallbackOrders = collect();

        foreach ($orders as $order) {
            $orderSn = (string) $order->channel_order_no;
            $packages = array_values((array) ($packagesByOrder[$orderSn] ?? []));

            if (count($packages) !== 1) {
                $fallbackOrders->push($order);

                continue;
            }

            $packageNumber = (string) ($packages[0]['package_number'] ?? '');
            if ($packageNumber === '') {
                $fallbackOrders->push($order);

                continue;
            }

            $order->forceFill(['channel_package_ids' => [$packageNumber]])->saveQuietly();
            $massOrders->push([
                'order' => $order,
                'package_number' => $packageNumber,
                'logistics_channel_id' => isset($packages[0]['logistics_channel_id'])
                    ? (string) $packages[0]['logistics_channel_id']
                    : null,
                'product_location_id' => isset($packages[0]['product_location_id'])
                    ? (string) $packages[0]['product_location_id']
                    : null,
            ]);
        }

        return [$massOrders, $fallbackOrders];
    }

    private function readTrackingNumbers(
        ShopeeOrderService $shopee,
        Collection $massOrders,
        BulkShippingLabelService $bulkLabels,
        ShippingLabelPreparationDispatcher $labelDispatcher,
    ): Collection {
        $result = $shopee->getMassTrackingNumbers(
            $this->shopId,
            $massOrders->pluck('package_number')->all(),
        );
        $trackingByPackage = (array) ($result['results'] ?? []);

        return $massOrders->filter(function (array $entry) use ($trackingByPackage, $bulkLabels, $labelDispatcher): bool {
            $tracking = (array) ($trackingByPackage[$entry['package_number']] ?? []);
            $trackingNumber = trim((string) ($tracking['tracking_number'] ?? ''));

            if ($trackingNumber === '') {
                return true;
            }

            $this->completeOrder(
                $entry['order'],
                $trackingNumber,
                isset($tracking['pickup_code']) ? (string) $tracking['pickup_code'] : null,
                $bulkLabels,
                $labelDispatcher,
            );

            return false;
        })->values();
    }

    private function requestMassShipment(
        ShopeeOrderService $shopee,
        Collection $massOrders,
        BulkShippingLabelService $bulkLabels,
    ): Collection {
        $verificationEntries = collect();
        $requestEntries = collect();
        $claimsByPackage = [];

        foreach ($massOrders as $entry) {

            $order = $entry['order']->fresh();

            if ($order === null || ChannelOrderSideEffectGuard::active((string) $order->id, 'request_mass_awb') === null) {
                continue;
            }

            if (ChannelFulfillmentGuard::blocks($order->channel_shop_id, 'ready_to_ship', $order->salesorder_no)) {
                $bulkLabels->onOrderAwbGaveUp((string) $order->id, BulkShippingLabelItem::REASON_CHANNEL_SYNC_PAUSED);

                continue;
            }

            if ($this->channelAlreadyShipped($order)) {
                $verificationEntries->push($entry);

                continue;
            }

            $claim = ChannelOperationLedger::claim($order, 'request_awb');
            if (! $claim['should_execute']) {
                if (in_array($claim['attempt']->status, [
                    ChannelOperationAttempt::STATUS_ACCEPTED,
                    ChannelOperationAttempt::STATUS_UNCERTAIN,
                    ChannelOperationAttempt::STATUS_SENDING,
                ], true)) {
                    $verificationEntries->push($entry);
                }

                continue;
            }

            $claimsByPackage[$entry['package_number']] = $claim['attempt'];
            $requestEntries->push($entry);
        }

        foreach ($requestEntries->groupBy(
            static fn (array $entry): string => implode('|', [
                $entry['logistics_channel_id'] ?: 'unknown-channel',
                $entry['product_location_id'] ?: 'unknown-location',
            ]),
        ) as $entries) {
            $packageNumbers = $entries->pluck('package_number')->all();
            $firstEntry = $entries->first();
            $massOptions = array_filter([
                'logistics_channel_id' => $firstEntry['logistics_channel_id'] ?? null,
                'product_location_id' => $firstEntry['product_location_id'] ?? null,
            ], static fn ($value): bool => $value !== null && $value !== '');

            try {
                $result = $shopee->massShipPackages($this->shopId, $packageNumbers, $massOptions);
                $resultsByPackage = (array) ($result['results'] ?? []);

                foreach ($entries as $entry) {
                    $packageNumber = $entry['package_number'];
                    $packageResult = (array) ($resultsByPackage[$packageNumber] ?? []);
                    $attempt = $claimsByPackage[$packageNumber];

                    if (! empty($packageResult['shipped'])) {
                        ChannelOperationLedger::markAccepted($attempt, [
                            'package_number' => $packageNumber,
                            'mass_request' => true,
                        ]);
                        $verificationEntries->push($entry);

                        continue;
                    }

                    $reason = (string) ($packageResult['error'] ?? 'Shopee tidak mengembalikan hasil mass shipping.');
                    if ($packageResult === []) {
                        ChannelOperationLedger::markUncertain($attempt, new \RuntimeException($reason));
                        $verificationEntries->push($entry);

                        continue;
                    }

                    ChannelOperationLedger::markRetryable($attempt, $reason);
                    RequestChannelAwbJob::dispatch((string) $entry['order']->id)
                        ->delay(now()->addSeconds(5));
                }
            } catch (Throwable $exception) {
                foreach ($entries as $entry) {
                    $attempt = $claimsByPackage[$entry['package_number']];
                    ChannelOperationLedger::markUncertain($attempt, $exception);
                    $verificationEntries->push($entry);
                }

                Log::warning('RequestShopeeMassAwbJob: mass_ship_order tidak pasti, pindah ke verifikasi baca-saja.', [
                    'batch_id' => $this->batchId,
                    'shop_id' => $this->shopId,
                    'packages' => $packageNumbers,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return $verificationEntries->unique('package_number')->values();
    }

    private function completeOrder(
        SalesOrder $order,
        string $trackingNumber,
        ?string $pickupCode,
        BulkShippingLabelService $bulkLabels,
        ShippingLabelPreparationDispatcher $labelDispatcher,
    ): void {
        $fresh = ChannelOrderSideEffectGuard::active((string) $order->id, 'persist_mass_awb');
        if ($fresh === null) {
            return;
        }

        $existingTracking = trim((string) $fresh->tracking_number);
        if ($existingTracking !== '' && $existingTracking !== $trackingNumber) {
            Log::error('RequestShopeeMassAwbJob: resi mass berbeda dengan resi lokal; nilai lokal dipertahankan.', [
                'order_id' => $fresh->id,
                'salesorder_no' => $fresh->salesorder_no,
                'local_tracking_number' => $existingTracking,
                'mass_tracking_number' => $trackingNumber,
            ]);
            $trackingNumber = $existingTracking;
        }

        $updates = ['tracking_number' => $trackingNumber];
        if ($pickupCode !== null && $pickupCode !== '') {
            $updates['pickup_code'] = $pickupCode;
        }

        $fresh->forceFill($updates)->save();

        ChannelOperationLedger::markSucceededWhenVerified($fresh, 'request_awb', [
            'tracking_number' => $trackingNumber,
            'mass_verification' => true,
        ]);

        $bulkLabels->onOrderAwbReady((string) $fresh->id);
        $labelDispatcher->dispatch($fresh->fresh());
    }

    private function scheduleVerificationOrFail(Collection $remaining, BulkShippingLabelService $bulkLabels): void
    {
        $delays = array_values((array) config(
            'bulk-labels.shopee_mass_awb_verification_delays',
            [2, 5, 10, 20, 30, 60],
        ));

        if (isset($delays[$this->trackingAttempt])) {
            self::dispatch(
                $this->batchId,
                $this->shopId,
                $remaining->pluck('order.id')->map(static fn ($id): string => (string) $id)->all(),
                $this->trackingAttempt + 1,
                true,
            )->delay(now()->addSeconds(max(1, (int) $delays[$this->trackingAttempt])));

            return;
        }

        foreach ($remaining as $entry) {
            $bulkLabels->onOrderAwbGaveUp(
                (string) $entry['order']->id,
                BulkShippingLabelItem::REASON_AWB_TIMEOUT,
            );
        }
    }

    private function channelAlreadyShipped(SalesOrder $order): bool
    {
        return in_array(strtoupper((string) $order->channel_status), [
            'PROCESSED',
            'AWAITING_COLLECTION',
            'SHIPPED',
            'IN_TRANSIT',
            'TO_CONFIRM_RECEIVE',
            'COMPLETED',
        ], true);
    }
}
