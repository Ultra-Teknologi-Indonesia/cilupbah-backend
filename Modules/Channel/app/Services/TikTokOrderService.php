<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Order\Services\OrderService;

class TikTokOrderService
{
    protected TikTokClient $client;
    protected TikTokToInternalOrderMapper $mapper;
    protected OrderService $orderService;

    public function __construct(TikTokClient $client, TikTokToInternalOrderMapper $mapper, OrderService $orderService)
    {
        $this->client = $client;
        $this->mapper = $mapper;
        $this->orderService = $orderService;
    }

    public function pullOrders(string $shopId): int
    {
        $shop = DB::table('channel_shops')->where('shop_id', $shopId)->first();
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

    public function acceptOrder(string $shopId, string $orderId): array
    {
        $shop = DB::table('channel_shops')->where('shop_id', $shopId)->first();
        if (!$shop || !$shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];
        $body = ['order_id' => $orderId];

        $res = $this->client->request('POST', '/fulfillment/202309/packages', $queries, $body, $shop->access_token);
        
        DB::table('orders')->where('order_number', $orderId)->update(['status' => 'PROCESSING']);

        return $res;
    }

    public function declineOrder(string $shopId, string $orderId, string $reason): array
    {
        $shop = DB::table('channel_shops')->where('shop_id', $shopId)->first();
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
        
        DB::table('orders')->where('order_number', $orderId)->update(['status' => 'CANCELLED']);

        return $res;
    }
}
