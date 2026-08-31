<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Exceptions\ChannelCancelException;
use Modules\Channel\Exceptions\TokenExpiredException;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Outbound\Support\ChannelInstantSignal;
use Modules\Sales\Services\SalesOrderService;

class ShopeeOrderService
{
    public const MAX_TIME_RANGE_DAYS = 14;

    private const DETAIL_FIELDS = 'recipient_address,item_list,total_amount,buyer_user_id,buyer_username,payment_method,estimated_shipping_fee,actual_shipping_fee,actual_shipping_fee_confirmed,shipping_carrier,note,pay_time,cancel_reason,buyer_cancel_reason,cancel_by,package_list,fulfillment_flag,pickup_done_time,invoice_data,order_chargeable_weight_gram,dropshipper,dropshipper_phone,split_up,return_request_due_date,ship_by_date,logistics_channel_id';

    public function __construct(
        protected ShopeeClient $client,
        protected ShopeeToInternalOrderMapper $mapper,
        protected SalesOrderService $orderService,
        protected ChannelShopRepository $shopRepository,
        protected ShopeeAuthService $authService,
    ) {}

    public function pullOrders(string $shopId, ?int $updatedAfter = null, ?int $updatedBefore = null): int
    {
        if (app(ChannelSyncSettingService::class)->isPaused()) {
            return 0;
        }

        $shop = $this->requireShop($shopId);

        $timeFrom = $updatedAfter ?: now()->subDays(7)->timestamp;
        $orderSns = $this->fetchOrderSns($shop, $timeFrom, $updatedBefore);

        if (empty($orderSns)) {
            return 0;
        }

        $count = 0;
        $shippingChannelTypes = $this->shippingChannelTypes($shopId);
        foreach (array_chunk($orderSns, 50) as $chunk) {
            foreach ($this->fetchOrderDetails($shop, $chunk) as $order) {
                $orderSn = (string) ($order['order_sn'] ?? '');

                try {
                    $internal = $this->mapper->map($order, $shopId, $shippingChannelTypes);
                    $this->orderService->upsertFromChannel($internal);
                    $count++;
                } catch (\Throwable $e) {
                    Log::error("Shopee: gagal upsert order {$orderSn}: ".$e->getMessage());
                }
            }
        }

        if ($count > 0 || ! empty($orderSns)) {
            $this->shopRepository->markIntegrationHealthy($shop->id);
            $this->shopRepository->markOrderSyncOk($shop->id);
        }

        return $count;
    }

    public function pullOrderById(string $shopId, string $orderSn): int
    {
        $shop = $this->requireShop($shopId);

        $details = $this->fetchOrderDetails($shop, [$orderSn]);
        $order = $details[0] ?? [];

        if (empty($order)) {
            return 0;
        }

        $internal = $this->mapper->map($order, $shopId, $this->shippingChannelTypes($shopId));
        $orderId = $this->orderService->upsertFromChannel($internal);

        if ($orderId) {
            try {
                $escrowRaw = $this->getEscrowDetail($shopId, $orderSn);
                if (! empty($escrowRaw)) {
                    $finance = app(ShopeeEscrowMapper::class)->map($escrowRaw);
                    $this->orderService->updateOrderFinance($orderId, $finance);
                }
            } catch (\Throwable $e) {

            }
        }

        $this->shopRepository->markIntegrationHealthy($shop->id);
        $this->shopRepository->markOrderSyncOk($shop->id);

        return 1;
    }

    public function listRecentOrderIds(string $shopId, ?int $timeFrom = null): array
    {
        $shop = $this->requireShop($shopId);

        return $this->fetchOrderSns($shop, $timeFrom ?: now()->subDays(2)->timestamp);
    }

    public function listChannelReturns(string $shopId, int $maxPages = 10, int $pageSize = 50): array
    {
        $shop = $this->requireShop($shopId);
        $returns = [];
        $pageNo = 0;

        for ($page = 0; $page < $maxPages; $page++) {
            $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request(
                'GET',
                '/api/v2/returns/get_return_list',
                ['page_no' => $pageNo, 'page_size' => $pageSize],
                $token,
                $shop->shop_id,
            ));

            $rows = $res['response']['return'] ?? [];
            foreach ($rows as $row) {
                $returns[] = [
                    'order_sn' => (string) ($row['order_sn'] ?? ''),
                    'return_sn' => (string) ($row['return_sn'] ?? ''),
                    'reason' => (string) ($row['reason'] ?? ''),
                ];
            }

            if (count($rows) < $pageSize || ! ($res['response']['more'] ?? false)) {
                break;
            }

            $pageNo++;
        }

