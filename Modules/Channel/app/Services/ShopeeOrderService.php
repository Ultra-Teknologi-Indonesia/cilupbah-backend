<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;
use Modules\Channel\Exceptions\TokenExpiredException;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Sales\Services\SalesOrderService;

class ShopeeOrderService
{
    /** Field detail order yang diminta dari Shopee (response_optional_fields). */
    private const DETAIL_FIELDS = 'recipient_address,item_list,total_amount,buyer_username,payment_method,estimated_shipping_fee,shipping_carrier,note,message_to_seller,pay_time,cancel_reason';

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

    /**
     * Ambil daftar order_sn dalam window waktu (paginasi cursor Shopee).
     *
     * @return string[]
     */
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

    /**
     * @param string[] $orderSns maksimal 50 per panggilan.
     * @return array<int, array> daftar order detail.
     */
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

    /** Tracking number Shopee tidak ada di order detail — ditarik terpisah hanya untuk status yang relevan. */
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

    /** Alasan pembatalan Shopee adalah enum tetap (tidak ada API). */
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

    /**
     * Terima & kirim order: tentukan metode dari get_shipping_parameter lalu ship_order.
     */
    public function shipOrder(string $shopId, string $orderSn): array
    {
        $shop = $this->requireShop($shopId);

        $param = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/api/v2/logistics/get_shipping_parameter', ['order_sn' => $orderSn], $token, $shop->shop_id));

        $info = $param['response'] ?? [];
        $body = ['order_sn' => $orderSn];

        // Shopee mengembalikan info_needed: gunakan dropoff bila tersedia, jika tidak pickup.
        if (! empty($info['dropoff'])) {
            $body['dropoff'] = (object) [];
        } else {
            $addressId = $info['pickup']['address_list'][0]['address_id'] ?? null;
            $timeSlot = $info['pickup']['address_list'][0]['time_slot_list'][0]['pickup_time_id'] ?? null;
            $body['pickup'] = array_filter([
                'address_id' => $addressId,
                'pickup_time_id' => $timeSlot,
            ], fn ($v) => $v !== null);
        }

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('POST', '/api/v2/logistics/ship_order', $body, $token, $shop->shop_id));

        $this->resyncLocalOrder($shopId, $orderSn);

        return ['order_sn' => $orderSn, 'shipped' => empty($res['error']), 'response' => $res['response'] ?? []];
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

    /** @return array<int, array{item_id:int, model_id:int}> */
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
