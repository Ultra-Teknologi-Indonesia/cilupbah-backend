<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;
use Modules\Channel\Exceptions\TokenExpiredException;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Sales\Services\SalesOrderService;

class ShopeeOrderService
{

    private const DETAIL_FIELDS = 'recipient_address,item_list,total_amount,buyer_username,payment_method,estimated_shipping_fee,shipping_carrier,note,message_to_seller,pay_time,cancel_reason,package_list';

    public function __construct(
        protected ShopeeClient $client,
        protected ShopeeToInternalOrderMapper $mapper,
        protected SalesOrderService $orderService,
        protected ChannelShopRepository $shopRepository,
        protected ShopeeAuthService $authService,
    ) {}

    public function pullOrders(string $shopId, ?int $updatedAfter = null): int
    {
        $shop = $this->requireShop($shopId);

        $timeFrom = $updatedAfter ?: now()->subDays(7)->timestamp;
        $orderSns = $this->fetchOrderSns($shop, $timeFrom);

        if (empty($orderSns)) {
            return 0;
        }

        $count = 0;
        foreach (array_chunk($orderSns, 50) as $chunk) {
            foreach ($this->fetchOrderDetails($shop, $chunk) as $order) {
                $orderSn = (string) ($order['order_sn'] ?? '');

                try {
                    $internal = $this->mapper->map($order, $shopId);
                    $this->orderService->upsertFromChannel($internal);
                    $count++;
                } catch (\Throwable $e) {
                    Log::error("Shopee: gagal upsert order {$orderSn}: " . $e->getMessage());
                }
            }
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

        $internal = $this->mapper->map($order, $shopId);
        $this->orderService->upsertFromChannel($internal);

        return 1;
    }

    protected function fetchOrderSns(object $shop, int $timeFrom): array
    {
        $orderSns = [];
        $cursor = '';

        do {
            $params = [
                'time_range_field' => 'update_time',
                'time_from' => $timeFrom,
                'time_to' => now()->timestamp,
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

        return $orderSns;
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
            $order['tracking_number'] = $this->resolveTrackingNumber($shop, (string) ($order['order_sn'] ?? ''), (string) ($order['order_status'] ?? ''));
        }

        return $orders;
    }

    protected function resolveTrackingNumber(object $shop, string $orderSn, string $status): ?string
    {
        if ($orderSn === '' || ! in_array(strtoupper($status), ['PROCESSED', 'SHIPPED', 'TO_CONFIRM_RECEIVE', 'COMPLETED'], true)) {
            return null;
        }

        try {
            $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/api/v2/logistics/get_tracking_number', ['order_sn' => $orderSn], $token, $shop->shop_id));

            return $res['response']['tracking_number'] ?? null;
        } catch (\Throwable $e) {
            Log::warning("Shopee: gagal ambil tracking number {$orderSn}: " . $e->getMessage());

            return null;
        }
    }

    public function getCancelReasons(): array
    {
        return [
            ['id' => 'OUT_OF_STOCK', 'text' => 'Stok habis'],
            ['id' => 'CUSTOMER_REQUEST', 'text' => 'Permintaan pembeli'],
            ['id' => 'UNDELIVERABLE_AREA', 'text' => 'Area tidak terjangkau'],
            ['id' => 'COD_NOT_SUPPORTED', 'text' => 'COD tidak didukung'],
        ];
    }

    public function getLogistics(string $shopId): array
    {
        $shop = $this->requireShop($shopId);

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/api/v2/logistics/get_channel_list', [], $token, $shop->shop_id));

        return $res['response']['logistics_channel_list'] ?? [];
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

        $method = $opts['method'] ?? null;
        if (! in_array($method, ['pickup', 'dropoff', 'non_integrated'], true)) {
            // info_needed adalah sinyal utama; bila tidak ada/kosong, pakai
            // ketersediaan address_list/branch_list. Jangan default ke non_integrated
            // kecuali memang ditawarkan info_needed.
            $pickupOffered = array_key_exists('pickup', $infoNeeded) ? $infoNeeded['pickup'] !== null : ! empty($addressList);
            $dropoffOffered = array_key_exists('dropoff', $infoNeeded) ? $infoNeeded['dropoff'] !== null : ! empty($branchList);
            $nonIntegratedOffered = array_key_exists('non_integrated', $infoNeeded) && $infoNeeded['non_integrated'] !== null;

            if ($pickupOffered) {
                $method = 'pickup';
            } elseif ($dropoffOffered) {
                $method = 'dropoff';
            } elseif ($nonIntegratedOffered) {
                $method = 'non_integrated';
            } else {
                $method = ! empty($addressList) ? 'pickup' : 'dropoff';
            }
        }

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
                $pickupTimeId = $chosenAddress['time_slot_list'][0]['pickup_time_id'] ?? null;
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
            // Array biasa (bukan object) — pickup selalu berisi address_id/pickup_time_id.
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

        $method = $opts['method'] ?? null;
        if (! in_array($method, ['pickup', 'dropoff', 'non_integrated'], true)) {
            $pickupOffered = array_key_exists('pickup', $infoNeeded) ? $infoNeeded['pickup'] !== null : ! empty($addressList);
            $dropoffOffered = array_key_exists('dropoff', $infoNeeded) ? $infoNeeded['dropoff'] !== null : ! empty($branchList);
            $nonIntegratedOffered = array_key_exists('non_integrated', $infoNeeded) && $infoNeeded['non_integrated'] !== null;

            if ($pickupOffered) {
                $method = 'pickup';
            } elseif ($dropoffOffered) {
                $method = 'dropoff';
            } elseif ($nonIntegratedOffered) {
                $method = 'non_integrated';
            } else {
                $method = ! empty($addressList) ? 'pickup' : 'dropoff';
            }
        }

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

    public function createShippingDocument(string $shopId, string $orderSn, string $docType = 'NORMAL_AIR_WAYBILL'): array
    {
        $shop = $this->requireShop($shopId);

        return $this->callWithRefresh($shop, fn (string $token) => $this->client->request('POST', '/api/v2/logistics/create_shipping_document', [
            'order_list' => [[
                'order_sn' => $orderSn,
                'shipping_document_type' => $docType,
            ]],
        ], $token, $shop->shop_id));
    }

    public function getShippingDocumentResult(string $shopId, string $orderSn, string $docType = 'NORMAL_AIR_WAYBILL'): array
    {
        $shop = $this->requireShop($shopId);

        return $this->callWithRefresh($shop, fn (string $token) => $this->client->request('POST', '/api/v2/logistics/get_shipping_document_result', [
            'order_list' => [[
                'order_sn' => $orderSn,
                'shipping_document_type' => $docType,
            ]],
        ], $token, $shop->shop_id));
    }

    public function downloadShippingDocument(string $shopId, string $orderSn, string $docType = 'NORMAL_AIR_WAYBILL'): array
    {
        $shop = $this->requireShop($shopId);

        // download_shipping_document mengembalikan PDF biner — pakai requestBinary agar
        // byte tidak hilang saat Shopee membalas stream (bukan base64-in-JSON).
        return $this->callWithRefresh($shop, fn (string $token) => $this->client->requestBinary('/api/v2/logistics/download_shipping_document', [
            'shipping_document_type' => $docType,
            'order_list' => [['order_sn' => $orderSn]],
        ], $token, $shop->shop_id));
    }

    public function getAirwayBill(string $shopId, string $orderSn, string $docType = 'NORMAL_AIR_WAYBILL'): array
    {
        $create = $this->createShippingDocument($shopId, $orderSn, $docType);
        if (! empty($create['error'])) {
            return [
                'order_sn' => $orderSn,
                'ready' => false,
                'error' => $create['error'],
                'message' => $create['message'] ?? null,
            ];
        }

        $maxRetries = 6;
        $status = null;
        for ($i = 0; $i < $maxRetries; $i++) {
            $result = $this->getShippingDocumentResult($shopId, $orderSn, $docType);
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

        $download = $this->downloadShippingDocument($shopId, $orderSn, $docType);

        // Kasus 1: stream PDF biner → encode base64 agar aman ditransport via JSON API.
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

        // Kasus 2: Shopee membalas base64-in-JSON. Nama field persis perlu validasi sandbox.
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

    public function getPackageDetail(string $shopId, array $packageNumbers): array
    {
        $shop = $this->requireShop($shopId);

        $packageNumbers = array_values(array_filter(array_map('strval', $packageNumbers), fn ($v) => $v !== ''));

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('POST', '/api/v2/order/get_package_detail', [
            'package_number_list' => $packageNumbers,
        ], $token, $shop->shop_id));

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

        $this->resyncLocalOrder($shopId, $orderSn);

        return ['order_sn' => $orderSn, 'cancelled' => empty($res['error']), 'response' => $res['response'] ?? []];
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

    protected function resyncLocalOrder(string $shopId, string $orderSn): void
    {
        try {
            $this->pullOrderById($shopId, $orderSn);
        } catch (\Throwable $e) {
            Log::warning("Shopee: resync order {$orderSn} gagal pasca aksi: " . $e->getMessage());
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