        return $returns;
    }

    protected function fetchOrderSns(object $shop, int $timeFrom, ?int $timeTo = null): array
    {
        $timeTo = $timeTo ?: now()->timestamp;
        $orderSns = [];

        foreach ($this->splitTimeWindows($timeFrom, $timeTo) as [$windowFrom, $windowTo]) {
            foreach (['create_time', 'update_time'] as $timeRangeField) {
                $cursor = '';

                do {
                    $params = [
                        'time_range_field' => $timeRangeField,
                        'time_from' => $windowFrom,
                        'time_to' => $windowTo,
                        'page_size' => 50,
                        'cursor' => $cursor,
                    ];

                    $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/api/v2/order/get_order_list', $params, $token, $shop->shop_id));

                    $response = $res['response'] ?? [];
                    foreach ($response['order_list'] ?? [] as $row) {
                        if (! empty($row['order_sn'])) {
                            $orderSns[] = (string) $row['order_sn'];
                        }
                    }

                    $cursor = (string) ($response['next_cursor'] ?? '');
                    $more = (bool) ($response['more'] ?? false);
                } while ($more && $cursor !== '');
            }
        }

        return array_values(array_unique($orderSns));
    }

    protected function splitTimeWindows(int $timeFrom, int $timeTo): array
    {
        $maxSpan = self::MAX_TIME_RANGE_DAYS * 86400;

        if ($timeTo <= $timeFrom) {
            return [[$timeFrom, $timeTo]];
        }

        $windows = [];
        $cursor = $timeFrom;

        while ($cursor < $timeTo) {
            $end = min($cursor + $maxSpan, $timeTo);
            $windows[] = [$cursor, $end];
            $cursor = $end;
        }

        return $windows;
    }

    protected function fetchOrderDetails(object $shop, array $orderSns): array
    {
        if (empty($orderSns)) {
            return [];
        }

        $params = [
            'order_sn_list' => implode(',', $orderSns),
            'response_optional_fields' => self::DETAIL_FIELDS,
        ];

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/api/v2/order/get_order_detail', $params, $token, $shop->shop_id));

        $orders = $res['response']['order_list'] ?? [];

        foreach ($orders as &$order) {
            $logistics = $this->resolveLogistics($shop, (string) ($order['order_sn'] ?? ''), (string) ($order['order_status'] ?? ''));
            $order['tracking_number'] = $logistics['tracking_number'];
            $order['pickup_code'] = $logistics['pickup_code'];
        }

        return $orders;
    }

    public function resolveTrackingNumber(object $shop, string $orderSn, string $status): ?string
    {
        return $this->resolveLogistics($shop, $orderSn, $status)['tracking_number'];
    }

    public function getTrackingInfo(string $shopId, string $orderSn): array
    {
        if ($orderSn === '') {
            return [];
        }

        try {
            $shop = $this->requireShop($shopId);
            $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request(
                'GET',
                '/api/v2/logistics/get_tracking_info',
                ['order_sn' => $orderSn],
                $token,
                $shop->shop_id,
            ));

            return $res['response']['tracking_info'] ?? [];
        } catch (\Throwable $e) {
            Log::warning("Shopee: gagal ambil tracking info {$orderSn}: ".$e->getMessage());

            return [];
        }
    }

    public function resolveLogistics(object $shop, string $orderSn, string $status): array
    {
        $empty = ['tracking_number' => null, 'pickup_code' => null];

        if ($orderSn === '' || ! in_array(strtoupper($status), ['READY_TO_SHIP', 'PROCESSED', 'SHIPPED', 'TO_CONFIRM_RECEIVE', 'COMPLETED'], true)) {
            return $empty;
        }

        try {
            $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/api/v2/logistics/get_tracking_number', ['order_sn' => $orderSn], $token, $shop->shop_id));

            return [
                'tracking_number' => $res['response']['tracking_number'] ?? null,
                'pickup_code' => $res['response']['pickup_code'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::warning("Shopee: gagal ambil tracking number {$orderSn}: ".$e->getMessage());

            return $empty;
        }
    }

    public function fetchReturnTracking(string $shopId, ?string $returnSn, ?string $orderSn = null): array
    {
        $empty = ['tracking_number' => null, 'carrier' => null, 'shipped_at' => null];

        try {
            $shop = $this->requireShop($shopId);

            if (! $returnSn && $orderSn) {
                $returnSn = $this->resolveReturnSnByOrder($shop, $orderSn);
            }

            if (! $returnSn) {
                return $empty;
            }

            $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request(
                'GET',
                '/api/v2/returns/get_return_detail',
                ['return_sn' => $returnSn],
                $token,
                $shop->shop_id,
            ));

            $detail = $res['response'] ?? [];
            $logistics = $detail['logistics'] ?? [];

            $tracking = $detail['tracking_number']
                ?? $logistics['tracking_number']
                ?? null;
            $carrier = $detail['shipping_carrier']
                ?? $logistics['shipping_carrier']
                ?? $logistics['logistics_name']
                ?? null;
            $shippedAt = isset($detail['create_time']) && $detail['create_time']
                ? now()->setTimestamp((int) $detail['create_time'])->toIso8601String()
                : null;

            return [
                'tracking_number' => $tracking ? (string) $tracking : null,
                'carrier' => $carrier ? (string) $carrier : null,
                'shipped_at' => $shippedAt,
            ];
        } catch (\Throwable $e) {
            Log::warning("Shopee: gagal ambil resi retur (return_sn={$returnSn}): ".$e->getMessage());

            return $empty;
        }
    }

    public function fetchReturnDetail(string $shopId, ?string $returnSn, ?string $orderSn = null): array
    {
        $empty = [
            'channel_status' => null,
            'reason_code' => null,
            'reason_text' => null,
            'refund_amount' => null,
            'refund_currency' => null,
            'shipping_fee_original' => null,
            'shipping_fee_return' => null,
            'tracking_number' => null,
            'carrier' => null,
            'shipped_at' => null,
            'raw' => [],
        ];

        try {
            $shop = $this->requireShop($shopId);

            if (! $returnSn && $orderSn) {
                $returnSn = $this->resolveReturnSnByOrder($shop, $orderSn);
            }

            if (! $returnSn) {
                return $empty;
            }

            $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request(
                'GET',
                '/api/v2/returns/get_return_detail',
                ['return_sn' => $returnSn],
                $token,
                $shop->shop_id,
            ));

            $detail = $res['response'] ?? [];
            $logistics = $detail['logistics'] ?? [];
            $refund = $detail['refund'] ?? [];

            $tracking = $detail['tracking_number'] ?? $logistics['tracking_number'] ?? null;
            $carrier = $detail['shipping_carrier']
                ?? $logistics['shipping_carrier']
                ?? $logistics['logistics_name']
                ?? null;
            $shippedAt = isset($detail['create_time']) && $detail['create_time']
                ? now()->setTimestamp((int) $detail['create_time'])->toIso8601String()
                : null;

            return [
                'channel_status' => isset($detail['status']) ? (string) $detail['status'] : null,
                'reason_code' => isset($detail['reason']) ? (string) $detail['reason'] : null,
                'reason_text' => $detail['text_reason'] ?? null,
                'refund_amount' => isset($refund['amount'])
                    ? (float) $refund['amount']
                    : (isset($detail['amount']) ? (float) $detail['amount'] : null),
                'refund_currency' => $detail['currency'] ?? null,
                'shipping_fee_original' => null,
                'shipping_fee_return' => isset($logistics['shipping_fee']) ? (float) $logistics['shipping_fee'] : null,
                'tracking_number' => $tracking ? (string) $tracking : null,
                'carrier' => $carrier ? (string) $carrier : null,
                'shipped_at' => $shippedAt,
                'raw' => $detail,
            ];
        } catch (\Throwable $e) {
            Log::warning("Shopee: gagal ambil detail retur (return_sn={$returnSn}): ".$e->getMessage());

            return $empty;
        }
    }

    public function fetchReturnHistory(string $shopId, string $returnSn): array
    {
        try {
            $shop = $this->requireShop($shopId);

            $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request(
                'GET',
                '/api/v2/returns/get_return_detail',
                ['return_sn' => $returnSn],
                $token,
                $shop->shop_id,
            ));

            $detail = $res['response'] ?? [];
            $negotiation = $detail['negotiation'] ?? [];
            $offers = $negotiation['counter_offer'] ?? [];

            $records = [];
            foreach ($offers as $offer) {
                $records[] = [
                    'type' => 'SELLER_DISPUTE',
                    'operator' => 'SELLER',
                    'description' => $offer['reason'] ?? null,
                    'timestamp' => isset($offer['create_time']) && $offer['create_time']
                        ? now()->setTimestamp((int) $offer['create_time'])->toIso8601String()
                        : null,
                ];
            }

            return ['records' => $records];
        } catch (\Throwable $e) {
            Log::warning("Shopee: gagal ambil riwayat banding retur (return_sn={$returnSn}): ".$e->getMessage());

            return ['records' => []];
        }
    }

    public function confirmReturn(string $shopId, string $returnSn): bool
    {
        try {
            $shop = $this->requireShop($shopId);

            $this->callWithRefresh($shop, fn (string $token) => $this->client->request(
                'POST',
                '/api/v2/returns/confirm',
                ['return_sn' => $returnSn],
                $token,
                $shop->shop_id,
            ));

            return true;
        } catch (\Throwable $e) {
            Log::warning("Shopee: gagal setujui retur (return_sn={$returnSn}): ".$e->getMessage());

            return false;
        }
    }

    public function disputeReturn(string $shopId, string $returnSn, string $disputeReason, ?string $disputeText = null, array $images = []): bool
    {
        try {
            $shop = $this->requireShop($shopId);

            $params = [
                'return_sn' => $returnSn,
                'dispute_reason' => $disputeReason,
            ];
            if ($disputeText) {
                $params['dispute_text_reason'] = $disputeText;
            }
            if (! empty($images)) {
                $params['images'] = $images;
            }

            $this->callWithRefresh($shop, fn (string $token) => $this->client->request(
                'POST',
                '/api/v2/returns/dispute',
                $params,
                $token,
                $shop->shop_id,
            ));

            return true;
        } catch (\Throwable $e) {
            Log::warning("Shopee: gagal tolak/dispute retur (return_sn={$returnSn}): ".$e->getMessage());

            return false;
        }
    }

    public function getDisputeReasons(): array
    {
        return [
            ['id' => 'DAMAGED_UPON_ARRIVAL', 'text' => 'Barang rusak bukan karena pengiriman'],
            ['id' => 'NOT_AS_DESCRIBED', 'text' => 'Klaim tidak sesuai deskripsi tidak valid'],
            ['id' => 'WRONG_ITEM', 'text' => 'Klaim barang salah tidak valid'],
            ['id' => 'OTHER', 'text' => 'Alasan lain'],
        ];
    }

    protected function resolveReturnSnByOrder(object $shop, string $orderSn): ?string
    {
        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request(
            'GET',
            '/api/v2/returns/get_return_list',
            ['page_no' => 0, 'page_size' => 20],
            $token,
            $shop->shop_id,
        ));

        foreach ($res['response']['return'] ?? [] as $ret) {
            if (($ret['order_sn'] ?? null) === $orderSn) {
                return $ret['return_sn'] ?? null;
            }
        }

        return null;
    }

    public function getEscrowDetail(string $shopId, string $orderSn): array
    {
        $shop = $this->requireShop($shopId);

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/api/v2/payment/get_escrow_detail', ['order_sn' => $orderSn], $token, $shop->shop_id));

        return $res['response'] ?? [];
    }

    public function getEscrowList(string $shopId, int $releaseTimeFrom, int $releaseTimeTo, int $pageNo = 1, int $pageSize = 100): array
    {
        $shop = $this->requireShop($shopId);

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/api/v2/payment/get_escrow_list', [
            'release_time_from' => $releaseTimeFrom,
            'release_time_to' => $releaseTimeTo,
            'page_no' => $pageNo,
            'page_size' => $pageSize,
        ], $token, $shop->shop_id));

        $response = $res['response'] ?? [];

        return [
            'escrow_list' => $response['escrow_list'] ?? [],
            'more' => (bool) ($response['more'] ?? false),
        ];
    }

    public function getCancelReasons(): array
    {

        return array_map(
            fn ($r) => ['id' => $r['key'], 'text' => $r['label']],
            app(MarketplaceCancelReasonService::class)->for('shopee'),
        );
    }

    public function getLogistics(string $shopId): array
    {
        $shop = $this->requireShop($shopId);

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/api/v2/logistics/get_channel_list', [], $token, $shop->shop_id));

        return $res['response']['logistics_channel_list'] ?? [];
    }

    public function instantChannelIds(string $shopId): array
    {
        $types = $this->shippingChannelTypes($shopId);

        return array_values(array_map(
            'strval',
            array_keys(array_filter(
                $types,
                static fn (?string $type): bool => ChannelInstantSignal::isInstantType($type),
            )),
        ));
    }

    /**
     * Return only categories explicitly supplied by Shopee.
     * A service with an explicit null category is retained as null. Shopee documents
     * null as a valid value, which is enough to determine that it is not instant,
     * while a missing category remains unknown.
     *
     * @return array<string, ?string>
     */
    public function shippingChannelTypes(string $shopId): array
    {
        $key = "shopee:shipping_channel_types:{$shopId}";
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $channels = $this->getLogistics($shopId);
        } catch (\Throwable $e) {
            Log::warning("Shopee shippingChannelTypes: gagal ambil channel list untuk {$shopId}: ".$e->getMessage());

            return [];
        }

        $types = [];
        foreach ($channels as $channel) {
            $id = $channel['logistics_channel_id'] ?? null;
            $rawType = null;
            $hasCategory = false;
            foreach (['service_type_identifier', 'service_type', 'type'] as $field) {
                if (! array_key_exists($field, $channel)) {
                    continue;
                }

                $rawType = $channel[$field];
                $hasCategory = true;
                break;
            }

            $normalizedType = ChannelInstantSignal::normalizeType(
                is_scalar($rawType) ? (string) $rawType : null,
            );

            if ($id !== null && $hasCategory) {
                $types[(string) $id] = $normalizedType;
            }
        }

        Cache::put($key, $types, now()->addHours(6));

        return $types;
    }

    public function shipOrder(string $shopId, string $orderSn, array $opts = []): array
    {
        $shop = $this->requireShop($shopId);

        $param = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/api/v2/logistics/get_shipping_parameter', ['order_sn' => $orderSn], $token, $shop->shop_id));

        $info = $param['response'] ?? [];
        $infoNeeded = $info['info_needed'] ?? [];

        $addressList = $info['pickup']['address_list'] ?? [];
        $branchList = $info['dropoff']['branch_list'] ?? [];
        $slug = $info['slug'] ?? ($info['dropoff']['slug'] ?? null);

        $method = $this->resolveHandoverMethod($opts, $infoNeeded, $addressList, $branchList);

        $body = ['order_sn' => $orderSn];

        if ($method === 'pickup') {
            $addressId = $opts['address_id'] ?? ($addressList[0]['address_id'] ?? null);

            $pickupTimeId = $opts['pickup_time_id'] ?? null;
            if ($pickupTimeId === null) {
                $chosenAddress = $addressList[0] ?? [];
                if ($addressId !== null) {
                    foreach ($addressList as $addr) {
                        if (($addr['address_id'] ?? null) == $addressId) {
                            $chosenAddress = $addr;
                            break;
                        }
                    }
                }
                $slots = $chosenAddress['time_slot_list'] ?? [];
                $recommended = null;
                foreach ($slots as $slot) {
                    if (in_array('recommended', (array) ($slot['flags'] ?? []), true)) {
                        $recommended = $slot;
                        break;
                    }
                }
                $pickupTimeId = $recommended['pickup_time_id'] ?? ($slots[0]['pickup_time_id'] ?? null);
            }

            $required = is_array($infoNeeded['pickup'] ?? null) ? $infoNeeded['pickup'] : [];
            $pickup = [
                'address_id' => $addressId,
                'pickup_time_id' => $pickupTimeId,
            ];

            foreach ($required as $field) {
                if (! array_key_exists($field, $pickup) && array_key_exists($field, $opts)) {
                    $pickup[$field] = $opts[$field];
                }
            }
            $pickup = array_filter($pickup, fn ($v) => $v !== null);

            if (! empty($required)) {
                $pickup = array_intersect_key($pickup, array_flip($required)) ?: $pickup;
            }

            $body['pickup'] = $pickup;
        } elseif ($method === 'dropoff') {
            $required = is_array($infoNeeded['dropoff'] ?? null) ? $infoNeeded['dropoff'] : [];
            $dropoff = [];
            foreach ($required as $field) {
                if (array_key_exists($field, $opts)) {
                    $dropoff[$field] = $opts[$field];
                }
            }

            foreach (['branch_id', 'sender_real_name', 'tracking_number'] as $field) {
                if (array_key_exists($field, $opts) && ! array_key_exists($field, $dropoff)) {
                    $dropoff[$field] = $opts[$field];
                }
            }
            if (empty($dropoff) && ! empty($branchList) && ! array_key_exists('branch_id', $dropoff)) {

                $defaultBranch = $branchList[0]['branch_id'] ?? null;
                if ($defaultBranch !== null && in_array('branch_id', $required, true)) {
                    $dropoff['branch_id'] = $defaultBranch;
                }
            }
            $body['dropoff'] = (object) $dropoff;
        } else {
            $trackingNumber = $opts['tracking_number'] ?? null;
            if ($trackingNumber === null || $trackingNumber === '') {
                return [
                    'order_sn' => $orderSn,
                    'shipped' => false,
                    'error' => 'tracking_number wajib untuk non_integrated',
                    'method' => 'non_integrated',
                ];
            }
            $body['non_integrated'] = ['tracking_number' => $trackingNumber];
        }

        if ($slug !== null && $slug !== '') {
            $body['slug'] = $slug;
        }

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('POST', '/api/v2/logistics/ship_order', $body, $token, $shop->shop_id));

        $this->resyncLocalOrder($shopId, $orderSn);

        return [
            'order_sn' => $orderSn,
            'shipped' => empty($res['error']),
            'error' => $res['error'] ?? null,
            'response' => $res['response'] ?? [],
            'method' => $method,
        ];
    }

    protected function resolveHandoverMethod(array $opts, array $infoNeeded, array $addressList, array $branchList): string
    {
        $method = $opts['method'] ?? null;
        if (in_array($method, ['pickup', 'dropoff', 'non_integrated'], true)) {
            return $method;
        }

        $pickupOffered = array_key_exists('pickup', $infoNeeded) ? $infoNeeded['pickup'] !== null : ! empty($addressList);
        $dropoffOffered = array_key_exists('dropoff', $infoNeeded) ? $infoNeeded['dropoff'] !== null : ! empty($branchList);
        $nonIntegratedOffered = array_key_exists('non_integrated', $infoNeeded) && $infoNeeded['non_integrated'] !== null;

        $offered = array_values(array_filter([
            $pickupOffered ? 'pickup' : null,
            $dropoffOffered ? 'dropoff' : null,
            $nonIntegratedOffered ? 'non_integrated' : null,
        ]));

        $preferred = $opts['preferred_method'] ?? null;

        if ($preferred !== null && in_array($preferred, $offered, true)) {
            return $preferred;
        }

        if (! empty($offered)) {
            return $offered[0];
        }

        return ! empty($addressList) ? 'pickup' : 'dropoff';
    }

    public function massShipOrder(string $shopId, array $orderSns, array $opts = []): array
    {
        $shop = $this->requireShop($shopId);

        $orderSns = array_values(array_filter(array_map('strval', $orderSns), fn ($v) => $v !== ''));
        if (empty($orderSns)) {
            return ['shipped' => false, 'error' => 'order_sn_list kosong', 'results' => []];
        }

        $param = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/api/v2/logistics/get_mass_shipping_parameter', [
            'order_list' => array_map(fn ($sn) => ['order_sn' => $sn], $orderSns),
        ], $token, $shop->shop_id));

        $info = $param['response'] ?? [];
        $infoNeeded = $info['info_needed'] ?? [];
        $addressList = $info['pickup']['address_list'] ?? [];
        $branchList = $info['dropoff']['branch_list'] ?? [];

        $method = $this->resolveHandoverMethod($opts, $infoNeeded, $addressList, $branchList);

        $body = [
            'order_list' => array_map(fn ($sn) => ['order_sn' => $sn], $orderSns),
        ];

        if ($method === 'pickup') {
            $addressId = $opts['address_id'] ?? ($addressList[0]['address_id'] ?? null);
            $pickupTimeId = $opts['pickup_time_id'] ?? ($addressList[0]['time_slot_list'][0]['pickup_time_id'] ?? null);
            $body['pickup'] = (object) array_filter([
                'address_id' => $addressId,
                'pickup_time_id' => $pickupTimeId,
            ], fn ($v) => $v !== null);
        } elseif ($method === 'dropoff') {
            $dropoff = [];
            foreach (['branch_id', 'sender_real_name'] as $field) {
                if (array_key_exists($field, $opts)) {
                    $dropoff[$field] = $opts[$field];
                }
            }
            $body['dropoff'] = (object) $dropoff;
        }

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('POST', '/api/v2/logistics/mass_ship_order', $body, $token, $shop->shop_id));

        $response = $res['response'] ?? [];

        $failByOrder = [];
        foreach (($response['result_list'] ?? []) as $row) {
            $sn = (string) ($row['order_sn'] ?? '');
            if ($sn !== '' && ! empty($row['fail_error'])) {
                $failByOrder[$sn] = $row['fail_message'] ?? $row['fail_error'];
            }
        }

        $results = [];
        foreach ($orderSns as $sn) {
            $ok = ! isset($failByOrder[$sn]);
            if ($ok) {
                $this->resyncLocalOrder($shopId, $sn);
            }
            $results[] = [
                'order_sn' => $sn,
                'shipped' => $ok && empty($res['error']),
                'error' => $failByOrder[$sn] ?? ($res['error'] ?? null),
            ];
        }

        return [
            'shipped' => empty($res['error']),
            'error' => $res['error'] ?? null,
            'method' => $method,
            'results' => $results,
            'response' => $response,
        ];
    }

    public function getShippingDocumentParameter(string $shopId, string $orderSn): array
    {
        $shop = $this->requireShop($shopId);

        return $this->callWithRefresh($shop, fn (string $token) => $this->client->request('POST', '/api/v2/logistics/get_shipping_document_parameter', [
            'order_list' => [['order_sn' => $orderSn]],
        ], $token, $shop->shop_id));
    }

    public function resolveSupportedDocType(string $shopId, string $orderSn, string $fallback): string
    {
        try {
            $params = $this->getShippingDocumentParameter($shopId, $orderSn);
            $row = $params['response']['result_list'][0] ?? [];

            $selectable = [];
            foreach ((array) ($row['selectable_shipping_document_type'] ?? []) as $type) {
                if (is_string($type) && $type !== '') {
                    $selectable[] = $type;
                }
            }
            foreach ((array) ($row['shipping_document_info'] ?? []) as $info) {
                if (! empty($info['shipping_document_type'])) {
                    $selectable[] = $info['shipping_document_type'];
                }
            }

            if ($selectable && in_array($fallback, $selectable, true)) {
                return $fallback;
            }

            $suggested = $row['suggest_shipping_document_type'] ?? null;
            if ($suggested) {
                return $suggested;
            }
            if ($selectable) {
                return $selectable[0];
            }
        } catch (\Throwable $e) {
            Log::warning("Shopee: gagal get_shipping_document_parameter {$orderSn}: ".$e->getMessage());
        }

        return $fallback;
    }

    public function getPackageDetailByOrderSn(string $shopId, string $orderSn, ?string $packageNumber = null): array
    {
        $shop = $this->requireShop($shopId);

        $packageNumber = $packageNumber ?: $this->resolvePackageNumber($shop, $orderSn);
        if (! $packageNumber) {
            return ['response' => ['package_list' => []]];
        }

        return $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/api/v2/order/get_package_detail', [
            'package_number_list' => $packageNumber,
        ], $token, $shop->shop_id));
    }

    private function resolvePackageNumber(object $shop, string $orderSn): ?string
    {
        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/api/v2/order/get_order_detail', [
            'order_sn_list' => $orderSn,
            'response_optional_fields' => 'package_list',
        ], $token, $shop->shop_id));

        return $res['response']['order_list'][0]['package_list'][0]['package_number'] ?? null;
    }

    public function getShippingDocumentDataInfo(string $shopId, string $orderSn, ?string $packageNumber = null, string $docType = 'NORMAL_AIR_WAYBILL'): array
    {
        $shop = $this->requireShop($shopId);

        $payload = array_filter([
            'order_sn' => $orderSn,
            'package_number' => $packageNumber,
            'shipping_document_type' => $docType,
        ], static fn ($v) => $v !== null && $v !== '');

        return $this->callWithRefresh($shop, fn (string $token) => $this->client->request('POST', '/api/v2/logistics/get_shipping_document_data_info', $payload, $token, $shop->shop_id));
    }

    public function checkAllowSelfDesignAwb(object|string $shop, string $orderSn, ?string $packageNumber = null): bool
    {
        try {
            $shopId = is_string($shop) ? $shop : (string) ($shop->shop_id ?? '');
            $res = $this->getPackageDetailByOrderSn($shopId, $orderSn, $packageNumber);
            $pkg = $res['response']['package_list'][0] ?? [];

            return (bool) ($pkg['allow_self_design_awb'] ?? false);
        } catch (\Throwable $e) {
            Log::warning("Shopee: gagal cek allow_self_design_awb {$orderSn}: ".$e->getMessage());

            return false;
        }
    }

    public const LABEL_FAILURE_SELF_DESIGN = 'self_design_required';

    public const LABEL_FAILURE_PARCEL_SHIPPED = 'parcel_already_shipped';

    public function classifyShippingLabelFailure(mixed $failure): ?string
    {
        $parts = [];

        if ($failure instanceof \Throwable) {
            $parts[] = $failure->getMessage();

            if (property_exists($failure, 'rawMessage')) {
                $parts[] = (string) $failure->rawMessage;
            }
            if (property_exists($failure, 'errorInfo')) {
                $parts[] = (string) $failure->errorInfo;
            }
        } elseif (is_array($failure)) {
            $parts[] = (string) ($failure['error'] ?? '');
            $parts[] = (string) ($failure['message'] ?? '');
            $parts[] = (string) data_get($failure, 'response.result_list.0.fail_error', '');
            $parts[] = (string) data_get($failure, 'response.result_list.0.fail_message', '');
        } elseif (is_string($failure)) {
            $parts[] = $failure;
        }

        $message = mb_strtolower(implode(' ', array_filter($parts)));

        foreach ([
            'parcel has been shipped',
            'package has been shipped',
            'already shipped',
            'can not print now',
            'cannot print now',
            'sudah dikirim',
            'statusnya dikirim',
        ] as $marker) {
            if (str_contains($message, $marker)) {
                return self::LABEL_FAILURE_PARCEL_SHIPPED;
            }
        }

        foreach ([
            'self-design',
            'self design',
            'self_design',
            'design label sendiri',
            'label sendiri',
        ] as $marker) {
            if (str_contains($message, $marker)) {
                return self::LABEL_FAILURE_SELF_DESIGN;
            }
        }

        return null;
    }

    public function createShippingDocument(string $shopId, string $orderSn, string $docType = 'NORMAL_AIR_WAYBILL', ?string $trackingNumber = null, ?string $packageNumber = null): array
    {
        $shop = $this->requireShop($shopId);

        $orderPayload = [
            'order_sn' => $orderSn,
            'shipping_document_type' => $docType,
        ];
        if (! empty($trackingNumber)) {
            $orderPayload['tracking_number'] = $trackingNumber;
        }
        if (! empty($packageNumber)) {
            $orderPayload['package_number'] = $packageNumber;
        }

        return $this->callWithRefresh($shop, fn (string $token) => $this->client->request('POST', '/api/v2/logistics/create_shipping_document', [
            'order_list' => [$orderPayload],
        ], $token, $shop->shop_id));
    }

    public function getShippingDocumentResult(string $shopId, string $orderSn, string $docType = 'NORMAL_AIR_WAYBILL', ?string $trackingNumber = null, ?string $packageNumber = null): array
    {
        $shop = $this->requireShop($shopId);

        $orderPayload = [
            'order_sn' => $orderSn,
            'shipping_document_type' => $docType,
        ];
        if (! empty($trackingNumber)) {
            $orderPayload['tracking_number'] = $trackingNumber;
        }
        if (! empty($packageNumber)) {
            $orderPayload['package_number'] = $packageNumber;
        }

        return $this->callWithRefresh($shop, fn (string $token) => $this->client->request('POST', '/api/v2/logistics/get_shipping_document_result', [
            'order_list' => [$orderPayload],
        ], $token, $shop->shop_id));
    }

    public function downloadShippingDocument(string $shopId, string $orderSn, string $docType = 'NORMAL_AIR_WAYBILL', ?string $trackingNumber = null, ?string $packageNumber = null): array
    {
        $shop = $this->requireShop($shopId);

        $orderPayload = ['order_sn' => $orderSn];
        if (! empty($trackingNumber)) {
            $orderPayload['tracking_number'] = $trackingNumber;
        }
        if (! empty($packageNumber)) {
            $orderPayload['package_number'] = $packageNumber;
        }

        return $this->callWithRefresh($shop, fn (string $token) => $this->client->requestBinary('/api/v2/logistics/download_shipping_document', [
            'shipping_document_type' => $docType,
            'order_list' => [$orderPayload],
        ], $token, $shop->shop_id));
    }

    public function getAirwayBill(string $shopId, string $orderSn, string $docType = 'NORMAL_AIR_WAYBILL', ?string $trackingNumber = null, ?string $packageNumber = null): array
    {
        $resolvedType = $this->resolveSupportedDocType($shopId, $orderSn, $docType);

        if (empty($trackingNumber)) {
            $shop = $this->requireShop($shopId);
            $trackingNumber = $this->resolveTrackingNumber($shop, $orderSn, 'READY_TO_SHIP');
        }

        $create = $this->createShippingDocument($shopId, $orderSn, $resolvedType, $trackingNumber, $packageNumber);
        if (! empty($create['error'])) {
            $failDetail = $create['response']['result_list'][0]['fail_message']
                ?? $create['response']['result_list'][0]['fail_error']
                ?? null;

            return [
                'order_sn' => $orderSn,
                'ready' => false,
                'error' => $create['error'],
                'message' => $failDetail ?? $create['message'] ?? null,
                'doc_type' => $resolvedType,
            ];
        }

        $docType = $resolvedType;

        $maxRetries = 6;
        $status = null;
        for ($i = 0; $i < $maxRetries; $i++) {
            $result = $this->getShippingDocumentResult($shopId, $orderSn, $docType, $trackingNumber, $packageNumber);
            $row = $result['response']['result_list'][0] ?? [];
            $status = strtoupper((string) ($row['status'] ?? ''));

            if ($status === 'READY') {
                break;
            }
            if ($status === 'FAILED') {
                return [
                    'order_sn' => $orderSn,
                    'ready' => false,
                    'status' => $status,
                    'error' => $row['fail_error'] ?? 'shipping document generation failed',
                    'message' => $row['fail_message'] ?? null,
                ];
            }

            usleep(800_000);
        }

        if ($status !== 'READY') {
            return [
                'order_sn' => $orderSn,
                'ready' => false,
                'status' => $status,
                'error' => 'shipping document belum READY setelah polling',
            ];
        }

        $download = $this->downloadShippingDocument($shopId, $orderSn, $docType, $trackingNumber, $packageNumber);

        if (! empty($download['binary'])) {
            return [
                'order_sn' => $orderSn,
                'ready' => true,
                'status' => 'READY',
                'doc_type' => $docType,
                'content_type' => $download['content_type'] ?? 'application/pdf',
                'document_base64' => base64_encode((string) ($download['content'] ?? '')),
            ];
        }

        $payload = $download['response'] ?? $download;

        return [
            'order_sn' => $orderSn,
            'ready' => true,
            'status' => 'READY',
            'doc_type' => $docType,
            'content_type' => 'application/pdf',
            'document' => $payload,
        ];
    }

    public function searchPackageList(string $shopId, array $opts = []): array
    {
        $shop = $this->requireShop($shopId);

        $body = array_filter([
            'package_status' => $opts['package_status'] ?? 2,
            'cursor' => $opts['cursor'] ?? null,
            'page_size' => $opts['page_size'] ?? 40,
            'logistics_channel_id' => $opts['logistics_channel_id'] ?? null,
        ], fn ($v) => $v !== null);

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('POST', '/api/v2/order/search_package_list', $body, $token, $shop->shop_id));

        return $res['response'] ?? [];
    }

    public function updateShippingOrder(string $shopId, string $orderSn, array $pickup): array
    {
        $shop = $this->requireShop($shopId);

        $body = [
            'order_sn' => $orderSn,
            'pickup' => (object) array_filter([
                'address_id' => $pickup['address_id'] ?? null,
                'pickup_time_id' => $pickup['pickup_time_id'] ?? null,
            ], fn ($v) => $v !== null),
        ];

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('POST', '/api/v2/logistics/update_shipping_order', $body, $token, $shop->shop_id));

        $this->resyncLocalOrder($shopId, $orderSn);

        return ['order_sn' => $orderSn, 'updated' => empty($res['error']), 'error' => $res['error'] ?? null, 'response' => $res['response'] ?? []];
    }

    public function handleBuyerCancellation(string $shopId, string $orderSn, string $operation): array
    {
        $shop = $this->requireShop($shopId);

        $operation = strtoupper($operation);
        if (! in_array($operation, ['ACCEPT', 'REJECT'], true)) {
            throw new \Exception("operation harus ACCEPT atau REJECT: {$operation}");
        }

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('POST', '/api/v2/order/handle_buyer_cancellation', [
            'order_sn' => $orderSn,
            'operation' => $operation,
        ], $token, $shop->shop_id));

        $this->resyncLocalOrder($shopId, $orderSn);

        return ['order_sn' => $orderSn, 'handled' => empty($res['error']), 'operation' => $operation, 'error' => $res['error'] ?? null, 'response' => $res['response'] ?? []];
    }

    public function splitOrder(string $shopId, string $orderSn, array $packageList): array
    {
        $shop = $this->requireShop($shopId);

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('POST', '/api/v2/order/split_order', [
            'order_sn' => $orderSn,
            'package_list' => $packageList,
        ], $token, $shop->shop_id));

        $this->resyncLocalOrder($shopId, $orderSn);

        return ['order_sn' => $orderSn, 'split' => empty($res['error']), 'error' => $res['error'] ?? null, 'response' => $res['response'] ?? []];
    }

    public function unsplitOrder(string $shopId, string $orderSn): array
    {
        $shop = $this->requireShop($shopId);

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('POST', '/api/v2/order/unsplit_order', [
            'order_sn' => $orderSn,
        ], $token, $shop->shop_id));

        $this->resyncLocalOrder($shopId, $orderSn);

        return ['order_sn' => $orderSn, 'unsplit' => empty($res['error']), 'error' => $res['error'] ?? null, 'response' => $res['response'] ?? []];
    }

    public function cancelOrder(string $shopId, string $orderSn, string $cancelReason): array
    {
        $shop = $this->requireShop($shopId);

        $itemList = $this->orderItemList($shop, $orderSn);
        if (empty($itemList)) {
            throw new \Exception("Order {$orderSn} tidak punya item untuk dibatalkan.");
        }

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('POST', '/api/v2/order/cancel_order', [
            'order_sn' => $orderSn,
            'cancel_reason' => $cancelReason,
            'item_list' => $itemList,
        ], $token, $shop->shop_id));

        if (! empty($res['error'])) {
            $code = (string) $res['error'];
            $message = $res['message'] ?? $code;
            $transient = (bool) preg_match('/server|network|timeout|inner http/i', $code.' '.$message);

            throw new ChannelCancelException(
                "Shopee menolak pembatalan {$orderSn}: {$message}",
                retryable: $transient,
                channelCode: $code,
            );
        }

        $this->resyncLocalOrder($shopId, $orderSn);

        return ['order_sn' => $orderSn, 'cancelled' => true, 'response' => $res['response'] ?? []];
    }

    protected function orderItemList(object $shop, string $orderSn): array
    {
        $details = $this->fetchOrderDetails($shop, [$orderSn]);
        $items = $details[0]['item_list'] ?? [];

        return array_values(array_filter(array_map(
            fn (array $row) => isset($row['item_id'])
                ? ['item_id' => (int) $row['item_id'], 'model_id' => (int) ($row['model_id'] ?? 0)]
                : null,
            $items
        )));
    }

    public function retryPickup(string $shopId, string $orderSn): array
    {
        $shop = $this->requireShop($shopId);

        $param = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/api/v2/logistics/get_shipping_parameter', ['order_sn' => $orderSn], $token, $shop->shop_id));

        $info = $param['response'] ?? [];
        $addressList = $info['pickup']['address_list'] ?? [];

        if (empty($addressList)) {
            throw new \Exception('Tidak ada alamat pickup yang tersedia. Silakan atur ulang melalui Shopee Seller Center.');
        }

        $addressId = $addressList[0]['address_id'] ?? null;
        $pickupTimeId = $addressList[0]['time_slot_list'][0]['pickup_time_id'] ?? null;

        $pickup = array_filter([
            'address_id' => $addressId,
            'pickup_time_id' => $pickupTimeId,
        ], fn ($v) => $v !== null);

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('POST', '/api/v2/logistics/update_shipping_order', [
            'order_sn' => $orderSn,
            'pickup' => (object) $pickup,
        ], $token, $shop->shop_id));

        $this->resyncLocalOrder($shopId, $orderSn);

        return ['order_sn' => $orderSn, 'updated' => empty($res['error']), 'error' => $res['error'] ?? null, 'response' => $res['response'] ?? []];
    }

    protected function resyncLocalOrder(string $shopId, string $orderSn): void
    {
        try {
            $this->pullOrderById($shopId, $orderSn);
        } catch (\Throwable $e) {
            Log::warning("Shopee: resync order {$orderSn} gagal pasca aksi: ".$e->getMessage());
        }
    }

    protected function callWithRefresh(object $shop, callable $fn): array
    {
        try {
            return $fn($shop->access_token);
        } catch (TokenExpiredException $e) {
            Log::info("Shopee: token toko {$shop->shop_id} kedaluwarsa — refresh lalu retry.");

            $this->authService->refreshStoreToken((string) $shop->id);
            $fresh = $this->shopRepository->findByShopId($shop->shop_id);

            return $fn($fresh->access_token);
        }
    }

    protected function requireShop(string $shopId): object
    {
        $shop = $this->shopRepository->findByShopId($shopId);

        if (! $shop || ! $shop->access_token) {
            throw new \Exception("Toko Shopee tidak ditemukan atau belum terhubung: {$shopId}");
        }

        return $shop;
    }
}
