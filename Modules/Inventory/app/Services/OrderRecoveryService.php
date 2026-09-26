<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Exceptions\UserFacingException;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\ChannelOrderRefreshService;
use Modules\Inventory\Repositories\OrderRecoveryRepository;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\BulkShippingLabelService;
use Modules\Sales\Services\SalesOrderService;
use Throwable;

final readonly class OrderRecoveryService
{
    private const REQUEST_DEADLINE_SECONDS = 25;

    private const BATCH_SLICE_LIMIT = 20;

    public function __construct(
        private OrderRecoveryRepository $repository,
        private ChannelOrderRefreshService $orderRefresh,
        private SalesOrderService $salesOrders,
        private BulkShippingLabelService $bulkLabels,
    ) {}

    public function sync(
        User $user,
        array $items,
        string $action,
        ?string $batchId = null,
        bool $refreshExistingOrder = true,
    ): array {
        $startedAt = microtime(true);
        $deadline = $startedAt + self::REQUEST_DEADLINE_SECONDS;
        $results = [];

        foreach ($this->uniqueItems($items) as $item) {
            if (microtime(true) >= $deadline) {
                $results[] = $this->baseResult($item, 'timed_out', 'Batas waktu request tercapai. Jalankan ulang hanya untuk pesanan ini.');

                continue;
            }

            $results[] = $this->recoverOne(
                $user,
                $item,
                $action,
                $deadline,
                $batchId,
                $refreshExistingOrder,
            );
        }

        $counts = array_count_values(array_column($results, 'status'));

        Log::info('Synchronous order recovery completed', [
            'user_id' => (string) $user->id,
            'action' => $action,
            'total' => count($results),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'status_counts' => $counts,
        ]);

        return [
            'action' => $action,
            'synchronous' => true,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'summary' => [
                'total' => count($results),
                'ready' => (int) ($counts['ready'] ?? 0),
                'success' => (int) ($counts['success'] ?? 0),
                'waiting_marketplace' => (int) ($counts['waiting_marketplace'] ?? 0),
                'skipped' => (int) ($counts['skipped'] ?? 0),
                'busy' => (int) ($counts['busy'] ?? 0),
                'timed_out' => (int) ($counts['timed_out'] ?? 0),
                'failed' => (int) ($counts['failed'] ?? 0),
            ],
            'items' => $results,
        ];
    }

    public function syncBatch(User $user, string $batchId): array
    {
        try {
            $lock = Cache::lock("order-recovery-batch:{$batchId}", 120);
            $acquired = $lock->get();
        } catch (Throwable $exception) {
            Log::error('Order recovery batch lock unavailable', [
                'batch_id' => $batchId,
                'exception' => $exception::class,
            ]);

            throw new UserFacingException(
                'Pengaman tidak tersedia',
                'Pengaman proses ganda sedang tidak tersedia. Coba kembali sebentar lagi.',
                503,
            );
        }

        if (! $acquired) {
            throw new UserFacingException(
                'Batch sedang diproses',
                'Batch ini sedang disinkronkan oleh permintaan lain. Batch lain tetap dapat diproses.',
                409,
            );
        }

        try {
            $slice = $this->repository->batchRecoverySlice($batchId, self::BATCH_SLICE_LIMIT);
            if ($slice === null) {
                throw new UserFacingException(
                    'Batch tidak ditemukan',
                    'Batch tidak ditemukan atau tidak berada pada gudang yang dapat Anda akses.',
                    404,
                );
            }

            $result = $this->sync(
                $user,
                $slice['items'],
                'all',
                $batchId,
                refreshExistingOrder: false,
            );
            $latest = $this->repository->batchRecoverySlice($batchId, self::BATCH_SLICE_LIMIT) ?? $slice;
            if ((int) $latest['remaining'] === 0) {
                $this->bulkLabels->finalizeSynchronouslyIfReady($batchId);
                $latest = $this->repository->batchRecoverySlice($batchId, self::BATCH_SLICE_LIMIT) ?? $latest;
            }
            $result['batch'] = $latest['batch'];
            $result['processed_in_request'] = count($slice['items']);
            $result['remaining'] = (int) $latest['remaining'];
            $result['has_more'] = (int) $latest['remaining'] > 0;

            Log::info('Synchronous order recovery batch slice completed', [
                'user_id' => (string) $user->id,
                'batch_id' => $batchId,
                'processed_in_request' => $result['processed_in_request'],
                'remaining' => $result['remaining'],
            ]);

            return $result;
        } finally {
            $lock->release();
        }
    }

    private function recoverOne(
        User $user,
        array $item,
        string $action,
        float $deadline,
        ?string $batchId = null,
        bool $refreshExistingOrder = true,
    ): array {
        $reference = trim((string) $item['reference']);
        $channel = $this->nullableString($item['channel'] ?? null);
        $shopId = $this->nullableString($item['shop_id'] ?? null);
        $lockKey = 'order-recovery:'.hash('sha256', mb_strtolower($reference));
        try {
            $lock = Cache::lock($lockKey, 120);
            $acquired = $lock->get();
        } catch (Throwable $exception) {
            Log::error('Order recovery lock unavailable', [
                'reference_hash' => hash('sha256', $reference),
                'exception' => $exception::class,
            ]);

            return $this->baseResult($item, 'failed', 'Pengaman proses ganda sedang tidak tersedia. Coba kembali sebentar lagi.');
        }

        if (! $acquired) {
            return $this->baseResult($item, 'busy', 'Pesanan sedang diproses oleh permintaan lain. Tidak ada proses duplikat yang dibuat.');
        }

        $itemStartedAt = microtime(true);

        try {
            $order = $this->repository->findOrder($reference, $channel, $shopId);

            if ($order !== null && $action === 'all' && $this->isReady($order)) {
                if ($batchId !== null) {
                    return $this->stageReadyOrderForBatch($order, $batchId, $itemStartedAt);
                }

                return $this->orderResult($order, 'ready', 'Resi dan label sudah siap. Tidak diproses ulang.', $itemStartedAt);
            }

            if (in_array($action, ['order', 'all'], true) && ($order === null || $refreshExistingOrder)) {
                [$channel, $shopId] = $this->resolveIdentity($reference, $order, $channel, $shopId);
                if ($channel === null || $shopId === null) {
                    return $this->baseResult(
                        $item,
                        'failed',
                        'Channel dan toko tidak dapat ditentukan. Pilih toko sebelum menarik pesanan.',
                        $itemStartedAt,
                    );
                }

                $channelReference = $this->nullableString($order?->channel_order_no) ?? $reference;
                $pulled = $this->orderRefresh->refresh($channel, $shopId, $channelReference);
                $order = $this->repository->findOrder($reference, $channel, $shopId);

                if ($order === null) {
                    return $this->baseResult(
                        ['reference' => $reference, 'channel' => $channel, 'shop_id' => $shopId],
                        $pulled > 0 ? 'waiting_marketplace' : 'failed',
                        $pulled > 0
                            ? 'Marketplace menerima permintaan, tetapi order belum terbaca kembali. Coba periksa lagi.'
                            : 'Order tidak ditemukan atau sinkronisasi channel sedang dinonaktifkan.',
                        $itemStartedAt,
                    );
                }

                if ($action === 'order') {
                    return $this->orderResult($order, 'success', 'Data pesanan berhasil disinkronkan langsung.', $itemStartedAt);
                }
            }

            if ($order === null) {
                return $this->baseResult($item, 'failed', 'Pesanan belum ada di WMS. Sinkronkan pesanan terlebih dahulu.', $itemStartedAt);
            }

            if (microtime(true) >= $deadline) {
                return $this->orderResult($order, 'timed_out', 'Batas waktu request tercapai sebelum tahap resi/label.', $itemStartedAt);
            }

            if (in_array($action, ['awb', 'all'], true) && empty($order->tracking_number)) {
                $awb = $this->salesOrders->requestAwb(['order_id' => (string) $order->id], scheduleRecovery: false);
                $order = $this->repository->findOrder($reference, (string) $order->source, (string) $order->channel_shop_id) ?? $order->fresh();

                if (empty($order->tracking_number)) {
                    $message = (string) ($awb['message'] ?? 'Marketplace masih menyiapkan nomor resi.');

                    return $this->orderResult($order, 'waiting_marketplace', $message, $itemStartedAt);
                }

                if ($action === 'awb') {
                    return $this->orderResult($order, 'success', 'Nomor resi berhasil diperoleh.', $itemStartedAt);
                }
            } elseif ($action === 'awb') {
                return $this->orderResult($order, 'ready', 'Nomor resi sudah tersedia. Tidak diminta ulang.', $itemStartedAt);
            }

            if ($action === 'label' && empty($order->tracking_number)) {
                return $this->orderResult($order, 'waiting_marketplace', 'Nomor resi belum tersedia. Minta resi terlebih dahulu.', $itemStartedAt);
            }

            if (in_array($action, ['label', 'all'], true)) {
                if ($order->shipping_label_status === 'ready') {
                    return $this->orderResult($order, 'ready', 'Label sudah siap. Tidak diunduh ulang dari marketplace.', $itemStartedAt);
                }

                if (microtime(true) >= $deadline) {
                    return $this->orderResult($order, 'timed_out', 'Batas waktu request tercapai sebelum pengambilan label.', $itemStartedAt);
                }

                $prepared = $this->salesOrders->prepareShippingLabelDocument(
                    $order,
                    BulkShippingLabelService::DEFAULT_SIZE,
                    scheduleRecovery: false,
                );
                $order = $order->fresh();
                $encoded = $prepared['document_base64'] ?? null;
                $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;
                if ($order->shipping_label_status !== 'ready' || ! is_string($bytes) || $bytes === '') {
                    return $this->orderResult(
                        $order,
                        'waiting_marketplace',
                        'Marketplace belum mengembalikan dokumen label yang lengkap. Coba sinkronkan kembali.',
                        $itemStartedAt,
                    );
                }
                if ($batchId !== null) {
                    $this->bulkLabels->stageReadyLabelForBatchItem($batchId, (string) $order->id, $bytes);
                }

                return $this->orderResult($order, 'ready', 'Label berhasil diambil dan disimpan.', $itemStartedAt);
            }

            return $this->orderResult($order, 'success', 'Pesanan berhasil diproses.', $itemStartedAt);
        } catch (UserFacingException $exception) {
            $status = $exception->getStatus() === 202 ? 'waiting_marketplace' : 'failed';
            $order = $this->repository->findOrder($reference, $channel, $shopId);

            return $order !== null
                ? $this->orderResult($order, $status, $this->userMessage($exception), $itemStartedAt)
                : $this->baseResult($item, $status, $this->userMessage($exception), $itemStartedAt);
        } catch (Throwable $exception) {
            Log::warning('Synchronous order recovery failed', [
                'user_id' => (string) $user->id,
                'reference' => $reference,
                'action' => $action,
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);

            return $this->baseResult($item, 'failed', $this->safeMessage(), $itemStartedAt);
        } finally {
            $lock->release();
        }
    }

    private function stageReadyOrderForBatch(SalesOrder $order, string $batchId, float $startedAt): array
    {
        $sourceBytes = $this->salesOrders->cachedShippingLabelBytes($order);
        if ($sourceBytes !== null && $sourceBytes !== '') {
            $bytes = $this->bulkLabels->normalizeToTarget(
                $sourceBytes,
                BulkShippingLabelService::DEFAULT_SIZE,
                strtolower((string) $order->source),
            );
        } else {
            $prepared = $this->salesOrders->prepareShippingLabelDocument(
                $order,
                BulkShippingLabelService::DEFAULT_SIZE,
                scheduleRecovery: false,
            );
            $encoded = $prepared['document_base64'] ?? null;
            $decoded = is_string($encoded) ? base64_decode($encoded, true) : false;
            if (! is_string($decoded) || $decoded === '') {
                return $this->orderResult(
                    $order->fresh(),
                    'waiting_marketplace',
                    'Dokumen label belum tersedia lengkap untuk dipasang kembali ke batch.',
                    $startedAt,
                );
            }
            $bytes = $decoded;
        }

        $this->bulkLabels->stageReadyLabelForBatchItem($batchId, (string) $order->id, $bytes);

        return $this->orderResult(
            $order->fresh(),
            'ready',
            'Label yang sudah siap berhasil dipasang kembali ke batch.',
            $startedAt,
        );
    }

    private function resolveIdentity(string $reference, ?SalesOrder $order, ?string $channel, ?string $shopId): array
    {
        $channel ??= $this->nullableString($order?->source);
        $shopId ??= $this->nullableString($order?->channel_shop_id);

        if ($channel !== null && $shopId !== null) {
            return [$channel, $shopId];
        }

        $identity = $this->repository->latestWebhookIdentity($reference);

        return [$channel ?? ($identity['channel'] ?? null), $shopId ?? ($identity['shop_id'] ?? null)];
    }

    private function isReady(SalesOrder $order): bool
    {
        return filled($order->tracking_number) && $order->shipping_label_status === 'ready';
    }

    private function orderResult(SalesOrder $order, string $status, string $message, float $startedAt): array
    {
        return [
            'reference' => (string) ($order->channel_order_no ?: $order->salesorder_no),
            'order_id' => (string) $order->id,
            'internal_order_no' => (string) $order->salesorder_no,
            'channel' => $this->nullableString($order->source),
            'shop_id' => $this->nullableString($order->channel_shop_id),
            'status' => $status,
            'message' => $message,
            'tracking_number' => $this->nullableString($order->tracking_number),
            'shipping_label_status' => $this->nullableString($order->shipping_label_status),
            'label_ready' => $order->shipping_label_status === 'ready',
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    private function baseResult(array $item, string $status, string $message, ?float $startedAt = null): array
    {
        return [
            'reference' => trim((string) $item['reference']),
            'order_id' => null,
            'internal_order_no' => null,
            'channel' => $this->nullableString($item['channel'] ?? null),
            'shop_id' => $this->nullableString($item['shop_id'] ?? null),
            'status' => $status,
            'message' => $message,
            'tracking_number' => null,
            'shipping_label_status' => null,
            'label_ready' => false,
            'duration_ms' => $startedAt === null ? 0 : (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    private function uniqueItems(array $items): array
    {
        $unique = [];
        foreach ($items as $item) {
            $reference = trim((string) $item['reference']);
            $channel = $this->nullableString($item['channel'] ?? null);
            $shopId = $this->nullableString($item['shop_id'] ?? null);
            $key = strtolower(implode('|', [$channel, $shopId, $reference]));
            $unique[$key] = ['reference' => $reference, 'channel' => $channel, 'shop_id' => $shopId];
        }

        return array_values($unique);
    }

    private function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function userMessage(UserFacingException $exception): string
    {
        $detail = data_get($exception->getErrors(), 'detail');

        return is_string($detail) && $detail !== '' ? $detail : $exception->getMessage();
    }

    private function safeMessage(): string
    {
        return 'Proses gagal. Periksa koneksi channel lalu coba kembali.';
    }
}
