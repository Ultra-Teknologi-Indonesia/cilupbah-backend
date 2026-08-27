<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Collection;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Sales\Repositories\SalesOrderRepository;
use Modules\Sales\Services\SalesOrderService;

class TikTokOrderPlatformBackfillService
{
    private const CHUNK_SIZE = 100;

    private const MAX_REPORTED_ROWS = 100;

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
            'total' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'not_found' => 0,
            'conflicts' => 0,
            'errors' => 0,
            'rows' => [],
        ];

        $shops = [];
        $orders->chunkById(self::CHUNK_SIZE, function (Collection $shopOrders) use (&$result, &$shops, $apply): void {
            foreach ($shopOrders as $order) {
                $result['total']++;
                $channelShopId = (string) $order->channel_shop_id;
                $shop = array_key_exists($channelShopId, $shops)
                    ? $shops[$channelShopId]
                    : ($shops[$channelShopId] = $this->shopRepository->findByShopId($channelShopId));

                if (! $shop || ! $shop->access_token || ! $shop->shop_cipher) {
                    $result['errors']++;
                    $this->appendRow($result, $this->row(
                        $order,
                        null,
                        'error',
                        'Toko TikTok atau kredensial API tidak tersedia.',
                    ));

                    continue;
                }

                $this->reconcileOrder($order, $shop, $apply, $result);
            }
        }, 'id');

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
            $this->appendRow($result, $this->row($order, null, 'error', $exception->getMessage()));

            return;
        }

        $remoteOrder = collect($response['data']['orders'] ?? [])->first(
            fn (array $item): bool => (string) ($item['id'] ?? '') === (string) $order->channel_order_no,
        );

        if (! $remoteOrder) {
            $result['not_found']++;
            $this->appendRow($result, $this->row(
                $order,
                null,
                'not_found',
                'Pesanan tidak ditemukan pada toko TikTok yang tersimpan.',
            ));

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
            $this->appendRow($result, $this->row(
                $order,
                $targetNumber,
                'conflict',
                'Nomor pesanan tujuan sudah dipakai pesanan lain; tidak diubah.',
                $commercePlatform,
            ));

            return;
        }

        $changed = $order->salesorder_no !== $targetNumber
            || $order->commerce_platform !== $commercePlatform;

        if (! $changed) {
            $result['unchanged']++;
            $this->appendRow($result, $this->row($order, $targetNumber, 'unchanged', null, $commercePlatform));

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

        $this->appendRow($result, $this->row($order, $targetNumber, $action, null, $commercePlatform));
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

    private function appendRow(array &$result, array $row): void
    {
        if (count($result['rows']) < self::MAX_REPORTED_ROWS) {
            $result['rows'][] = $row;
        }
    }
}
