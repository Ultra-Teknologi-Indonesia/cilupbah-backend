<?php

namespace Modules\Channel\Services;

use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Sales\Repositories\SalesOrderRepository;
use Modules\Sales\Services\SalesOrderService;

class TikTokOrderPlatformBackfillService
{
    public function __construct(
        private readonly TikTokClient $client,
        private readonly ChannelShopRepository $shopRepository,
        private readonly SalesOrderRepository $orderRepository,
        private readonly SalesOrderService $salesOrderService,
    ) {}

    public function run(
        ?string $orderReference,
        ?string $shopId,
        int $limit,
        bool $apply,
    ): array {
        $orders = $this->orderRepository->getOrdersForTikTokPlatformBackfill(
            $orderReference,
            $shopId,
            $limit,
        );

        $result = [
            'total' => $orders->count(),
            'updated' => 0,
            'unchanged' => 0,
            'not_found' => 0,
            'conflicts' => 0,
            'errors' => 0,
            'rows' => [],
        ];

        foreach ($orders->groupBy('channel_shop_id') as $channelShopId => $shopOrders) {
            $shop = $this->shopRepository->findByShopId((string) $channelShopId);

            if (! $shop || ! $shop->access_token || ! $shop->shop_cipher) {
                foreach ($shopOrders as $order) {
                    $result['errors']++;
                    $result['rows'][] = $this->row(
                        $order,
                        null,
                        'error',
                        'Toko TikTok atau kredensial API tidak tersedia.',
                    );
                }

                continue;
            }

            foreach ($shopOrders as $order) {
                $this->reconcileOrder($order, $shop, $apply, $result);
            }
        }

        return $result;
    }

    private function reconcileOrder(object $order, object $shop, bool $apply, array &$result): void
    {
        try {
            $response = $this->client->request(
                'GET',
                '/order/202309/orders',
                [
                    'shop_cipher' => $shop->shop_cipher,
                    'ids' => (string) $order->channel_order_no,
                ],
                [],
                $shop->access_token,
            );
        } catch (\Throwable $exception) {
            $result['errors']++;
            $result['rows'][] = $this->row($order, null, 'error', $exception->getMessage());

            return;
        }

        $remoteOrder = collect($response['data']['orders'] ?? [])->first(
            fn (array $item): bool => (string) ($item['id'] ?? '') === (string) $order->channel_order_no,
        );

        if (! $remoteOrder) {
            $result['not_found']++;
            $result['rows'][] = $this->row(
                $order,
                null,
                'not_found',
                'Pesanan tidak ditemukan pada toko TikTok yang tersimpan.',
            );

            return;
        }

        $commercePlatform = $this->resolveCommercePlatform($remoteOrder);
        $targetNumber = $this->salesOrderService->generateSalesOrderNo(
            'tiktok',
            (string) $order->channel_order_no,
            $commercePlatform,
        )['salesorder_no'];

        if ($this->orderRepository->salesOrderNoBelongsToAnotherOrder($targetNumber, (string) $order->id)) {
            $result['conflicts']++;
            $result['rows'][] = $this->row(
                $order,
                $targetNumber,
                'conflict',
                'Nomor pesanan tujuan sudah dipakai pesanan lain; tidak diubah.',
                $commercePlatform,
            );

            return;
        }

        $changed = $order->salesorder_no !== $targetNumber
            || $order->commerce_platform !== $commercePlatform;

        if (! $changed) {
            $result['unchanged']++;
            $result['rows'][] = $this->row($order, $targetNumber, 'unchanged', null, $commercePlatform);

            return;
        }

        if ($apply) {
            $this->orderRepository->updateCommercePlatformAndSalesOrderNo(
                $order,
                $commercePlatform,
                $targetNumber,
            );
            $result['updated']++;
            $action = 'updated';
        } else {
            $result['updated']++;
            $action = 'would_update';
        }

        $result['rows'][] = $this->row($order, $targetNumber, $action, null, $commercePlatform);
    }

    private function resolveCommercePlatform(array $remoteOrder): string
    {
        return strtoupper((string) ($remoteOrder['commerce_platform'] ?? '')) === 'TOKOPEDIA'
            ? 'TOKOPEDIA'
            : 'TIKTOK_SHOP';
    }

    private function row(
        object $order,
        ?string $targetNumber,
        string $action,
        ?string $message,
        ?string $commercePlatform = null,
    ): array {
        return [
            'order_id' => (string) $order->id,
            'current_no' => $order->salesorder_no,
            'channel_order_no' => $order->channel_order_no,
            'target_no' => $targetNumber,
            'current_platform' => $order->commerce_platform,
            'target_platform' => $commercePlatform,
            'action' => $action,
            'message' => $message,
        ];
    }
}
