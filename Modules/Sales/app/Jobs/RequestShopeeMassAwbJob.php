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
use Modules\Channel\Support\ChannelQueue;
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
        $this->onQueue(ChannelQueue::for(
            'shopee',
            $this->verificationOnly || $this->trackingAttempt > 0
                ? 'awb_poll'
                : 'awb_request',
        ));
    }

    public function uniqueId(): string
    {
        $orderIds = $this->orderIds;
        sort($orderIds);

        return implode(':', [
            'shopee-mass-awb',
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
            if (! $this->hasUntrackedPackages($order)) {
                $this->completeOrder($order, (string) $order->tracking_number, null, $bulkLabels, $labelDispatcher);
            }
        }

        $orders = $orders->filter(
            fn (SalesOrder $order): bool => blank($order->tracking_number) || $this->hasUntrackedPackages($order),
        )->values();
        $orders = $orders->reject(function (SalesOrder $order) use ($bulkLabels): bool {
            if ($bulkLabels->isInstantCourier($order) && ! $this->verificationOnly) {
                $bulkLabels->onOrderAwbSkippedInstant((string) $order->id);

                return true;
            }

            return false;
        })->values();
        if ($orders->isEmpty()) {
            return;
        }

        $packagesByOrder = $shopee->resolveMassPackages(
            $this->shopId,
            $orders->pluck('channel_order_no')->map(static fn ($value): string => (string) $value)->all(),
        );

        [$massOrders, $fallbackOrders] = $this->mapMassPackageOrders($orders, $packagesByOrder);

        foreach ($fallbackOrders as $order) {
            RequestChannelAwbJob::dispatch((string) $order->id, 0, true, false, false, 'shopee');
        }

        if ($massOrders->isEmpty()) {
            return;
        }

        if ($this->verificationOnly) {
            $remaining = $this->readTrackingNumbers($shopee, $massOrders, $bulkLabels, $labelDispatcher);
        } else {
            $alreadyShipped = $massOrders
                ->filter(fn (array $entry): bool => $this->channelAlreadyShipped($entry['order']))
                ->values();
            $this->readTrackingNumbers($shopee, $alreadyShipped, $bulkLabels, $labelDispatcher);
            $remaining = $massOrders
                ->reject(fn (array $entry): bool => $this->channelAlreadyShipped($entry['order']))
                ->values();
        }

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

    private function mapMassPackageOrders(Collection $orders, array $packagesByOrder): array
    {
        $massOrders = collect();
        $fallbackOrders = collect();

        foreach ($orders as $order) {
            $orderSn = (string) $order->channel_order_no;
            $packages = array_values((array) ($packagesByOrder[$orderSn] ?? []));

            if ($packages === []) {
                $fallbackOrders->push($order);

                continue;
            }

            $mappedPackages = collect($packages)
                ->map(function (array $package): array {
                    return [
                        'package_number' => trim((string) ($package['package_number'] ?? '')),
                        'logistics_channel_id' => isset($package['logistics_channel_id'])
                            ? (int) $package['logistics_channel_id']
                            : null,
                        'product_location_id' => isset($package['product_location_id'])
                            ? (string) $package['product_location_id']
                            : null,
                    ];
                })
                ->filter(static fn (array $package): bool => $package['package_number'] !== '')
                ->values();

            if ($mappedPackages->count() !== count($packages)) {
                $fallbackOrders->push($order);

                continue;
            }

            $order->forceFill([
                'channel_package_ids' => $mappedPackages
                    ->pluck('package_number')
                    ->unique()
                    ->values()
                    ->all(),
            ])->saveQuietly();

            foreach ($mappedPackages as $package) {
                $massOrders->push(['order' => $order] + $package);
            }
        }

        return [$massOrders, $fallbackOrders];
    }

    private function readTrackingNumbers(
        ShopeeOrderService $shopee,
        Collection $massOrders,
        BulkShippingLabelService $bulkLabels,
        ShippingLabelPreparationDispatcher $labelDispatcher,
    ): Collection {
        if ($massOrders->isEmpty()) {
            return collect();
        }

        $result = $shopee->getMassTrackingNumbers(
            $this->shopId,
            $massOrders->pluck('package_number')->unique()->values()->all(),
        );
        $trackingByPackage = (array) ($result['results'] ?? []);

        return $massOrders
            ->groupBy(static fn (array $entry): string => (string) $entry['order']->id)
            ->flatMap(function (Collection $entries) use ($trackingByPackage, $bulkLabels, $labelDispatcher): Collection {
                $order = $entries->first()['order']->fresh();
                if ($order === null) {
                    return $entries;
                }

                $rawData = is_array($order->shipping_label_raw_data)
                    ? $order->shipping_label_raw_data
                    : [];
                $packageTrackingNumbers = (array) ($rawData['package_tracking_numbers'] ?? []);
                $pickupCode = null;

                foreach ($entries as $entry) {
                    $tracking = (array) ($trackingByPackage[$entry['package_number']] ?? []);
                    $trackingNumber = trim((string) ($tracking['tracking_number'] ?? ''));
                    if ($trackingNumber !== '') {
                        $packageTrackingNumbers[$entry['package_number']] = $trackingNumber;
                    }
                    if ($pickupCode === null && filled($tracking['pickup_code'] ?? null)) {
                        $pickupCode = (string) $tracking['pickup_code'];
                    }
                }

                if ($packageTrackingNumbers !== []) {
                    $rawData['package_tracking_numbers'] = $packageTrackingNumbers;
                    $order->forceFill(['shipping_label_raw_data' => $rawData])->saveQuietly();
                }

                $packageNumbers = array_values(array_filter(array_map(
                    'strval',
                    (array) $order->channel_package_ids,
                )));
                $allPackagesTracked = $packageNumbers !== []
                    && collect($packageNumbers)->every(
                        static fn (string $packageNumber): bool => filled($packageTrackingNumbers[$packageNumber] ?? null),
                    );

                if (! $allPackagesTracked) {
                    return $entries;
                }

                $primaryTracking = (string) ($packageTrackingNumbers[$packageNumbers[0]] ?? '');
                $this->completeOrder(
                    $order,
                    $primaryTracking,
                    $pickupCode,
                    $bulkLabels,
                    $labelDispatcher,
                    $packageTrackingNumbers,
                );

                return collect();
            })
            ->values();
    }

    private function requestMassShipment(
        ShopeeOrderService $shopee,
        Collection $massOrders,
        BulkShippingLabelService $bulkLabels,
    ): Collection {
        $verificationEntries = collect();
        $requestEntries = collect();
        $claimsByOrder = [];
        $resultEntriesByOrder = [];

        foreach ($massOrders as $entry) {

            $order = $entry['order']->fresh();

            if ($order === null || ChannelOrderSideEffectGuard::active((string) $order->id, 'request_mass_awb') === null) {
                continue;
            }

            if (ChannelFulfillmentGuard::blocks($order->channel_shop_id, 'ready_to_ship', $order->salesorder_no)) {
                $bulkLabels->onOrderAwbGaveUp((string) $order->id, BulkShippingLabelItem::REASON_FULFILLMENT_DISABLED);

                continue;
            }

            if ($this->channelAlreadyShipped($order)) {
                $verificationEntries->push($entry);

                continue;
            }

            $orderId = (string) $order->id;
            if (isset($claimsByOrder[$orderId])) {
                $requestEntries->push($entry);

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

            $claimsByOrder[$orderId] = $claim['attempt'];
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
                    $resultEntriesByOrder[(string) $entry['order']->id][] = [
                        'entry' => $entry,
                        'result' => $packageResult,
                    ];
                }
            } catch (Throwable $exception) {
                foreach ($entries as $entry) {
                    $resultEntriesByOrder[(string) $entry['order']->id][] = [
                        'entry' => $entry,
                        'result' => [
                            'package_number' => $entry['package_number'],
                            'shipped' => false,
                            'uncertain' => true,
                            'error' => $exception->getMessage(),
                        ],
                    ];
                }

                Log::warning('RequestShopeeMassAwbJob: mass_ship_order tidak pasti, pindah ke verifikasi baca-saja.', [
                    'batch_id' => $this->batchId,
                    'shop_id' => $this->shopId,
                    'packages' => $packageNumbers,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        foreach ($resultEntriesByOrder as $orderId => $packageResults) {
            $attempt = $claimsByOrder[$orderId] ?? null;
            $entries = collect($packageResults)->pluck('entry');
            $results = collect($packageResults)->pluck('result');
            $allShipped = $results->isNotEmpty() && $results->every(
                static fn (array $result): bool => ! empty($result['shipped']),
            );

            if ($attempt === null) {
                $verificationEntries = $verificationEntries->merge($entries);

                continue;
            }

            if ($allShipped) {
                ChannelOperationLedger::markAccepted($attempt, [
                    'packages' => $entries->pluck('package_number')->values()->all(),
                    'mass_request' => true,
                ]);
                $verificationEntries = $verificationEntries->merge($entries);

                continue;
            }

            $failedResult = $results->first(static fn (array $result): bool => empty($result['shipped']));
            $reason = (string) ($failedResult['error'] ?? 'Shopee tidak mengembalikan hasil mass shipping.');
            if ($results->contains(static fn (array $result): bool => ! empty($result['uncertain']))) {
                ChannelOperationLedger::markUncertain($attempt, new \RuntimeException($reason));
                $verificationEntries = $verificationEntries->merge($entries);

                continue;
            }

            ChannelOperationLedger::markRetryable($attempt, $reason);
            self::dispatch(
                $this->batchId,
                $this->shopId,
                [$orderId],
                $this->trackingAttempt + 1,
                false,
            )->delay(now()->addSeconds(5));
            $verificationEntries = $verificationEntries->merge($entries);
        }

        return $verificationEntries->unique('package_number')->values();
    }

    private function completeOrder(
        SalesOrder $order,
        string $trackingNumber,
        ?string $pickupCode,
        BulkShippingLabelService $bulkLabels,
        ShippingLabelPreparationDispatcher $labelDispatcher,
        array $packageTrackingNumbers = [],
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
        if ($packageTrackingNumbers !== []) {
            $rawData = is_array($fresh->shipping_label_raw_data)
                ? $fresh->shipping_label_raw_data
                : [];
            $rawData['package_tracking_numbers'] = $packageTrackingNumbers;
            $updates['shipping_label_raw_data'] = $rawData;
        }
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
                $remaining->pluck('order.id')->map(static fn ($id): string => (string) $id)->unique()->values()->all(),
                $this->trackingAttempt + 1,
                true,
            )->delay(now()->addSeconds(max(1, (int) $delays[$this->trackingAttempt])));

            return;
        }

        foreach ($remaining->unique(static fn (array $entry): string => (string) $entry['order']->id) as $entry) {
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

    private function hasUntrackedPackages(SalesOrder $order): bool
    {
        $packageNumbers = array_values(array_filter(array_map(
            'strval',
            (array) $order->channel_package_ids,
        )));

        if (count($packageNumbers) <= 1) {
            return false;
        }

        $trackedPackages = (array) data_get(
            is_array($order->shipping_label_raw_data) ? $order->shipping_label_raw_data : [],
            'package_tracking_numbers',
            [],
        );

        return ! collect($packageNumbers)->every(
            static fn (string $packageNumber): bool => filled($trackedPackages[$packageNumber] ?? null),
        );
    }
}
