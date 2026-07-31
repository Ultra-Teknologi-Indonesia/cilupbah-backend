<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;
use Modules\Outbound\Support\InstantOrderClassifier;
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
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $accessToken = $shop->access_token;
        $shopCipher = $shop->shop_cipher ?? '';

        $count = 0;
        $nextPageToken = '';

        do {
            $queries = [
                'shop_cipher' => $shopCipher,
                'page_size'   => 100,
            ];

            $body = [
                'sort_by'   => 'CREATE_TIME',
                'sort_type' => 'DESC',
            ];

            if ($nextPageToken !== '') {
                $body['next_page_token'] = $nextPageToken;
            }

            $res = $this->client->request('POST', '/order/202309/orders/search', $queries, $body, $accessToken);

            if (! isset($res['data']['orders'])) {
                break;
            }

            foreach ($res['data']['orders'] as $item) {
                try {
                    $this->dumpInstantPayloadForResearch($item, $shopId);
                    $internalData = $this->mapper->map($item, $shopId);
                    $internalData = $this->enrichTrackingFromPackages($internalData, $item, $shopCipher, $accessToken);
                    $this->orderService->upsertFromChannel($internalData);
                    $count++;
                } catch (\Exception $e) {
                    Log::error("Failed to pull order {$item['id']}: " . $e->getMessage());
                }
            }

            $nextPageToken = $res['data']['next_page_token'] ?? '';
        } while ($nextPageToken !== '');

        return $count;
    }

    public function pullOrderById(string $shopId, string $orderId): ?int
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $queries = [
            'shop_cipher' => $shop->shop_cipher ?? '',
            'ids'         => $orderId,
        ];

        $res = $this->client->request('GET', '/order/202309/orders', $queries, [], $shop->access_token);

        if (! isset($res['data']['orders']) || empty($res['data']['orders'])) {
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
                $this->orderService->upsertFromChannel($internalData);
                $count++;
            } catch (\Exception $e) {
                Log::error("Failed to pull specific order {$item['id']}: " . $e->getMessage());
            }
        }

        return $count;
    }

    protected function dumpInstantPayloadForResearch(array $item, string $shopId): void
    {
        if (! config('services.tiktok.dump_instant_payload')) {
            return;
        }

        $provider = $item['packages'][0]['shipping_provider_name']
            ?? ($item['line_items'][0]['shipping_provider_name'] ?? null);
        $fulfillmentType = (string) ($item['fulfillment_type'] ?? '');

        $isInstant = InstantOrderClassifier::isInstant($provider, $item['shipping_type'] ?? null)
            || preg_match('/instant|same[- ]?day/i', $fulfillmentType) === 1;

        if (! $isInstant) {
            return;
        }

        Log::info('TikTok instant/sameday order payload (riset pickup_code)', [
            'shop_id'            => $shopId,
            'order_id'           => $item['id'] ?? null,
            'top_level_keys'     => array_keys($item),
            'shipping_type'      => $item['shipping_type'] ?? null,
            'fulfillment_type'   => $item['fulfillment_type'] ?? null,
            'delivery_option_id' => $item['delivery_option_id'] ?? null,
            'tracking_number'    => $item['tracking_number'] ?? null,
            'packages'           => $item['packages'] ?? null,
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
            Log::warning("Failed to enrich tracking from package {$packageId}: " . $e->getMessage());
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

        $res = $this->client->request('GET', $this->financeStatementPath($orderId), $queries, [], $shop->access_token);

        return $res['data'] ?? [];
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

    public function readyToShip(string $shopId, string $orderId, ?array $handover = null): array
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];

        try {
            $packageIds = $this->resolvePackageIds($shop, $orderId, $queries);

            if (empty($packageIds)) {
                Log::warning('TikTok RTS: no package_id resolvable for order', [
                    'shop_id'  => $shopId,
                    'order_id' => $orderId,
                ]);

                return [
                    'order_id' => $orderId,
                    'shipped'  => false,
                    'message'  => 'Tidak ada package_id yang dapat di-resolve untuk order ini. Pastikan order sudah AWAITING_SHIPMENT dan package sudah dibuat.',
                    'packages' => [],
                ];
            }

            $results = [];
            $allOk = true;

            foreach ($packageIds as $packageId) {
                try {
                    $shipBody = ['order_id' => $orderId];

                    if ($handover) {
                        if (! empty($handover['tracking_number'])) {
                            $shipBody['tracking_number'] = $handover['tracking_number'];
                        }
                        if (! empty($handover['shipping_provider_id'])) {
                            $shipBody['shipping_provider_id'] = $handover['shipping_provider_id'];
                        }
                    }

                    $res = $this->client->request(
                        'POST',
                        "/fulfillment/202309/packages/{$packageId}/ship",
                        $queries,
                        $shipBody,
                        $shop->access_token
                    );

                    $results[] = ['package_id' => $packageId, 'shipped' => true, 'response' => $res['data'] ?? []];
                } catch (\Throwable $e) {
                    $allOk = false;
                    Log::error('TikTok RTS: gagal ship package', [
                        'shop_id'    => $shopId,
                        'order_id'   => $orderId,
                        'package_id' => $packageId,
                        'error'      => $e->getMessage(),
                    ]);
                    $results[] = ['package_id' => $packageId, 'shipped' => false, 'message' => $e->getMessage()];
                }
            }

            $someOk = collect($results)->contains('shipped', true);

            if ($allOk || $someOk) {
                $this->resyncLocalOrder($shopId, $orderId);
            }

            $this->fetchAndStoreTracking($shop, $orderId, $queries);

            return [
                'order_id' => $orderId,
                'shipped'  => $allOk,
                'message'  => $allOk
                    ? 'RTS berhasil.'
                    : ($someOk ? 'Sebagian package berhasil, sebagian gagal (PARTIALLY_SHIPPING).' : 'Semua package gagal di-RTS.'),
                'packages' => $results,
            ];
        } catch (\Throwable $e) {
            Log::error('TikTok RTS: gagal', [
                'shop_id'  => $shopId,
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Package id(s) untuk sebuah order — read-only (tidak membuat package baru).
     * Dipakai penyiapan label proaktif; kosong = paket belum ada / label belum siap.
     */
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
            'shop_cipher'   => $shop->shop_cipher ?? '',
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

    public function acceptBuyerCancellation(string $shopId, string $orderId): array
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        // API pakai cancel_id (bukan order_id) & path harus /{cancel_id}/approve.
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

        $this->resyncLocalOrder($shopId, $orderId);

        return $res;
    }

    /**
     * Cari cancel_id (ID permintaan pembatalan) + alasan pembeli untuk sebuah
     * order TikTok. Endpoint: POST /return_refund/202309/cancellations/search.
     *
     * @return array{cancel_id: ?string, reason_key: ?string, reason_text: ?string, status: ?string, raw: array}
     */
    public function searchBuyerCancellation(string $shopId, string $orderId): array
    {
        $empty = ['cancel_id' => null, 'reason_key' => null, 'reason_text' => null, 'status' => null, 'raw' => []];

        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            return $empty;
        }

        $queries = ['shop_cipher' => $shop->shop_cipher ?? '', 'page_size' => 50];
        // Field filter yang benar = main_order_id_list (dikonfirmasi live; `order_ids`
        // diabaikan TikTok dan mengembalikan 0).
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

        // Cocokkan tepat berdasarkan order_id (filter server bisa mengembalikan lebih).
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
            'cancel_id'   => isset($c['cancel_id']) ? (string) $c['cancel_id'] : (isset($c['id']) ? (string) $c['id'] : null),
            'reason_key'  => $c['cancel_reason_key'] ?? $c['cancel_reason'] ?? null,
            'reason_text' => $c['cancel_reason_text'] ?? $c['reason_text'] ?? null,
            'status'      => $c['cancel_status'] ?? $c['status'] ?? null,
            'raw'         => $c,
        ];
    }

    private function resolveCancellationId(string $shopId, string $orderId): ?string
    {
        return $this->searchBuyerCancellation($shopId, $orderId)['cancel_id'] ?? null;
    }

    /**
     * Ambil nama alasan penolakan pembatalan yang valid dari Get Decision
     * Eligibility (reject_reason WAJIB saat menolak pembatalan pembeli).
     */
    public function firstRejectCancelReason(string $shopId, string $cancelId): ?string
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            return null;
        }

        $queries = [
            'shop_cipher'         => $shop->shop_cipher ?? '',
            'return_or_cancel_id' => $cancelId,
            'check_decisions'     => 'REJECT_REQUEST_CANCEL',
            'locale'              => 'id-ID',
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
        $empty = ['tracking_number' => null, 'carrier' => null, 'shipped_at' => null];

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
                'carrier'         => $carrier ? (string) $carrier : null,
                'shipped_at'      => $shippedAt,
            ];
        } catch (\Throwable $e) {
            Log::warning("TikTok: gagal ambil resi retur (return_id={$returnId}): " . $e->getMessage());

            return $empty;
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

            return [
                'channel_status' => isset($ret['return_status']) ? (string) $ret['return_status'] : null,
                'reason_code' => $ret['return_reason_key'] ?? null,
                'reason_text' => $ret['return_reason'] ?? null,
                'refund_amount' => isset($ret['refund_amount']) ? (float) $ret['refund_amount'] : null,
                'refund_currency' => $ret['currency'] ?? null,
                'shipping_fee_original' => null,
                'shipping_fee_return' => isset($ret['shipping_fee_amount']) ? (float) $ret['shipping_fee_amount'] : null,
                'tracking_number' => $tracking ? (string) $tracking : null,
                'carrier' => $carrier ? (string) $carrier : null,
                'shipped_at' => $shippedAt,
                'raw' => $ret,
            ];
        } catch (\Throwable $e) {
            Log::warning("TikTok: gagal ambil detail retur (return_id={$returnId}): " . $e->getMessage());

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
            Log::warning("TikTok: gagal ambil riwayat banding retur (return_id={$returnId}): " . $e->getMessage());

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
            Log::warning("TikTok: gagal setujui retur (return_id={$returnId}): " . $e->getMessage());

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
            Log::warning("TikTok: gagal tolak retur (return_id={$returnId}): " . $e->getMessage());

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
            Log::warning("TikTok: gagal ambil alasan tolak retur (return_id={$returnId}): " . $e->getMessage());

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

        // reject_reason WAJIB (nama alasan dari Get Decision Eligibility).
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
            'order_id'           => $orderId,
            'cancel_reason_key'  => $reason,
            'cancel_reason'      => $reason,
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
            throw \Modules\Channel\Exceptions\ChannelCancelException::final(
                "TikTok menolak pembatalan {$orderId}: status {$shown} tidak dapat dibatalkan seller.",
            );
        }

        $shop = $this->shopRepository->findByShopId($order->channel_shop_id);
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("No access token found for shop: {$order->channel_shop_id}");
        }

        $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];
        $body = [
            'order_id'      => $tiktokOrderId,
            'cancel_reason' => $reason,
        ];

        $res = $this->client->request('POST', '/return_refund/202309/cancellations', $queries, $body, $shop->access_token);

        $code = (int) ($res['code'] ?? 0);
        if ($code !== 0) {
            $message = $res['message'] ?? "code {$code}";

            throw new \Modules\Channel\Exceptions\ChannelCancelException(
                "TikTok menolak pembatalan {$orderId}: {$message}",
                retryable: $code === 36009003,
                channelCode: (string) $code,
            );
        }

        $cancelStatus = $res['data']['cancel_status'] ?? null;
        if ($cancelStatus === 'CANCELLATION_REQUEST_CANCEL') {
            throw \Modules\Channel\Exceptions\ChannelCancelException::final(
                "TikTok membatalkan permintaan pembatalan {$orderId} (CANCELLATION_REQUEST_CANCEL).",
            );
        }

        $this->resyncLocalOrder($order->channel_shop_id, $tiktokOrderId);

        return [
            'cancel_id'     => $res['data']['cancel_id'] ?? null,
            'cancel_status' => $cancelStatus,
            'async'         => $cancelStatus !== 'CANCELLATION_REQUEST_COMPLETE',
            'raw'           => $res,
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
                'key'   => (string) $key,
                'label' => (string) ($r['text'] ?? $r['label'] ?? $key),
            ];
        }, $reasons)));
    }

    protected function resyncLocalOrder(string $shopId, string $orderId): void
    {
        try {
            $this->pullOrderById($shopId, $orderId);
        } catch (\Throwable $e) {
            Log::warning("TikTok: resync order {$orderId} gagal pasca aksi: " . $e->getMessage());
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
                    'error'    => $e->getMessage(),
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

            if (! $trackingNumber) {
                foreach ($packages as $pkg) {
                    $packageId = $pkg['id'] ?? null;
                    if (! $packageId) {
                        continue;
                    }
                    try {
                        $shop2 = $shop;
                        $docRes = $this->getShippingDocument((string) ($shop2->shop_id ?? ''), (string) $packageId, 'SHIPPING_LABEL');
                        $tn = $docRes['data']['tracking_number'] ?? null;
                        if ($tn !== null && $tn !== '') {
                            $trackingNumber = (string) $tn;
                            $shippingProvider = $pkg['shipping_provider_name'] ?? $pkg['shipping_provider'] ?? null;
                            break;
                        }
                    } catch (\Throwable $docErr) {
                        Log::debug('fetchAndStoreTracking: shipping document fallback gagal', [
                            'order_id'   => $orderId,
                            'package_id' => $packageId,
                            'error'      => $docErr->getMessage(),
                        ]);
                    }
                }
            }

            if ($trackingNumber) {
                $this->orderRepository->updateTrackingByOrderNo($orderId, $trackingNumber, $shippingProvider);
            }
        } catch (\Throwable $e) {
            Log::warning('TikTok: gagal fetch tracking post-RTS', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
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
                    'tracking_number'   => (string) $tn,
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
                        'tracking_number'   => (string) $tn,
                        'shipping_provider' => $pkg['shipping_provider_name'] ?? $pkg['shipping_provider'] ?? null,
                    ];
                }
            } catch (\Throwable $e) {
                Log::debug('resolveTrackingNumber: shipping document fallback gagal', [
                    'order_id'   => $orderId,
                    'package_id' => $packageId,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return null;
    }
}
