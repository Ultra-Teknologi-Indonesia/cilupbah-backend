<?php

namespace Modules\Sales\Services;

use App\Exceptions\UserFacingException;
use Modules\Channel\Jobs\ProcessLazadaFulfillmentJob;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Channel\Support\UploadErrorPresenter;
use Modules\Sales\Exceptions\ShippingLabelPreparingException;
use Modules\Sales\Jobs\CallLazadaDriverJob;
use Modules\Sales\Jobs\CallShopeeDriverJob;
use Modules\Sales\Jobs\CallTikTokDriverJob;
use Modules\Sales\Jobs\RequestChannelAwbJob;
use Modules\Sales\Models\ChannelOperationAttempt;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Repositories\SalesOrderRepository;
use Modules\Sales\Support\ChannelOperationLedger;
use Modules\Sales\Support\DriverCallReadiness;

class SalesOrderDriverCallService
{
    private const SUPPORTED_SOURCES = ['shopee', 'tiktok', 'lazada'];

    public function __construct(
        protected ShopeeOrderService $shopee,
        protected TikTokOrderService $tiktok,
        protected SalesOrderRepository $orderRepository,
        protected SalesOrderService $orderService,
    ) {}

    public function findOrder(string $id): SalesOrder
    {
        return $this->orderRepository->findOrFail($id);
    }

