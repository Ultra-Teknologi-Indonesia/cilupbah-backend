<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;
use Modules\Sales\Services\SalesOrderService as OrderService;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Repositories\ChannelOrderRepository;

class TikTokOrderService
{
    protected TikTokClient $client;
    protected TikTokToInternalOrderMapper $mapper;
    protected OrderService $orderService;
    protected ChannelShopRepository $shopRepository;
    protected ChannelOrderRepository $orderRepository;

    public function __construct(
        TikTokClient $client, 
        TikTokToInternalOrderMapper $mapper, 
        OrderService $orderService,
        ChannelShopRepository $shopRepository,
        ChannelOrderRepository $orderRepository
    ) {
        $this->client = $client;
        $this->mapper = $mapper;
        $this->orderService = $orderService;
        $this->shopRepository = $shopRepository;
        $this->orderRepository = $orderRepository;
    }

    public function pullOrders(string $shopId): int
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (!$shop || !$shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $accessToken = $shop->access_token;
        $shopCipher = $shop->shop_cipher ?? '';

        $queries = [
            'shop_cipher' => $shopCipher,
            'page_size' => 100,
        ];
        
        $body = [
            'sort_by' => 'CREATE_TIME',
            'sort_type' => 'DESC'
        ];

        $res = $this->client->request('POST', '/order/202309/orders/search', $queries, $body, $accessToken);
        
        if (!isset($res['data']['orders'])) {
            return 0; 
        }

        $count = 0;
        foreach ($res['data']['orders'] as $item) {
            try {
                $internalData = $this->mapper->map($item, $shopId);
                $this->orderService->upsertFromChannel($internalData);
                $count++;
            } catch (\Exception $e) {
                Log::error("Failed to pull order {$item['id']}: " . $e->getMessage());
            }
        }
        
        return $count;
    }

    public function pullOrderById(string $shopId, string $orderId): ?int
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (!$shop || !$shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $accessToken = $shop->access_token;
        $shopCipher = $shop->shop_cipher ?? '';

        $queries = [
            'shop_cipher' => $shopCipher,
            'ids' => $orderId,
        ];

        $res = $this->client->request('GET', '/order/202309/orders', $queries, [], $accessToken);
        
        if (!isset($res['data']['orders']) || empty($res['data']['orders'])) {
            return 0; 
        }

        $count = 0;
        foreach ($res['data']['orders'] as $item) {
            try {
                $internalData = $this->mapper->map($item, $shopId);
                $this->orderService->upsertFromChannel($internalData);
                $count++;
            } catch (\Exception $e) {
                Log::error("Failed to pull specific order {$item['id']}: " . $e->getMessage());
            }
        }
        
        return $count;
    }

    public function acceptOrder(string $shopId, string $orderId): array
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (!$shop || !$shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];
        $body = ['order_id' => $orderId];

        try {
            $res = $this->client->request('POST', '/fulfillment/202309/packages', $queries, $body, $shop->access_token);
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'invalid params') !== false) {
                $res = ['bypassed' => true];
            } else {
                throw $e;
            }
        }
        
        $this->orderRepository->updateOrderStatusByOrderNo($orderId, 'PROCESSING');

        return $res;
    }

    public function declineOrder(string $shopId, string $orderId, string $reason): array
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (!$shop || !$shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];
        $body = [
            'order_id' => $orderId,
            'cancel_reason_key' => $reason,
            'cancel_reason' => $reason
        ];

        $res = $this->client->request('POST', "/return_refund/202309/cancellations", $queries, $body, $shop->access_token);
        
        $this->orderRepository->updateOrderStatusByOrderNo($orderId, 'CANCELLED');

        return $res;
    }

    public function getCancelReasons(): array
    {
        return [
            "seller_cancel_reason_out_of_stock" => "Stok habis",
            "seller_cancel_reason_wrong_price" => "Kesalahan harga",
            "seller_cancel_paid_reason_address_not_deliver" => "Alamat pembeli tidak terjangkau"
        ];
    }

    public function cancelProduct(string $orderId, string $reason): array
    {
        $order = $this->orderRepository->findOrderBySalesOrderNo($orderId);
        if (!$order) {
            throw new \Exception('Pesanan tidak ditemukan di sistem lokal');
        }

        if (!in_array($order->channel_status, ['ON_HOLD', 'AWAITING_SHIPMENT'])) {
            throw new \Exception("Pembatalan ditolak. Status pesanan saat ini adalah {$order->channel_status}. Hanya berlaku untuk ON_HOLD dan AWAITING_SHIPMENT.");
        }

        $shop = $this->shopRepository->findByShopId($order->channel_shop_id);
        if (!$shop || !$shop->access_token) {
            throw new \Exception("No access token found for shop: {$order->channel_shop_id}");
        }

        $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];
        $body = [
            'order_id' => $orderId,
            'cancel_reason_key' => $reason,
            'cancel_reason' => $reason
        ];

        $res = $this->client->request('POST', "/return_refund/202309/cancellations", $queries, $body, $shop->access_token);
        
        return $res;
    }
}
