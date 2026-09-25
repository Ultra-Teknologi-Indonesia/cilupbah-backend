<?php

namespace Modules\Channel\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Exceptions\ChannelCancelException;
use Modules\Channel\Exceptions\ChannelOrderPullIncompleteException;
use Modules\Channel\Exceptions\TikTokApiException;
use Modules\Channel\Exceptions\TikTokOrderListUnavailableException;
use Modules\Channel\Repositories\ChannelOrderRepository;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Outbound\Support\ChannelInstantSignal;
use Modules\Sales\Exceptions\ChannelOrderBeforeIntakeCutoffException;
use Modules\Sales\Services\SalesOrderService as OrderService;

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

    public function listRecentOrderIds(string $shopId, int $maxPages = 3): array
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            return [];
        }

        $ids = [];
        $nextPageToken = '';

        for ($page = 0; $page < $maxPages; $page++) {
            $queries = ['shop_cipher' => $shop->shop_cipher ?? '', 'page_size' => 100];
            $body = ['sort_by' => 'CREATE_TIME', 'sort_type' => 'DESC'];
            if ($nextPageToken !== '') {
                $body['next_page_token'] = $nextPageToken;
            }

            $res = $this->client->request('POST', '/order/202309/orders/search', $queries, $body, $shop->access_token);
            foreach ($res['data']['orders'] ?? [] as $order) {
                $ids[] = (string) ($order['id'] ?? '');
            }

            $nextPageToken = $res['data']['next_page_token'] ?? '';
            if ($nextPageToken === '') {
                break;
            }
        }

        return array_values(array_filter($ids));
    }

    public const MAX_PULL_PAGES = 200;

    public function pullOrders(string $shopId, ?int $updatedAfter = null, ?int $updatedBefore = null): int
    {
        if (app(ChannelSyncSettingService::class)->isPaused()) {
            return 0;
        }

        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $accessToken = $shop->access_token;
        $shopCipher = $shop->shop_cipher ?? '';

        $timeFrom = $updatedAfter ?: now()->subDays(7)->timestamp;
        $timeTo = $updatedBefore ?: now()->timestamp;

        $count = 0;
        $failedOrderIds = [];
        $nextPageToken = '';
        $page = 0;

        do {
            $queries = [
                'shop_cipher' => $shopCipher,
                'page_size' => 100,
            ];

            $body = [
                'sort_field' => 'update_time',
                'sort_order' => 'ASC',
                'update_time_ge' => $timeFrom,
                'update_time_lt' => $timeTo,
            ];

            if ($nextPageToken !== '') {
                $body['next_page_token'] = $nextPageToken;
            }

            $res = retry(
                2,
                function () use ($queries, $body, $accessToken): array {
                    $response = $this->client->request(
                        'POST',
                        '/order/202309/orders/search',
                        $queries,
                        $body,
                        $accessToken,
                    );

                    if (! is_array($response)
                        || ! isset($response['data']['orders'])
                        || ! is_array($response['data']['orders'])) {
                        throw new TikTokOrderListUnavailableException(
                            'TikTok tidak mengembalikan daftar order yang valid.',
                        );
                    }

                    return $response;
                },
                500,
                static fn (\Throwable $e): bool => $e instanceof TikTokOrderListUnavailableException
                    || $e instanceof ConnectionException
                    || ($e instanceof TikTokApiException && $e->isRetryable()),
            );

            foreach ($res['data']['orders'] as $item) {
                try {
                    $this->dumpInstantPayloadForResearch($item, $shopId);
                    $internalData = $this->mapper->map($item, $shopId);
                    $internalData = $this->enrichTrackingFromPackages($internalData, $item, $shopCipher, $accessToken);
                    $localOrderId = $this->orderService->upsertFromChannel($internalData);
                    if (! $localOrderId) {
                        throw new \RuntimeException(sprintf(
                            'TikTok order %s tidak menghasilkan ID lokal setelah upsert.',
                            (string) ($item['id'] ?? 'unknown'),
                        ));
                    }
                    $count++;
                } catch (ChannelOrderBeforeIntakeCutoffException) {
                    continue;
                } catch (\Exception $e) {
                    Log::error("Failed to pull order {$item['id']}: ".$e->getMessage());
                    $failedOrderIds[] = (string) ($item['id'] ?? 'unknown');
                }
            }

            $nextPageToken = $res['data']['next_page_token'] ?? '';
            $page++;

            if ($page >= self::MAX_PULL_PAGES && $nextPageToken !== '') {
                throw ChannelOrderPullIncompleteException::pageLimitReached('tiktok', $shopId, $page);
            }
        } while ($nextPageToken !== '');

        if ($failedOrderIds !== []) {
            throw ChannelOrderPullIncompleteException::forOrders('tiktok', $shopId, $failedOrderIds);
        }

        return $count;
    }

    public function pullOrdersPage(string $shopId, ?int $updatedAfter, ?int $updatedBefore, array $cursor = []): OrderPullPageResult
    {
        if (app(ChannelSyncSettingService::class)->isPaused()) {
            return new OrderPullPageResult(0, true);
        }

        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            throw new \RuntimeException("No access token found for shop: {$shopId}");
        }

        $from = $updatedAfter ?: now()->subDays(7)->timestamp;
        $to = $updatedBefore ?: now()->timestamp;
        $queries = ['shop_cipher' => $shop->shop_cipher ?? '', 'page_size' => 100];
        $body = [
            'sort_field' => 'update_time',
            'sort_order' => 'ASC',
            'update_time_ge' => $from,
            'update_time_lt' => $to,
        ];
        $nextPageToken = (string) ($cursor['next_page_token'] ?? '');
        if ($nextPageToken !== '') {
            $body['next_page_token'] = $nextPageToken;
        }

        $res = retry(
            2,
            function () use ($queries, $body, $shop): array {
                $response = $this->client->request('POST', '/order/202309/orders/search', $queries, $body, $shop->access_token);
                if (! is_array($response) || ! isset($response['data']['orders']) || ! is_array($response['data']['orders'])) {
                    throw new TikTokOrderListUnavailableException('TikTok tidak mengembalikan daftar order yang valid.');
                }

                return $response;
            },
            500,
            static fn (\Throwable $e): bool => $e instanceof TikTokOrderListUnavailableException
                || $e instanceof ConnectionException
                || ($e instanceof TikTokApiException && $e->isRetryable()),
        );

        $count = 0;
        $failed = [];
        foreach ($res['data']['orders'] as $item) {
            $orderId = (string) ($item['id'] ?? 'unknown');
            try {
                $internal = $this->mapper->map($item, $shopId);
                $internal = $this->enrichTrackingFromPackages($internal, $item, $shop->shop_cipher ?? '', $shop->access_token);
                if (! $this->orderService->upsertFromChannel($internal)) {
                    throw new \RuntimeException("TikTok order {$orderId} tidak menghasilkan ID lokal setelah upsert.");
                }
                $count++;
            } catch (ChannelOrderBeforeIntakeCutoffException) {
                continue;
            } catch (\Throwable $e) {
                Log::error("Failed to pull order {$orderId}: ".$e->getMessage());
                $failed[] = $orderId;
            }
        }

        if ($failed !== []) {
            throw ChannelOrderPullIncompleteException::forOrders('tiktok', $shopId, $failed);
        }

        $next = (string) ($res['data']['next_page_token'] ?? '');

        return $next === ''
            ? new OrderPullPageResult($count, true)
            : new OrderPullPageResult($count, false, ['next_page_token' => $next]);
    }

    public function pullOrderById(string $shopId, string $orderId): ?int
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $queries = [
            'shop_cipher' => $shop->shop_cipher ?? '',
            'ids' => $orderId,
        ];

        $res = $this->client->request('GET', '/order/202309/orders', $queries, [], $shop->access_token);

        if (! isset($res['data']['orders']) || empty($res['data']['orders'])) {
            Log::warning('TikTok: order detail kosong setelah webhook/reconcile pull.', [
                'shop_id' => $shopId,
                'order_id' => $orderId,
            ]);

            return 0;
        }

        $accessToken = $shop->access_token;
        $shopCipher = $shop->shop_cipher ?? '';

        $count = 0;
        foreach ($res['data']['orders'] as $item) {
            try {
                $this->dumpInstantPayloadForResearch($item, $shopId);
                $internalData = $this->mapper->map($item, $shopId);
                $internalData = $this->enrichTrackingFromPackages($internalData, $item, $shopCipher, $accessToken);
                $localOrderId = $this->orderService->upsertFromChannel($internalData);
                if (! $localOrderId) {
                    throw new \RuntimeException("TikTok order {$item['id']} tidak menghasilkan ID lokal setelah upsert.");
                }

                if ($localOrderId) {
                    try {
                        $statement = $this->getOrderStatement($shopId, (string) $item['id']);
                        if (! empty($statement)) {
                            $finance = app(TikTokStatementMapper::class)->map($statement);
                            $this->orderService->updateOrderFinance($localOrderId, $finance);
                        }
                    } catch (\Throwable $e) {

                    }
                }
                $count++;
            } catch (ChannelOrderBeforeIntakeCutoffException) {
                return 0;
            } catch (\Throwable $e) {
                Log::error("Failed to pull specific order {$item['id']}: ".$e->getMessage());
                throw $e;
            }
        }

        return $count;
    }

    protected function dumpInstantPayloadForResearch(array $item, string $shopId): void
    {
        if (! config('services.tiktok.dump_instant_payload')) {
            return;
        }

        $fulfillmentType = (string) ($item['fulfillment_type'] ?? '');

        $isInstant = ChannelInstantSignal::fromTypes(
            is_string($item['shipping_type'] ?? null) ? $item['shipping_type'] : null,
            $fulfillmentType,
            is_string($item['delivery_option_name'] ?? null) ? $item['delivery_option_name'] : null,
        ) === true;

        if (! $isInstant) {
            return;
        }

        Log::debug('TikTok instant/sameday order diproses', [
            'shop_id' => $shopId,
            'order_id' => $item['id'] ?? null,
            'shipping_type' => $item['shipping_type'] ?? null,
            'fulfillment_type' => $item['fulfillment_type'] ?? null,
        ]);
    }

    protected function enrichTrackingFromPackages(array $internalData, array $tiktokOrder, string $shopCipher, string $accessToken): array
    {
        $needsTracking = empty($internalData['tracking_number']);
        $needsProvider = empty($internalData['shipping_provider'])
            || stripos($internalData['shipping_provider'], 'standard') !== false;

        if (! $needsTracking && ! $needsProvider) {
            return $internalData;
        }

        $packages = $tiktokOrder['packages'] ?? [];
        if (empty($packages)) {
            return $internalData;
        }

        $packageId = $packages[0]['id'] ?? null;
        if (! $packageId) {
            return $internalData;
        }

        try {
            $queries = ['shop_cipher' => $shopCipher];
            $res = $this->client->request('GET', "/fulfillment/202309/packages/{$packageId}", $queries, [], $accessToken);

            $data = $res['data'] ?? [];
            if ($needsTracking && ! empty($data['tracking_number'])) {
                $internalData['tracking_number'] = (string) $data['tracking_number'];
            }
            if ($needsProvider && ! empty($data['shipping_provider_name'])) {
                $internalData['shipping_provider'] = (string) $data['shipping_provider_name'];
            }
        } catch (\Exception $e) {
            Log::warning("Failed to enrich tracking from package {$packageId}: ".$e->getMessage());
        }

        return $internalData;
    }

    protected function financeStatementPath(string $orderId): string
    {
        $template = config(
            'services.tiktok.finance_statement_path',
            '/finance/202309/orders/{order_id}/statement_transactions'
        );

        return str_replace('{order_id}', $orderId, $template);
    }

    public function getOrderStatement(string $shopId, string $orderId): array
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];

        $res = $this->client->request(
            'GET',
            $this->financeStatementPath($orderId),
            $queries,
            [],
            $shop->access_token,
            [],
            max(1, (int) config('services.tiktok.finance_timeout_seconds', 15)),
        );

        return $res['data'] ?? [];
    }

    public function getStatements(string $shopId, int $stmtTimeGe, int $stmtTimeLt): array
    {
        $shop = $this->requireFinanceShop($shopId);

        $queries = [
            'sort_field' => 'statement_time',
            'shop_cipher' => $shop->shop_cipher ?? '',
            'page_size' => 100,
            'statement_time_ge' => $stmtTimeGe,
            'statement_time_lt' => $stmtTimeLt,
        ];

        $res = $this->client->request('GET', '/finance/202309/statements', $queries, [], $shop->access_token);

        return $res['data']['statements'] ?? ($res['data'] ?? []);
    }

    public function getTransactionsByStatement(string $shopId, string $statementId): array
    {
        $shop = $this->requireFinanceShop($shopId);

        $queries = [
            'sort_field' => 'order_create_time',
            'shop_cipher' => $shop->shop_cipher ?? '',
            'page_size' => 100,
        ];

        $res = $this->client->request('GET', "/finance/202501/statements/{$statementId}/statement_transactions", $queries, [], $shop->access_token);

        return $res['data']['statement_transactions'] ?? ($res['data']['transactions'] ?? ($res['data'] ?? []));
    }

    public function getPayments(string $shopId, int $ge, int $lt): array
    {
        $shop = $this->requireFinanceShop($shopId);

        $queries = [
            'sort_field' => 'create_time',
            'shop_cipher' => $shop->shop_cipher ?? '',
            'page_size' => 100,
            'create_time_ge' => $ge,
            'create_time_lt' => $lt,
        ];

        $res = $this->client->request('GET', '/finance/202605/payments', $queries, [], $shop->access_token);

        return $res['data']['payments'] ?? ($res['data'] ?? []);
    }

    protected function requireFinanceShop(string $shopId): object
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        return $shop;
    }

    public function acceptOrder(string $shopId, string $orderId): array
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
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

        $this->resyncLocalOrder($shopId, $orderId);

        return $res;
    }

    public function readyToShip(
        string $shopId,
        string $orderId,
        ?array $handover = null,
        array $knownPackageIds = [],
    ): array {
        return $this->shipPackages($shopId, $orderId, $handover, $knownPackageIds, true);
    }

    public function requestTrackingNumber(
        string $shopId,
        string $orderId,
        ?array $handover = null,
        array $knownPackageIds = [],
        ?array $verifiedSnapshot = null,
    ): array {
        return $this->shipPackages(
            $shopId,
            $orderId,
            $handover,
            $knownPackageIds,
            false,
            $verifiedSnapshot,
        );
    }

    public function requestTrackingNumbersMass(string $shopId, array $packageIds): array
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $packageIds = $this->normalizePackageIds($packageIds);
        if ($packageIds === []) {
            return [];
        }

        return $this->shipPackagesInBatch(
            $shop,
            'mass-awb',
            ['shop_cipher' => $shop->shop_cipher ?? ''],
            $packageIds,
            null,
        );
    }

    private function shipPackages(
        string $shopId,
        string $orderId,
        ?array $handover,
        array $knownPackageIds,
        bool $resyncLocal,
        ?array $verifiedSnapshot = null,
    ): array {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];
        $lock = Cache::lock($this->shipmentLockKey($shopId, $orderId), 300);
        if (! $lock->get()) {
            return $this->deferredShipmentResult(
                $orderId,
                'Permintaan pengiriman TikTok untuk order ini sedang diproses. Sistem akan memeriksa hasilnya tanpa mengirim ulang.',
            );
        }

        try {
            try {

                $snapshot = $verifiedSnapshot ?? $this->getOrderFulfillmentSnapshot($shop, $orderId);
            } catch (\Throwable $e) {
                Log::warning('TikTok RTS: preflight order gagal; POST /ship tidak dikirim', [
                    'shop_id' => $shopId,
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                ]);

                return $this->deferredShipmentResult(
                    $orderId,
                    'Status TikTok belum dapat diverifikasi. Sistem tidak mengirim ulang panggilan driver dan akan mencoba membaca status kembali.',
                );
            }

            return $this->shipPackagesFromSnapshot(
                $shop,
                $orderId,
                $queries,
                $snapshot,
                $handover,
                $knownPackageIds,
                $resyncLocal,
            );
        } finally {
            if ($lock->isOwnedByCurrentProcess()) {
                $lock->release();
            }
        }
    }

    private function shipPackagesFromSnapshot(
        object $shop,
        string $orderId,
        array $queries,
        array $snapshot,
        ?array $handover,
        array $knownPackageIds,
        bool $resyncLocal,
    ): array {
        if (empty($snapshot['order_found'])) {
            return $this->deferredShipmentResult(
                $orderId,
                'Order TikTok tidak ditemukan saat verifikasi. Sistem tidak mengirim POST /ship.',
            );
        }

        $orderStatus = strtoupper((string) ($snapshot['status'] ?? ''));
        $packagesById = collect($snapshot['packages'] ?? [])
            ->filter(static fn (array $package): bool => filled($package['id'] ?? null))
            ->keyBy(static fn (array $package): string => (string) $package['id']);

        $packageIds = $this->normalizePackageIds($knownPackageIds);
        if ($packageIds === []) {
            $packageIds = $packagesById->keys()->all();
        }

        if ($packageIds === []) {
            if (! $this->isOrderReadyToShip($orderStatus)) {
                return $this->notShippableResult($orderId, $orderStatus);
            }

            try {
                $packageIds = $this->resolvePackageIds($shop, $orderId, $queries);
            } catch (\Throwable $e) {
                Log::warning('TikTok RTS: package belum dapat diverifikasi; POST /ship tidak dikirim', [
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                ]);

                return $this->deferredShipmentResult(
                    $orderId,
                    'Package TikTok belum dapat diverifikasi. Sistem tidak mengirim POST /ship sebelum statusnya terbaca.',
                );
            }
        }

        if ($packageIds === []) {
            return $this->deferredShipmentResult(
                $orderId,
                'TikTok belum menyediakan package_id untuk order ini.',
            );
        }

        $alreadyAcceptedIds = [];
        $packageIdsToShip = [];
        foreach ($packageIds as $packageId) {
            $package = $packagesById->get($packageId);
            $packageStatus = strtoupper((string) ($package['status'] ?? ''));

            if (
                filled($package['tracking_number'] ?? null)
                || $this->isPackageAlreadyShipped($packageStatus)
            ) {
                $alreadyAcceptedIds[] = $packageId;

                continue;
            }

            $packageCanBeShipped = $package !== null
                ? ($this->isPackageReadyToShip($packageStatus)
                    || ($packageStatus === '' && $this->isOrderReadyToShip($orderStatus)))
                : $this->isOrderReadyToShip($orderStatus);

            if (! $packageCanBeShipped) {
                continue;
            }

            $packageIdsToShip[] = $packageId;
        }

        if ($packageIdsToShip === []) {
            if ($alreadyAcceptedIds !== [] || $this->isOrderAlreadyShipped($orderStatus)) {
                $this->persistSnapshotTracking($orderId, $snapshot);

                return [
                    'order_id' => $orderId,
                    'shipped' => true,
                    'accepted' => true,
                    'all_packages_shipped' => true,
                    'tracking_number' => $snapshot['tracking_number'] ?? null,
                    'shipping_provider' => $snapshot['shipping_provider'] ?? null,
                    'channel_status' => $snapshot['status'] ?? null,
                    'message' => 'TikTok sudah menerima pengiriman sebelumnya; POST /ship tidak dikirim ulang.',
                    'packages' => [],
                    'already_accepted_package_ids' => $alreadyAcceptedIds,
                ];
            }

            return $this->notShippableResult($orderId, $orderStatus);
        }

        $results = $this->shipPackagesInBatch(
            $shop,
            $orderId,
            $queries,
            $packageIdsToShip,
            $handover,
        );

        $somePosted = collect($results)->contains('shipped', true);
        $failedPackageIds = collect($results)
            ->where('shipped', false)
            ->pluck('package_id')
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();
        $allPackagesShipped = $failedPackageIds === [];
        $tracking = $this->trackingFromShipResponses($results)
            ?? (filled($snapshot['tracking_number'] ?? null) ? [
                'tracking_number' => (string) $snapshot['tracking_number'],
                'shipping_provider' => $snapshot['shipping_provider'] ?? null,
            ] : null);

        if ($somePosted) {
            if ($resyncLocal) {
                $this->resyncLocalOrder((string) ($shop->shop_id ?? ''), $orderId);
            }

            if ($tracking === null) {
                try {
                    $tracking = $this->resolveTrackingNumberFromOrder($shop, $orderId);
                } catch (\Throwable $e) {
                    Log::info('TikTok RTS: request diterima, tracking belum dapat dibaca', [
                        'order_id' => $orderId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($tracking !== null && filled($tracking['tracking_number'] ?? null)) {
            $this->orderRepository->updateTrackingByOrderNo(
                $orderId,
                (string) $tracking['tracking_number'],
                $tracking['shipping_provider'] ?? null,
            );
        }

        return [
            'order_id' => $orderId,
            'shipped' => $allPackagesShipped,
            'accepted' => $somePosted || $alreadyAcceptedIds !== [],
            'all_packages_shipped' => $allPackagesShipped,
            'tracking_number' => $tracking['tracking_number'] ?? null,
            'shipping_provider' => $tracking['shipping_provider'] ?? null,
            'channel_status' => $somePosted ? 'PROCESSED' : ($snapshot['status'] ?? null),
            'message' => $allPackagesShipped
                ? 'RTS TikTok berhasil. Tracking number mungkin diterbitkan secara asynchronous.'
                : ($somePosted
                    ? 'Sebagian package TikTok berhasil dikirim; hanya package yang belum diterima akan dicoba ulang.'
                    : 'TikTok belum menerima request pengiriman package.'),
            'packages' => $results,
            'already_accepted_package_ids' => $alreadyAcceptedIds,
            'failed_package_ids' => $failedPackageIds,
        ];
    }

    private function shipPackagesInBatch(
        object $shop,
        string $orderId,
        array $queries,
        array $packageIds,
        ?array $handover,
    ): array {
        try {
            $response = $this->client->request(
                'POST',
                '/fulfillment/202309/packages/ship',
                $queries,
                [
                    'packages' => array_map(
                        fn (string $packageId): array => $this->batchShipPackagePayload($packageId, $handover),
                        $packageIds,
                    ),
                ],
                $shop->access_token,
            );

            $errorsByPackageId = collect(data_get($response, 'data.errors', []))
                ->filter(static fn ($error): bool => is_array($error) && filled(data_get($error, 'detail.package_id')))
                ->keyBy(static fn (array $error): string => (string) data_get($error, 'detail.package_id'));

            return array_map(function (string $packageId) use ($errorsByPackageId, $response): array {
                $error = $errorsByPackageId->get($packageId);

                if (is_array($error)) {
                    return [
                        'package_id' => $packageId,
                        'shipped' => false,
                        'message' => (string) ($error['message'] ?? 'TikTok menolak package pada batch shipment.'),
                        'error_code' => $error['code'] ?? null,
                        'request_id' => $response['request_id'] ?? null,
                    ];
                }

                return [
                    'package_id' => $packageId,
                    'shipped' => true,
                    'response' => $response['data'] ?? [],
                    'request_id' => $response['request_id'] ?? null,
                ];
            }, $packageIds);
        } catch (\Throwable $e) {
            Log::error('TikTok RTS: batch ship packages gagal', [
                'shop_id' => $shop->shop_id ?? null,
                'order_id' => $orderId,
                'package_ids' => $packageIds,
                'error' => $e->getMessage(),
            ]);

            return array_map(static function (string $packageId) use ($e): array {
                return [
                    'package_id' => $packageId,
                    'shipped' => false,
                    'message' => $e->getMessage(),
                    'error_code' => $e instanceof TikTokApiException ? $e->errorCode : null,
                    'error_category' => $e instanceof TikTokApiException ? $e->category : null,
                    'raw_message' => $e instanceof TikTokApiException ? $e->rawMessage : null,
                    'request_id' => $e instanceof TikTokApiException ? $e->requestId : null,
                ];
            }, $packageIds);
        }
    }

    private function batchShipPackagePayload(string $packageId, ?array $handover): array
    {
        $payload = ['id' => $packageId];
        if ($handover === null) {
            return $payload;
        }

        $method = strtoupper((string) (
            $handover['handover_method']
            ?? $handover['method']
            ?? $handover['preferred_method']
            ?? ''
        ));
        $method = match ($method) {
            'DROPOFF', 'DROP_OFF' => 'DROP_OFF',
            'PICKUP' => 'PICKUP',
            default => null,
        };

        if ($method !== null) {
            $payload['handover_method'] = $method;
        }

        $trackingNumber = trim((string) ($handover['tracking_number'] ?? ''));
        $shippingProviderId = trim((string) ($handover['shipping_provider_id'] ?? ''));
        if (($trackingNumber === '') !== ($shippingProviderId === '')) {
            throw new \InvalidArgumentException(
                'TikTok seller shipping membutuhkan tracking_number dan shipping_provider_id secara bersamaan.',
            );
        }

        if ($trackingNumber !== '') {
            $payload['self_shipment'] = [
                'tracking_number' => $trackingNumber,
                'shipping_provider_id' => $shippingProviderId,
            ];
        }

        $pickupSlot = $handover['pickup_slot'] ?? null;
        if (is_array($pickupSlot)
            && isset($pickupSlot['start_time'], $pickupSlot['end_time'])) {
            $payload['pickup_slot'] = [
                'start_time' => (int) $pickupSlot['start_time'],
                'end_time' => (int) $pickupSlot['end_time'],
            ];
        }

        return $payload;
    }

    private function normalizePackageIds(array $packageIds): array
    {
        return array_values(array_unique(array_filter(
            array_map('strval', $packageIds),
            static fn (string $id): bool => $id !== '',
        )));
    }

    private function shipmentLockKey(string $shopId, string $orderId): string
    {
        return 'tiktok:ship:'.hash('sha256', $shopId.'|'.$orderId);
    }

    private function deferredShipmentResult(string $orderId, string $message): array
    {
        return [
            'order_id' => $orderId,
            'shipped' => false,
            'accepted' => false,
            'all_packages_shipped' => false,
            'deferred' => true,
            'tracking_number' => null,
            'channel_status' => null,
            'message' => $message,
            'packages' => [],
        ];
    }

    private function notShippableResult(string $orderId, string $status): array
    {
        return [
            'order_id' => $orderId,
            'shipped' => false,
            'accepted' => false,
            'all_packages_shipped' => false,
            'tracking_number' => null,
            'channel_status' => $status !== '' ? $status : null,
            'message' => $status === ''
                ? 'Status order TikTok kosong; POST /ship tidak dikirim.'
                : "Status TikTok {$status} belum dapat dikirim; POST /ship tidak dikirim.",
            'packages' => [],
        ];
    }

    private function persistSnapshotTracking(string $orderId, array $snapshot): void
    {
        if (! filled($snapshot['tracking_number'] ?? null)) {
            return;
        }

        $this->orderRepository->updateTrackingByOrderNo(
            $orderId,
            (string) $snapshot['tracking_number'],
            $snapshot['shipping_provider'] ?? null,
        );
    }

    private function isOrderReadyToShip(string $status): bool
    {
        return in_array($status, ['AWAITING_SHIPMENT', 'READY_TO_SHIP'], true);
    }

    private function isOrderAlreadyShipped(string $status): bool
    {
        return in_array($status, [
            'PROCESSED',
            'AWAITING_COLLECTION',
            'SHIPPED',
            'IN_TRANSIT',
            'TO_CONFIRM_RECEIVE',
            'COMPLETED',
        ], true);
    }

    private function isPackageReadyToShip(string $status): bool
    {
        return in_array($status, ['AWAITING_SHIPMENT', 'READY_TO_SHIP'], true);
    }

    private function isPackageAlreadyShipped(string $status): bool
    {
        return $this->isOrderAlreadyShipped($status);
    }

    private function trackingFromShipResponses(array $packages): ?array
    {
        foreach ($packages as $package) {
            $data = $package['response'] ?? [];
            $trackingNumber = data_get($data, 'tracking_number')
                ?? data_get($data, 'packages.0.tracking_number');

            if ($trackingNumber !== null && $trackingNumber !== '') {
                return [
                    'tracking_number' => (string) $trackingNumber,
                    'shipping_provider' => data_get($data, 'shipping_provider_name')
                        ?? data_get($data, 'shipping_provider'),
                ];
            }
        }

        return null;
    }

    public function getOrderFulfillmentSnapshot(object $shop, string $orderId): array
    {
        $res = $this->client->request(
            'GET',
            '/order/202309/orders',
            ['shop_cipher' => $shop->shop_cipher ?? '', 'ids' => $orderId],
            [],
            $shop->access_token,
        );

        return $this->fulfillmentSnapshotFromOrder($shop, $orderId, $res['data']['orders'][0] ?? null);
    }

    /**
     * Batch preflight only: missing/unreadable orders must be verified separately.
     * Do not fan out into document/package API calls while preparing a mass shipment.
     *
     * @param  array<string>  $orderIds
     * @return array<string, array>
     */
    public function getOrderFulfillmentSnapshots(object $shop, array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map('strval', $orderIds),
            static fn (string $id): bool => trim($id) !== '',
        )));
        $snapshots = [];

        foreach (array_chunk($orderIds, 50) as $chunk) {
            try {
                $res = $this->client->request(
                    'GET',
                    '/order/202309/orders',
                    ['shop_cipher' => $shop->shop_cipher ?? '', 'ids' => implode(',', $chunk)],
                    [],
                    $shop->access_token,
                );

                foreach ($res['data']['orders'] ?? [] as $order) {
                    // TikTok's current contract uses `id`; accept the legacy
                    // `order_id` alias as well so a versioned response cannot
                    // silently turn a real order into a missing preflight.
                    $orderId = (string) ($order['id'] ?? $order['order_id'] ?? '');
                    if (! in_array($orderId, $chunk, true)) {
                        continue;
                    }

                    $snapshots[$orderId] = $this->fulfillmentSnapshotFromOrder($shop, $orderId, $order, false);
                }
            } catch (\Throwable $exception) {
                Log::warning('TikTok: batch preflight gagal; order perlu verifikasi baca-saja.', [
                    'shop_id' => $shop->shop_id ?? null,
                    'order_count' => count($chunk),
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return $snapshots;
    }

    private function fulfillmentSnapshotFromOrder(
        object $shop,
        string $orderId,
        ?array $order,
        bool $hydrateTracking = true,
    ): array {
        if (! is_array($order)) {
            return [
                'order_found' => false,
                'status' => null,
                'packages' => [],
                'tracking_number' => null,
                'shipping_provider' => null,
            ];
        }

        $packages = array_values(array_map(static function (array $package): array {
            return [
                'id' => isset($package['id']) ? (string) $package['id'] : null,
                'tracking_number' => filled($package['tracking_number'] ?? null)
                    ? (string) $package['tracking_number']
                    : null,
                'shipping_provider' => $package['shipping_provider_name']
                    ?? $package['shipping_provider']
                    ?? null,
                'status' => $package['status'] ?? null,
            ];
        }, $order['packages'] ?? []));

        $orderStatus = isset($order['status']) ? (string) $order['status'] : null;
        if ($hydrateTracking && $this->isOrderAlreadyShipped(strtoupper((string) $orderStatus))) {
            $packages = $this->hydrateTrackingFromShippingDocuments($shop, $packages, $orderId);
            $packages = $this->hydrateTrackingFromPackageDetails($shop, $packages, $orderId);
        }

        $packageWithTracking = collect($packages)
            ->first(static fn (array $package): bool => filled($package['tracking_number']));
        $hasPendingPackage = collect($packages)->contains(function (array $package): bool {
            $status = strtoupper((string) ($package['status'] ?? ''));

            return $this->isPackageReadyToShip($status);
        });

        return [
            'order_found' => true,
            'status' => $orderStatus,
            'packages' => $packages,
            'tracking_number' => $packageWithTracking['tracking_number'] ?? null,
            'shipping_provider' => $packageWithTracking['shipping_provider'] ?? null,
            'has_pending_package' => $hasPendingPackage,
            'all_packages_shipped' => $packages !== [] && ! $hasPendingPackage,
        ];
    }

    private function hydrateTrackingFromShippingDocuments(object $shop, array $packages, string $orderId): array
    {
        foreach ($packages as $index => $package) {
            if (! empty($package['tracking_number']) || empty($package['id'])) {
                continue;
            }

            try {
                $document = $this->getShippingDocument(
                    (string) ($shop->shop_id ?? ''),
                    (string) $package['id'],
                    'SHIPPING_LABEL',
                    'A6',
                );
                $trackingNumber = data_get($document, 'data.tracking_number');

                if (filled($trackingNumber)) {
                    $packages[$index]['tracking_number'] = (string) $trackingNumber;
                    $packages[$index]['shipping_provider'] ??=
                        data_get($document, 'data.shipping_provider_name');
                }
            } catch (\Throwable $e) {
                Log::debug('TikTok: shipping document belum menyediakan AWB', [
                    'order_id' => $orderId,
                    'package_id' => (string) $package['id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $packages;
    }

    private function hydrateTrackingFromPackageDetails(object $shop, array $packages, string $orderId): array
    {
        foreach ($packages as $index => $package) {
            if (! empty($package['tracking_number']) || empty($package['id'])) {
                continue;
            }

            try {
                $detail = $this->getPackageDetail(
                    (string) ($shop->shop_id ?? ''),
                    (string) $package['id'],
                );
                $trackingNumber = data_get($detail, 'data.tracking_number')
                    ?? data_get($detail, 'data.last_mile_tracking_number');

                if (filled($trackingNumber)) {
                    $packages[$index]['tracking_number'] = (string) $trackingNumber;
                    $packages[$index]['shipping_provider'] ??=
                        data_get($detail, 'data.shipping_provider_name')
                        ?? data_get($detail, 'data.shipping_provider');
                }
            } catch (\Throwable $e) {
                Log::debug('TikTok: package detail belum menyediakan AWB', [
                    'order_id' => $orderId,
                    'package_id' => (string) $package['id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $packages;
    }

    public function resolveTrackingNumberFromOrder(object $shop, string $orderId): ?array
    {
        $snapshot = $this->getOrderFulfillmentSnapshot($shop, $orderId);

        if (! filled($snapshot['tracking_number'] ?? null)) {
            return null;
        }

        return [
            'tracking_number' => (string) $snapshot['tracking_number'],
            'shipping_provider' => $snapshot['shipping_provider'] ?? null,
            'channel_status' => $snapshot['status'] ?? null,
        ];
    }

    private function resolveTrackingNumberOnce(object $shop, string $orderId): ?array
    {
        $res = $this->client->request(
            'GET',
            '/order/202309/orders',
            ['shop_cipher' => $shop->shop_cipher ?? '', 'ids' => $orderId],
            [],
            $shop->access_token,
        );

        $order = $res['data']['orders'][0] ?? null;
        if (! is_array($order)) {
            return null;
        }

        $packages = $order['packages'] ?? [];
        $channelStatus = isset($order['status'])
            ? (string) $order['status']
            : null;

        $package = collect($packages)
            ->first(static fn (array $row): bool => ! empty($row['tracking_number']));

        if (is_array($package)) {
            return [
                'tracking_number' => (string) $package['tracking_number'],
                'shipping_provider' => $package['shipping_provider_name']
                    ?? $package['shipping_provider']
                    ?? null,
                'channel_status' => $channelStatus,
            ];
        }

        foreach ($packages as $package) {
            $packageId = $package['id'] ?? null;
            if (! $packageId) {
                continue;
            }

            try {
                $document = $this->getShippingDocument(
                    (string) ($shop->shop_id ?? ''),
                    (string) $packageId,
                    'SHIPPING_LABEL',
                    'A6',
                );
                $trackingNumber = data_get($document, 'data.tracking_number');

                if ($trackingNumber !== null && $trackingNumber !== '') {
                    Log::info('TikTok: tracking resolved from shipping document', [
                        'order_id' => $orderId,
                        'package_id' => (string) $packageId,
                    ]);

                    return [
                        'tracking_number' => (string) $trackingNumber,
                        'shipping_provider' => $package['shipping_provider_name']
                            ?? $package['shipping_provider']
                            ?? data_get($document, 'data.shipping_provider_name'),
                        'channel_status' => $channelStatus,
                    ];
                }
            } catch (\Throwable $e) {
                Log::debug('TikTok: shipping document belum menyediakan AWB', [
                    'order_id' => $orderId,
                    'package_id' => (string) $packageId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    public function resolveTrackingNumberDirect(object $shop, string $orderId): ?array
    {
        return $this->resolveTrackingNumberOnce($shop, $orderId);
    }

    public function packageIdsForOrder(string $shopId, string $orderId): array
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            return [];
        }

        return $this->fetchPackageIds($shop, $orderId, ['shop_cipher' => $shop->shop_cipher ?? '']);
    }

    public function getShippingDocument(string $shopId, string $packageId, string $documentType = 'SHIPPING_LABEL', string $documentSize = 'A6'): array
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $queries = [
            'shop_cipher' => $shop->shop_cipher ?? '',
            'document_type' => $documentType,
            'document_size' => $documentSize,
        ];

        return $this->client->request('GET', "/fulfillment/202309/packages/{$packageId}/shipping_documents", $queries, [], $shop->access_token);
    }

    public function getShippingLabel(string $shopId, string $packageId, string $documentType = 'SHIPPING_LABEL', string $documentSize = 'A6'): array
    {
        return $this->getShippingDocument($shopId, $packageId, $documentType, $documentSize);
    }

    public function getPackingList(string $shopId, string $packageId, string $documentSize = 'A6'): array
    {
        return $this->getShippingDocument($shopId, $packageId, 'PACKING_LIST', $documentSize);
    }

    public function getPackageDetail(string $shopId, string $packageId): array
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];

        return $this->client->request('GET', "/fulfillment/202309/packages/{$packageId}", $queries, [], $shop->access_token);
    }

    public function acceptBuyerCancellation(
        string $shopId,
        string $orderId,
        bool $resync = true,
    ): array {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $cancelId = $this->resolveCancellationId($shopId, $orderId);
        if (! $cancelId) {
            throw new \RuntimeException("Tidak ditemukan permintaan pembatalan aktif untuk order {$orderId} di TikTok.", 404);
        }

        $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];

        $res = $this->client->request(
            'POST',
            "/return_refund/202309/cancellations/{$cancelId}/approve",
            $queries,
            [],
            $shop->access_token,
        );

        if ($resync) {
            $this->resyncLocalOrder($shopId, $orderId);
        }

        return $res;
    }

    public function searchBuyerCancellation(string $shopId, string $orderId): array
    {
        $empty = ['cancel_id' => null, 'reason_key' => null, 'reason_text' => null, 'status' => null, 'raw' => []];

        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            return $empty;
        }

        $queries = ['shop_cipher' => $shop->shop_cipher ?? '', 'page_size' => 50];

        $body = ['main_order_id_list' => [$orderId]];

        $res = $this->client->request(
            'POST',
            '/return_refund/202309/cancellations/search',
            $queries,
            $body,
            $shop->access_token,
        );

        $list = $res['data']['cancellations']
            ?? $res['data']['cancellation_orders']
            ?? [];

        $c = null;
        foreach ($list as $row) {
            if ((string) ($row['order_id'] ?? '') === (string) $orderId) {
                $c = $row;
                break;
            }
        }
        if (! $c) {
            return $empty;
        }

        return [
            'cancel_id' => isset($c['cancel_id']) ? (string) $c['cancel_id'] : (isset($c['id']) ? (string) $c['id'] : null),
            'reason_key' => $c['cancel_reason_key'] ?? $c['cancel_reason'] ?? null,
            'reason_text' => $c['cancel_reason_text'] ?? $c['reason_text'] ?? null,
            'status' => $c['cancel_status'] ?? $c['status'] ?? null,
            'raw' => $c,
        ];
    }

    private function resolveCancellationId(string $shopId, string $orderId): ?string
    {
        return $this->searchBuyerCancellation($shopId, $orderId)['cancel_id'] ?? null;
    }

    public function firstRejectCancelReason(string $shopId, string $cancelId): ?string
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            return null;
        }

        $queries = [
            'shop_cipher' => $shop->shop_cipher ?? '',
            'return_or_cancel_id' => $cancelId,
            'check_decisions' => 'REJECT_REQUEST_CANCEL',
            'locale' => 'id-ID',
        ];

        $res = $this->client->request(
            'GET',
            '/return_refund/202601/decision_eligibility',
            $queries,
            [],
            $shop->access_token,
        );

        foreach ($res['data']['decisions'] ?? [] as $d) {
            if (($d['decision'] ?? null) !== 'REJECT_REQUEST_CANCEL') {
                continue;
            }
            foreach ($d['available_reject_reasons'] ?? [] as $r) {
                if (! empty($r['name'])) {
                    return (string) $r['name'];
                }
            }
        }

        return null;
    }

    public function fetchReturnTracking(string $shopId, ?string $returnId, ?string $orderId = null): array
    {
        $empty = [
            'tracking_number' => null,
            'carrier' => null,
            'shipped_at' => null,
            '_request_succeeded' => false,
        ];

        try {
            $shop = $this->shopRepository->findByShopId($shopId);
            if (! $shop || ! $shop->access_token) {
                return $empty;
            }

            $queries = ['shop_cipher' => $shop->shop_cipher ?? '', 'page_size' => 20];
            $body = [];
            if ($returnId) {
                $body['return_ids'] = [$returnId];
            } elseif ($orderId) {
                $body['order_ids'] = [$orderId];
            } else {
                return $empty;
            }

            $res = $this->client->request(
                'POST',
                '/return_refund/202309/returns/search',
                $queries,
                $body,
                $shop->access_token,
            );

            $returns = $res['data']['return_orders']
                ?? $res['data']['returns']
                ?? [];
            $ret = $returns[0] ?? [];
            $shipment = $ret['return_shipment_document'] ?? $ret['shipment'] ?? [];

            $tracking = $ret['return_tracking_number']
                ?? $shipment['tracking_number']
                ?? null;
            $carrier = $ret['return_provider_name']
                ?? $ret['shipping_provider_name']
                ?? $shipment['shipping_provider_name']
                ?? null;
            $shippedAt = isset($ret['update_time']) && $ret['update_time']
                ? now()->setTimestamp((int) $ret['update_time'])->toIso8601String()
                : null;

            return [
                'tracking_number' => $tracking ? (string) $tracking : null,
                'carrier' => $carrier ? (string) $carrier : null,
                'shipped_at' => $shippedAt,
                '_request_succeeded' => true,
            ];
        } catch (\Throwable $e) {
            Log::warning("TikTok: gagal ambil resi retur (return_id={$returnId}): ".$e->getMessage());

            return $empty + ['_failure_reason' => $e->getMessage()];
        }
    }

    public function fetchReturnDetail(string $shopId, ?string $returnId, ?string $orderId = null): array
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
            $shop = $this->shopRepository->findByShopId($shopId);
            if (! $shop || ! $shop->access_token) {
                return $empty;
            }

            $queries = ['shop_cipher' => $shop->shop_cipher ?? '', 'page_size' => 20];
            $body = [];
            if ($returnId) {
                $body['return_ids'] = [$returnId];
            } elseif ($orderId) {
                $body['order_ids'] = [$orderId];
            } else {
                return $empty;
            }

            $res = $this->client->request(
                'POST',
                '/return_refund/202309/returns/search',
                $queries,
                $body,
                $shop->access_token,
            );

            $returns = $res['data']['return_orders'] ?? $res['data']['returns'] ?? [];
            $ret = $returns[0] ?? [];
            $shipment = $ret['return_shipment_document'] ?? $ret['shipment'] ?? [];

            $tracking = $ret['return_tracking_number'] ?? $shipment['tracking_number'] ?? null;
            $carrier = $ret['return_provider_name']
                ?? $ret['shipping_provider_name']
                ?? $shipment['shipping_provider_name']
                ?? null;
            $shippedAt = isset($ret['update_time']) && $ret['update_time']
                ? now()->setTimestamp((int) $ret['update_time'])->toIso8601String()
                : null;

            $refundRaw = $ret['refund_amount'] ?? null;
            if (is_array($refundRaw)) {
                $refundAmount = isset($refundRaw['refund_total']) && $refundRaw['refund_total'] !== ''
                    ? (float) $refundRaw['refund_total'] : null;
                $refundCurrency = $refundRaw['currency'] ?? null;
            } else {
                $refundAmount = ($refundRaw !== null && $refundRaw !== '') ? (float) $refundRaw : null;
                $refundCurrency = $ret['currency'] ?? null;
            }

            $shipRaw = $ret['shipping_fee_amount'] ?? null;
            $shipBlock = [];
            if (is_array($shipRaw)) {
                $shipBlock = isset($shipRaw[0]) && is_array($shipRaw[0]) ? $shipRaw[0] : $shipRaw;
            }
            $sellerReturnShipping = isset($shipBlock['seller_paid_return_shipping_fee']) && $shipBlock['seller_paid_return_shipping_fee'] !== ''
                ? (float) $shipBlock['seller_paid_return_shipping_fee'] : null;

            return [
                'channel_status' => isset($ret['return_status']) ? (string) $ret['return_status'] : null,
                'reason_code' => $ret['return_reason'] ?? $ret['return_reason_key'] ?? null,
                'reason_text' => $ret['return_reason_text'] ?? $ret['return_reason'] ?? null,
                'refund_amount' => $refundAmount,
                'refund_currency' => $refundCurrency,
                'shipping_fee_original' => null,
                'shipping_fee_return' => $sellerReturnShipping,
                'tracking_number' => $tracking ? (string) $tracking : null,
                'carrier' => $carrier ? (string) $carrier : null,
                'shipped_at' => $shippedAt,
                'raw' => $ret,
            ];
        } catch (\Throwable $e) {
            Log::warning("TikTok: gagal ambil detail retur (return_id={$returnId}): ".$e->getMessage());

            return $empty;
        }
    }

    public function fetchReturnHistory(string $shopId, string $returnId): array
    {
        try {
            $shop = $this->shopRepository->findByShopId($shopId);
            if (! $shop || ! $shop->access_token) {
                return ['records' => []];
            }

            $queries = ['shop_cipher' => $shop->shop_cipher ?? '', 'return_id' => $returnId];

            $res = $this->client->request(
                'GET',
                '/return_refund/202309/returns/records',
                $queries,
                [],
                $shop->access_token,
            );

            $entries = $res['data']['records'] ?? [];

            $records = [];
            foreach ($entries as $entry) {
                $records[] = [
                    'type' => $entry['record_type'] ?? 'UNKNOWN',
                    'operator' => $entry['operator'] ?? 'PLATFORM',
                    'description' => $entry['description'] ?? null,
                    'timestamp' => isset($entry['create_time']) && $entry['create_time']
                        ? now()->setTimestamp((int) $entry['create_time'])->toIso8601String()
                        : null,
                ];
            }

            return ['records' => $records];
        } catch (\Throwable $e) {
            Log::warning("TikTok: gagal ambil riwayat banding retur (return_id={$returnId}): ".$e->getMessage());

            return ['records' => []];
        }
    }

    public function approveReturn(string $shopId, string $returnId): bool
    {
        try {
            $shop = $this->shopRepository->findByShopId($shopId);
            if (! $shop || ! $shop->access_token) {
                return false;
            }

            $this->client->request(
                'POST',
                '/return_refund/202309/returns/approve',
                ['shop_cipher' => $shop->shop_cipher ?? ''],
                ['return_id' => $returnId],
                $shop->access_token,
            );

            return true;
        } catch (\Throwable $e) {
            Log::warning("TikTok: gagal setujui retur (return_id={$returnId}): ".$e->getMessage());

            return false;
        }
    }

    public function rejectReturn(string $shopId, string $returnId, string $rejectReasonKey, ?string $comments = null): bool
    {
        try {
            $shop = $this->shopRepository->findByShopId($shopId);
            if (! $shop || ! $shop->access_token) {
                return false;
            }

            $body = ['return_id' => $returnId, 'reject_reason_key' => $rejectReasonKey];
            if ($comments) {
                $body['comments'] = $comments;
            }

            $this->client->request(
                'POST',
                '/return_refund/202309/returns/reject',
                ['shop_cipher' => $shop->shop_cipher ?? ''],
                $body,
                $shop->access_token,
            );

            return true;
        } catch (\Throwable $e) {
            Log::warning("TikTok: gagal tolak retur (return_id={$returnId}): ".$e->getMessage());

            return false;
        }
    }

    public function getRejectReasons(string $shopId, string $returnId): array
    {
        try {
            $shop = $this->shopRepository->findByShopId($shopId);
            if (! $shop || ! $shop->access_token) {
                return [];
            }

            $res = $this->client->request(
                'GET',
                '/return_refund/202309/reject_reasons',
                ['shop_cipher' => $shop->shop_cipher ?? '', 'return_id' => $returnId],
                [],
                $shop->access_token,
            );

            $reasons = $res['data']['reject_reasons'] ?? [];

            return array_map(fn ($r) => [
                'id' => (string) ($r['reject_reason_key'] ?? $r['id'] ?? ''),
                'text' => (string) ($r['reject_reason_text'] ?? $r['text'] ?? ''),
            ], $reasons);
        } catch (\Throwable $e) {
            Log::warning("TikTok: gagal ambil alasan tolak retur (return_id={$returnId}): ".$e->getMessage());

            return [];
        }
    }

    public function rejectBuyerCancellation(string $shopId, string $orderId, ?string $rejectReason = null): array
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $cancelId = $this->resolveCancellationId($shopId, $orderId);
        if (! $cancelId) {
            throw new \RuntimeException("Tidak ditemukan permintaan pembatalan aktif untuk order {$orderId} di TikTok.", 404);
        }

        $reason = $rejectReason ?: $this->firstRejectCancelReason($shopId, $cancelId);
        if (! $reason) {
            throw new \RuntimeException("Tidak ada alasan penolakan pembatalan yang tersedia untuk order {$orderId} di TikTok.", 422);
        }

        $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];
        $body = ['reject_reason' => $reason];

        $res = $this->client->request(
            'POST',
            "/return_refund/202309/cancellations/{$cancelId}/reject",
            $queries,
            $body,
            $shop->access_token,
        );

        $this->resyncLocalOrder($shopId, $orderId);

        return $res;
    }

    public function declineOrder(string $shopId, string $orderId, string $reason): array
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];
        $body = [
            'order_id' => $orderId,
            'cancel_reason_key' => $reason,
            'cancel_reason' => $reason,
        ];

        $res = $this->client->request('POST', '/return_refund/202309/cancellations', $queries, $body, $shop->access_token);

        $this->resyncLocalOrder($shopId, $orderId);

        return $res;
    }

    public function cancelProduct(string $orderId, string $reason): array
    {
        $order = $this->orderRepository->findOrderBySalesOrderNo($orderId);
        if (! $order) {
            throw new \Exception('Pesanan tidak ditemukan di sistem lokal');
        }

        $tiktokOrderId = $order->channel_order_no ?: $orderId;

        $rawStatus = strtoupper((string) ($order->channel_status_raw ?? ''));
        $normalized = strtoupper((string) ($order->channel_status ?? ''));
        $cancelable = $rawStatus !== ''
            ? in_array($rawStatus, ['UNPAID', 'ON_HOLD', 'AWAITING_SHIPMENT'], true)
            : in_array($normalized, ['UNPAID', 'READY_TO_SHIP'], true);

        if (! $cancelable) {
            $shown = $rawStatus !== '' ? $rawStatus : $normalized;
            throw ChannelCancelException::final(
                "TikTok menolak pembatalan {$orderId}: status {$shown} tidak dapat dibatalkan seller.",
            );
        }

        $shop = $this->shopRepository->findByShopId($order->channel_shop_id);
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("No access token found for shop: {$order->channel_shop_id}");
        }

        $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];
        $body = [
            'order_id' => $tiktokOrderId,
            'cancel_reason' => $reason,
        ];

        $res = $this->client->request('POST', '/return_refund/202309/cancellations', $queries, $body, $shop->access_token);

        $code = (int) ($res['code'] ?? 0);
        if ($code !== 0) {
            $message = $res['message'] ?? "code {$code}";

            throw new ChannelCancelException(
                "TikTok menolak pembatalan {$orderId}: {$message}",
                retryable: $code === 36009003,
                channelCode: (string) $code,
            );
        }

        $cancelStatus = $res['data']['cancel_status'] ?? null;
        if ($cancelStatus === 'CANCELLATION_REQUEST_CANCEL') {
            throw ChannelCancelException::final(
                "TikTok membatalkan permintaan pembatalan {$orderId} (CANCELLATION_REQUEST_CANCEL).",
            );
        }

        $this->resyncLocalOrder($order->channel_shop_id, $tiktokOrderId);

        return [
            'cancel_id' => $res['data']['cancel_id'] ?? null,
            'cancel_status' => $cancelStatus,
            'async' => $cancelStatus !== 'CANCELLATION_REQUEST_COMPLETE',
            'raw' => $res,
        ];
    }

    public function getCancelReasons(?string $status = null): array
    {
        return collect(app(MarketplaceCancelReasonService::class)->for(MarketplaceCancelReasonService::TIKTOK, $status))
            ->pluck('label', 'key')
            ->all();
    }

    public function getCancelReasonsLive(string $shopId): array
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];

        $res = $this->client->request('GET', '/return_refund/202309/reject_reasons', $queries, [], $shop->access_token);

        $reasons = $res['data']['reasons'] ?? [];

        return array_values(array_filter(array_map(static function ($r) {
            $key = $r['name'] ?? $r['key'] ?? null;
            if ($key === null) {
                return null;
            }

            return [
                'key' => (string) $key,
                'label' => (string) ($r['text'] ?? $r['label'] ?? $key),
            ];
        }, $reasons)));
    }

    protected function resyncLocalOrder(string $shopId, string $orderId): void
    {
        try {
            $this->pullOrderById($shopId, $orderId);
        } catch (\Throwable $e) {
            Log::warning("TikTok: resync order {$orderId} gagal pasca aksi: ".$e->getMessage());
        }
    }

    protected function resolvePackageIds(object $shop, string $orderId, array $queries): array
    {
        $ids = $this->fetchPackageIds($shop, $orderId, $queries);

        if (! empty($ids)) {
            return $ids;
        }

        try {
            $this->client->request('POST', '/fulfillment/202309/packages', $queries, ['order_id' => $orderId], $shop->access_token);
        } catch (\Throwable $e) {
            if (strpos($e->getMessage(), 'invalid params') === false) {
                Log::warning('TikTok RTS: gagal membuat package sebelum RTS', [
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->fetchPackageIds($shop, $orderId, $queries);
    }

    protected function fetchPackageIds(object $shop, string $orderId, array $queries): array
    {
        $detailQueries = array_merge($queries, ['ids' => $orderId]);

        $res = $this->client->request('GET', '/order/202309/orders', $detailQueries, [], $shop->access_token);

        $orders = $res['data']['orders'] ?? [];
        $ids = [];

        foreach ($orders as $order) {
            foreach ($order['packages'] ?? [] as $package) {
                $pid = $package['id'] ?? null;
                if ($pid !== null && $pid !== '') {
                    $ids[] = (string) $pid;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    public function fetchAndStoreTracking(object $shop, string $orderId, array $queries): void
    {
        try {
            $detailQueries = array_merge($queries, ['ids' => $orderId]);
            $res = $this->client->request('GET', '/order/202309/orders', $detailQueries, [], $shop->access_token);

            $orders = $res['data']['orders'] ?? [];
            if (empty($orders)) {
                return;
            }

            $order = $orders[0];
            $packages = $order['packages'] ?? [];

            $trackingNumber = null;
            $shippingProvider = null;

            foreach ($packages as $pkg) {
                $tn = $pkg['tracking_number'] ?? null;
                if ($tn !== null && $tn !== '') {
                    $trackingNumber = (string) $tn;
                    $shippingProvider = $pkg['shipping_provider_name'] ?? $pkg['shipping_provider'] ?? null;
                    break;
                }
            }

            if ($trackingNumber) {
                $this->orderRepository->updateTrackingByOrderNo($orderId, $trackingNumber, $shippingProvider);
            }
        } catch (\Throwable $e) {
            Log::warning('TikTok: gagal fetch tracking post-RTS', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function resolveTrackingNumber(object $shop, string $orderId): ?array
    {
        $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];
        $detailQueries = array_merge($queries, ['ids' => $orderId]);

        $res = $this->client->request('GET', '/order/202309/orders', $detailQueries, [], $shop->access_token);

        $orders = $res['data']['orders'] ?? [];
        if (empty($orders)) {
            return null;
        }

        $packages = $orders[0]['packages'] ?? [];

        foreach ($packages as $pkg) {
            $tn = $pkg['tracking_number'] ?? null;
            if ($tn !== null && $tn !== '') {
                return [
                    'tracking_number' => (string) $tn,
                    'shipping_provider' => $pkg['shipping_provider_name'] ?? $pkg['shipping_provider'] ?? null,
                ];
            }
        }

        foreach ($packages as $pkg) {
            $packageId = $pkg['id'] ?? null;
            if (! $packageId) {
                continue;
            }
            try {
                $docRes = $this->getShippingDocument((string) ($shop->shop_id ?? ''), (string) $packageId, 'SHIPPING_LABEL');
                $tn = $docRes['data']['tracking_number'] ?? null;
                if ($tn !== null && $tn !== '') {
                    return [
                        'tracking_number' => (string) $tn,
                        'shipping_provider' => $pkg['shipping_provider_name'] ?? $pkg['shipping_provider'] ?? null,
                    ];
                }
            } catch (\Throwable $e) {
                Log::debug('resolveTrackingNumber: shipping document fallback gagal', [
                    'order_id' => $orderId,
                    'package_id' => $packageId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }
}