    public function dispatchPrintWithDriverCall(SalesOrder $order, array $query): array
    {
        if ($order->isManual()) {
            throw new UserFacingException(
                'Aksi tidak dapat diproses',
                'Pesanan manual tidak memicu panggilan driver marketplace.',
                422,
            );
        }

        $source = strtolower((string) $order->source);
        if (! in_array($source, self::SUPPORTED_SOURCES, true)) {
            throw new UserFacingException(
                'Aksi tidak dapat diproses',
                "Panggil driver belum didukung untuk source '{$source}'.",
                422,
            );
        }

        if (! $order->is_instant) {
            throw new UserFacingException(
                'Aksi tidak dapat diproses',
                'Endpoint ini hanya untuk pesanan Instant / Same Day (Shopee / TikTok / Lazada).',
                422,
            );
        }

        $shopId = (string) $order->channel_shop_id;
        $orderSn = (string) $order->channel_order_no;
        if ($shopId === '' || $orderSn === '') {
            throw new UserFacingException(
                'Aksi tidak dapat diproses',
                'channel_shop_id / channel_order_no kosong pada pesanan.',
                422,
            );
        }

        $forceLabel = (bool) ($query['force_label'] ?? false);

        $order->refresh();
        if (! DriverCallReadiness::ready($order)) {
            return $this->queuePrerequisites($order);
        }

        $driverCallSuccess = $this->callDriver($order);

        if (! $driverCallSuccess && ! $forceLabel) {
            throw new UserFacingException(
                'Aksi tidak dapat diproses',
                'Panggilan driver marketplace gagal. Tambahkan ?force_label=1 untuk tetap mencetak label.',
                422,
                [
                    'driver_call_status' => $order->driver_call_status,
                    'driver_call_message' => $order->driver_call_message,
                    'driver_call_attempted_at' => optional($order->driver_call_attempted_at)?->toIso8601String(),
                ],
            );
        }

        $options = array_filter([
            'doc_type' => $query['doc_type'] ?? null,
            'document_type' => $query['document_type'] ?? null,
            'document_size' => $query['document_size'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        try {
            $order->refresh();
            if ($source === 'tiktok' && empty($order->tracking_number)) {
                throw new ShippingLabelPreparingException(
                    'Permintaan pengiriman TikTok sudah diterima, tetapi tracking number belum diterbitkan. Sistem sedang menunggu resi sebelum mengambil label.'
                );
            }

            $labelResult = $this->orderService->getShippingLabel($order, $options);
            $order->refresh();

            if ($driverCallSuccess && ! DriverCallReadiness::ready($order)) {
                throw new ShippingLabelPreparingException(
                    'Tracking atau shipping label belum tervalidasi. Sistem belum menandai driver sebagai berhasil.',
                );
            }
        } catch (ShippingLabelPreparingException $e) {
            return [
                'data' => [
                    'driver_call_status' => $order->driver_call_status,
                    'driver_call_message' => $order->driver_call_message,
                    'driver_call_attempted_at' => optional($order->driver_call_attempted_at)?->toIso8601String(),
                    'label' => null,
                    'label_preparing' => true,
                    'label_message' => $e->getMessage(),
                ],
                'message' => 'Permintaan pengiriman diterima, tetapi resi belum tersedia. Label masih disiapkan; coba unduh lagi dalam beberapa detik.',
                'code' => 202,
            ];
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            throw new UserFacingException(
                'Aksi tidak dapat diproses',
                'Driver berhasil dipanggil namun label gagal diambil.',
                422,
                [
                    'success' => $driverCallSuccess,
                    'driver_call_status' => $order->driver_call_status,
                    'driver_call_message' => $order->driver_call_message,
                    'driver_call_attempted_at' => optional($order->driver_call_attempted_at)?->toIso8601String(),
                    'label' => null,
                    'label_error' => $e->getMessage(),
                    'detail' => $e->getMessage(),
                ],
            );
        }

        return [
            'data' => [
                'driver_call_status' => $order->driver_call_status,
                'driver_call_message' => $order->driver_call_message,
                'driver_call_attempted_at' => optional($order->driver_call_attempted_at)?->toIso8601String(),
                'label' => $labelResult,
            ],
            'message' => $driverCallSuccess
                ? ($order->driver_call_status === 'success'
                    ? 'Driver terpanggil dan label siap diunduh.'
                    : 'Label siap diunduh. Panggilan driver masih diproses oleh marketplace.')
                : 'Label siap; panggilan driver gagal — silakan retry.',
            'code' => 200,
        ];
    }

    public function dispatchRetryDriverCall(SalesOrder $order): array
    {
        $source = strtolower((string) $order->source);
        if (! in_array($source, self::SUPPORTED_SOURCES, true)) {
            throw new UserFacingException(
                'Aksi tidak dapat diproses',
                "Retry driver belum didukung untuk source '{$source}'.",
                422,
            );
        }

        if (! $order->is_instant) {
            throw new UserFacingException(
                'Aksi tidak dapat diproses',
                'Endpoint ini hanya untuk pesanan Instant / Same Day (Shopee / TikTok / Lazada).',
                422,
            );
        }

        $this->retryDriverCall($order);

        return [
            'data' => ['driver_call_status' => 'pending'],
            'message' => 'Panggilan driver dicoba ulang. Status akan diperbarui dalam beberapa detik.',
            'code' => 202,
        ];
    }

    private const NON_CALLABLE_CHANNEL_STATUSES = [
        'CANCELLED',
        'IN_CANCEL',
        'RETURN_REQUESTED',
        'RETURNED',
        'SHIPPED',
        'TO_CONFIRM_RECEIVE',
        'COMPLETED',
    ];

    public function callDriver(SalesOrder $order): bool
    {
        if ($guard = $this->guardCallable($order)) {
            $this->markUnsupported($order, $guard);

            return false;
        }

        $source = strtolower((string) $order->source);

        if (! $this->deferIfNotReady($order)) {
            return true;
        }

        if ($source === 'tiktok' && $order->driver_call_status === 'success') {
            if (empty($order->tracking_number)) {
                $order->update([
                    'driver_call_status' => 'pending',
                    'driver_call_message' => 'Permintaan TikTok sudah diterima, tetapi tracking number belum tersedia. Sistem sedang menunggu resi.',
                ]);
                RequestChannelAwbJob::dispatch($order->id, 1, false, false, true)->afterCommit();
            }

            return true;
        }

        return match ($source) {
            'shopee' => $this->callShopee($order),
            'tiktok' => $this->callTikTok($order),
            'lazada' => $this->callLazada($order),
            default => $this->markUnsupported($order, "Panggil driver untuk source '{$source}' belum didukung."),
        };
    }

    public function deferIfNotReady(SalesOrder $order): bool
    {
        $order->refresh();

        if (DriverCallReadiness::ready($order)) {
            return true;
        }

        $this->queuePrerequisites($order);

        return false;
    }

    private function queuePrerequisites(SalesOrder $order): array
    {
        $order->update([
            'driver_call_status' => 'pending',
            'driver_call_message' => filled($order->tracking_number)
                ? 'Shipping label belum siap. Driver belum dipanggil.'
                : 'Tracking number belum tersedia. Sistem mengambil resi terlebih dahulu; driver belum dipanggil.',
            'driver_call_attempted_at' => now(),
        ]);

        if (blank($order->tracking_number)) {
            RequestChannelAwbJob::dispatch(
                $order->id,
                0,
                true,
                false,
                false,
            )->afterCommit();
        } else {
            app(ShippingLabelPreparationDispatcher::class)->dispatch($order);
        }

        return [
            'data' => [
                'driver_call_status' => 'pending',
                'driver_call_message' => $order->driver_call_message,
                'driver_call_attempted_at' => optional($order->driver_call_attempted_at)?->toIso8601String(),
                'label' => null,
                'label_preparing' => true,
            ],
            'message' => 'Tracking dan shipping label harus siap sebelum driver dipanggil. Sistem sedang menyiapkannya.',
            'code' => 202,
        ];
    }

    private function guardCallable(SalesOrder $order): ?string
    {
        $cs = strtoupper((string) $order->channel_status);

        if (in_array($cs, self::NON_CALLABLE_CHANNEL_STATUSES, true)) {
            return match (true) {
                in_array($cs, ['CANCELLED', 'IN_CANCEL'], true) => 'Pesanan sudah dibatalkan — tidak bisa panggil driver.',
                in_array($cs, ['RETURN_REQUESTED', 'RETURNED'], true) => 'Pesanan dalam proses retur — tidak bisa panggil driver.',
                in_array($cs, ['SHIPPED', 'TO_CONFIRM_RECEIVE', 'COMPLETED'], true) => 'Pesanan sudah dikirim/selesai — driver tidak perlu dipanggil lagi.',
                default => "Status '{$cs}' tidak bisa panggil driver.",
            };
        }

        return null;
    }

    public function retryDriverCall(SalesOrder $order): void
    {
        $order->update([
            'driver_call_status' => 'pending',
            'driver_call_message' => null,
        ]);

        $source = strtolower((string) $order->source);

        match ($source) {
            'shopee' => CallShopeeDriverJob::dispatch($order->id),
            'tiktok' => CallTikTokDriverJob::dispatch($order->id),
            'lazada' => CallLazadaDriverJob::dispatch($order->id),
            default => $this->markUnsupported($order, "Retry driver untuk source '{$source}' belum didukung."),
        };
    }

    private function callShopee(SalesOrder $order): bool
    {
        $shopId = (string) $order->channel_shop_id;
        $orderSn = (string) $order->channel_order_no;

        if (
            filled($order->tracking_number)
            && in_array(strtoupper((string) $order->channel_status), [
                'PROCESSED',
                'AWAITING_COLLECTION',
                'SHIPPED',
                'IN_TRANSIT',
                'TO_CONFIRM_RECEIVE',
                'COMPLETED',
            ], true)
        ) {
            $order->update([
                'driver_call_status' => 'success',
                'driver_call_message' => null,
            ]);

            return true;
        }

        $order->update([
            'driver_call_status' => 'pending',
            'driver_call_attempted_at' => now(),
        ]);

        $driverCallSuccess = false;

        try {
            if ($order->channel_status === 'RETRY_SHIP') {
                $result = $this->shopee->retryPickup($shopId, $orderSn);
                $shipped = (bool) ($result['updated'] ?? false);
            } else {
                $result = $this->shopee->shipOrder($shopId, $orderSn);
                $shipped = (bool) ($result['shipped'] ?? false);
            }
            $error = (string) ($result['error'] ?? '');
            $alreadyShipped = $error !== '' && preg_match('/already|duplicate|shipped/i', $error);

            if ($shipped || $alreadyShipped) {
                $driverCallSuccess = true;
                $order->update([
                    'driver_call_status' => 'success',
                    'driver_call_message' => null,
                    'driver_call_response' => $result,
                ]);
            } else {
                $driverCallMessage = $error !== '' ? $error : 'Panggilan driver Shopee gagal tanpa keterangan.';
                $order->update([
                    'driver_call_status' => 'failed',
                    'driver_call_message' => $this->friendly('shopee', $driverCallMessage),
                    'driver_call_response' => $result,
                ]);
            }
        } catch (\Throwable $e) {
            $order->update([
                'driver_call_status' => 'failed',
                'driver_call_message' => $this->friendly('shopee', $e->getMessage()),
                'driver_call_response' => ['exception' => $e->getMessage(), 'class' => get_class($e)],
            ]);
        }

        $order->refresh();

        return $driverCallSuccess;
    }

    private function friendly(string $source, string $raw): string
    {
        return mb_substr(UploadErrorPresenter::fromMessage($source, $raw)['reason'], 0, 500);
    }

    private function callTikTok(SalesOrder $order): bool
    {
        $shopId = (string) $order->channel_shop_id;
        $channelOrderNo = (string) $order->channel_order_no;

        $order->update([
            'driver_call_status' => 'pending',
            'driver_call_attempted_at' => now(),
        ]);

        $claim = ChannelOperationLedger::claim($order, 'request_awb');
        if (! $claim['should_execute']) {
            $attempt = $claim['attempt'];
            $isAccepted = in_array($attempt->status, [
                ChannelOperationAttempt::STATUS_ACCEPTED,
                ChannelOperationAttempt::STATUS_SUCCEEDED,
            ], true);
            $hasTracking = filled($order->tracking_number);

            $order->update([
                // TikTok may acknowledge POST /ship before it publishes the
                // tracking number. That is not a completed driver call from
                // the warehouse's point of view yet.
                'driver_call_status' => $isAccepted && $hasTracking ? 'success' : 'pending',
                'driver_call_message' => $isAccepted && $hasTracking
                    ? null
                    : ($isAccepted
                        ? 'Permintaan TikTok sudah diterima, tetapi tracking number belum tersedia. Sistem sedang memverifikasi tanpa mengirim ulang.'
                        : 'Status panggilan TikTok belum pasti. Sistem sedang memverifikasi tanpa mengirim ulang.'),
            ]);

            RequestChannelAwbJob::dispatch($order->id, 1, false, false, true)->afterCommit();
            $order->refresh();

            return true;
        }

        try {
            $result = $this->tiktok->readyToShip($shopId, $channelOrderNo);
            $shipped = (bool) ($result['shipped'] ?? false);
            $handoverAccepted = (bool) ($result['accepted'] ?? false)
                || $shipped
                || collect($result['packages'] ?? [])->contains('shipped', true);
            $allPackagesShipped = ! array_key_exists('all_packages_shipped', $result)
                || (bool) $result['all_packages_shipped'];
            $hasTracking = filled($result['tracking_number'] ?? null) || filled($order->tracking_number);

            if ($handoverAccepted) {
                if ($allPackagesShipped) {
                    ChannelOperationLedger::markAccepted($claim['attempt'], $result);
                } else {
                    ChannelOperationLedger::markRetryable(
                        $claim['attempt'],
                        'Sebagian package TikTok belum diterima; hanya package yang tersisa akan dicoba ulang.',
                        $result,
                    );
                }

                $order->update([
                    'driver_call_status' => $allPackagesShipped && $hasTracking ? 'success' : 'pending',
                    'driver_call_message' => $allPackagesShipped && $hasTracking
                        ? null
                        : ($hasTracking
                            ? 'Sebagian package TikTok sudah diterima. Sistem sedang menyelesaikan package yang tersisa.'
                            : 'Permintaan TikTok diterima, tetapi tracking number belum tersedia. Sistem sedang menunggu resi.'),
                    'driver_call_response' => $result,
                ]);

                RequestChannelAwbJob::dispatch(
                    $order->id,
                    $allPackagesShipped ? 1 : 0,
                    ! $allPackagesShipped,
                    false,
                    $allPackagesShipped,
                )->afterCommit();
                $order->refresh();

                return true;
            }

            if (! empty($result['deferred'])) {
                ChannelOperationLedger::markRetryable(
                    $claim['attempt'],
                    (string) ($result['message'] ?? 'Status TikTok belum dapat diverifikasi.'),
                    $result,
                );
                $order->update([
                    'driver_call_status' => 'pending',
                    'driver_call_message' => $this->friendly('tiktok', (string) $result['message']),
                    'driver_call_response' => $result,
                ]);
                CallTikTokDriverJob::dispatch($order->id)->delay(now()->addMinute());
                $order->refresh();

                return true;
            }

            $msg = (string) ($result['message'] ?? 'Panggilan driver TikTok gagal tanpa keterangan.');
            ChannelOperationLedger::markRetryable($claim['attempt'], $msg, $result);
            $order->update([
                'driver_call_status' => 'failed',
                'driver_call_message' => $this->friendly('tiktok', $msg),
                'driver_call_response' => $result,
            ]);
        } catch (\Throwable $e) {
            ChannelOperationLedger::markUncertain($claim['attempt'], $e);
            $order->update([
                'driver_call_status' => 'failed',
                'driver_call_message' => $this->friendly('tiktok', $e->getMessage()),
                'driver_call_response' => ['exception' => $e->getMessage(), 'class' => get_class($e)],
            ]);
        }

        $order->refresh();

        return false;
    }

    private function callLazada(SalesOrder $order): bool
    {
        $shopId = (string) $order->channel_shop_id;
        $channelOrderNo = (string) $order->channel_order_no;
        $shippingProvider = (string) ($order->channel_shipping_provider_code ?? $order->shipping_provider ?? '');

        $deliveryType = 'dropship';
        $trackingNumber = $order->tracking_number ?: null;

        if ($shippingProvider === '') {
            $order->update([
                'driver_call_status' => 'failed',
                'driver_call_message' => 'Lazada: shipping_provider order kosong.',
                'driver_call_attempted_at' => now(),
            ]);
            $order->refresh();

            return false;
        }

        if ($deliveryType === 'dropship' && $trackingNumber === null) {
            $order->update([
                'driver_call_status' => 'failed',
                'driver_call_message' => 'Lazada dropship: nomor resi (tracking_number) belum ada. Ambil resi lebih dulu sebelum panggil driver.',
                'driver_call_attempted_at' => now(),
            ]);
            $order->refresh();

            return false;
        }

        $order->update([
            'driver_call_status' => 'pending',
            'driver_call_attempted_at' => now(),
        ]);

        try {
            ProcessLazadaFulfillmentJob::dispatch(
                $shopId,
                $channelOrderNo,
                $shippingProvider,
                $deliveryType,
                $trackingNumber,
                null,
            )->afterCommit();

            $order->update([
                'driver_call_status' => 'pending',
                'driver_call_message' => 'Shipping label sudah siap. Panggilan driver Lazada sedang diproses.',
                'driver_call_response' => ['queued' => true, 'pipeline' => 'lazada_fulfillment'],
            ]);
            $order->refresh();

            return true;
        } catch (\Throwable $e) {
            $order->update([
                'driver_call_status' => 'failed',
                'driver_call_message' => $this->friendly('lazada', $e->getMessage()),
                'driver_call_response' => ['exception' => $e->getMessage(), 'class' => get_class($e)],
            ]);
            $order->refresh();

            return false;
        }
    }

    private function markUnsupported(SalesOrder $order, string $message): bool
    {
        $order->update([
            'driver_call_status' => 'failed',
            'driver_call_message' => mb_substr($message, 0, 500),
            'driver_call_attempted_at' => now(),
        ]);

        return false;
    }
}
