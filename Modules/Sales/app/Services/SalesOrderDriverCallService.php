<?php

namespace Modules\Sales\Services;

use App\Exceptions\UserFacingException;
use Modules\Channel\Jobs\ProcessLazadaFulfillmentJob;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Outbound\Support\InstantOrderClassifier;
use Modules\Sales\Exceptions\ShippingLabelPreparingException;
use Modules\Sales\Jobs\CallLazadaDriverJob;
use Modules\Sales\Jobs\CallShopeeDriverJob;
use Modules\Sales\Jobs\CallTikTokDriverJob;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Repositories\SalesOrderRepository;

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

        if (! InstantOrderClassifier::isInstant($order->shipping_provider, $order->shipping_type)) {
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

        $driverCallSuccess = $this->callDriver($order);

        if (! $driverCallSuccess && ! $forceLabel) {
            throw new UserFacingException(
                'Aksi tidak dapat diproses',
                'Panggilan driver marketplace gagal. Tambahkan ?force_label=1 untuk tetap mencetak label.',
                422,
                [
                    'driver_call_status'       => $order->driver_call_status,
                    'driver_call_message'      => $order->driver_call_message,
                    'driver_call_attempted_at' => optional($order->driver_call_attempted_at)?->toIso8601String(),
                ],
            );
        }

        $options = array_filter([
            'doc_type'      => $query['doc_type'] ?? null,
            'document_type' => $query['document_type'] ?? null,
            'document_size' => $query['document_size'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        try {
            $labelResult = $this->orderService->getShippingLabel($order, $options);
        } catch (ShippingLabelPreparingException $e) {
            return [
                'data' => [
                    'driver_call_status'       => $order->driver_call_status,
                    'driver_call_message'      => $order->driver_call_message,
                    'driver_call_attempted_at' => optional($order->driver_call_attempted_at)?->toIso8601String(),
                    'label'           => null,
                    'label_preparing' => true,
                    'label_message'   => $e->getMessage(),
                ],
                'message' => 'Driver berhasil dipanggil, label masih disiapkan. Coba unduh dalam beberapa detik.',
                'code'    => 202,
            ];
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            throw new UserFacingException(
                'Aksi tidak dapat diproses',
                'Driver berhasil dipanggil namun label gagal diambil.',
                422,
                [
                    'success'                  => $driverCallSuccess,
                    'driver_call_status'       => $order->driver_call_status,
                    'driver_call_message'      => $order->driver_call_message,
                    'driver_call_attempted_at' => optional($order->driver_call_attempted_at)?->toIso8601String(),
                    'label'       => null,
                    'label_error' => $e->getMessage(),
                    'detail'      => $e->getMessage(),
                ],
            );
        }

        return [
            'data' => [
                'driver_call_status'       => $order->driver_call_status,
                'driver_call_message'      => $order->driver_call_message,
                'driver_call_attempted_at' => optional($order->driver_call_attempted_at)?->toIso8601String(),
                'label' => $labelResult,
            ],
            'message' => $driverCallSuccess
                ? 'Driver terpanggil dan label siap diunduh.'
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

        if (! InstantOrderClassifier::isInstant($order->shipping_provider, $order->shipping_type)) {
            throw new UserFacingException(
                'Aksi tidak dapat diproses',
                'Endpoint ini hanya untuk pesanan Instant / Same Day (Shopee / TikTok / Lazada).',
                422,
            );
        }

        $this->retryDriverCall($order);

        return [
            'data'    => ['driver_call_status' => 'pending'],
            'message' => 'Panggilan driver dicoba ulang. Status akan diperbarui dalam beberapa detik.',
            'code'    => 202,
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

        return match ($source) {
            'shopee' => $this->callShopee($order),
            'tiktok' => $this->callTikTok($order),
            'lazada' => $this->callLazada($order),
            default  => $this->markUnsupported($order, "Panggil driver untuk source '{$source}' belum didukung."),
        };
    }

    private function guardCallable(SalesOrder $order): ?string
    {
        $cs = strtoupper((string) $order->channel_status);

        if (in_array($cs, self::NON_CALLABLE_CHANNEL_STATUSES, true)) {
            return match (true) {
                in_array($cs, ['CANCELLED', 'IN_CANCEL'], true)              => 'Pesanan sudah dibatalkan — tidak bisa panggil driver.',
                in_array($cs, ['RETURN_REQUESTED', 'RETURNED'], true)        => 'Pesanan dalam proses retur — tidak bisa panggil driver.',
                in_array($cs, ['SHIPPED', 'TO_CONFIRM_RECEIVE', 'COMPLETED'], true) => 'Pesanan sudah dikirim/selesai — driver tidak perlu dipanggil lagi.',
                default                                                       => "Status '{$cs}' tidak bisa panggil driver.",
            };
        }

        return null;
    }

    public function retryDriverCall(SalesOrder $order): void
    {
        $order->update([
            'driver_call_status'  => 'pending',
            'driver_call_message' => null,
        ]);

        $source = strtolower((string) $order->source);

        match ($source) {
            'shopee' => CallShopeeDriverJob::dispatch($order->id),
            'tiktok' => CallTikTokDriverJob::dispatch($order->id),
            'lazada' => CallLazadaDriverJob::dispatch($order->id),
            default  => $this->markUnsupported($order, "Retry driver untuk source '{$source}' belum didukung."),
        };
    }

    private function callShopee(SalesOrder $order): bool
    {
        $shopId = (string) $order->channel_shop_id;
        $orderSn = (string) $order->channel_order_no;

        $order->update([
            'driver_call_status'       => 'pending',
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
                    'driver_call_status'   => 'success',
                    'driver_call_message'  => null,
                    'driver_call_response' => $result,
                ]);
            } else {
                $driverCallMessage = $error !== '' ? $error : 'ship_order gagal tanpa pesan';
                $order->update([
                    'driver_call_status'   => 'failed',
                    'driver_call_message'  => mb_substr($driverCallMessage, 0, 500),
                    'driver_call_response' => $result,
                ]);
            }
        } catch (\Throwable $e) {
            $order->update([
                'driver_call_status'   => 'failed',
                'driver_call_message'  => mb_substr($e->getMessage(), 0, 500),
                'driver_call_response' => ['exception' => $e->getMessage(), 'class' => get_class($e)],
            ]);
        }

        $order->refresh();

        return $driverCallSuccess;
    }

    private function callTikTok(SalesOrder $order): bool
    {
        $shopId = (string) $order->channel_shop_id;
        $channelOrderNo = (string) $order->channel_order_no;

        $order->update([
            'driver_call_status'       => 'pending',
            'driver_call_attempted_at' => now(),
        ]);

        try {
            $result = $this->tiktok->readyToShip($shopId, $channelOrderNo);
            $shipped = (bool) ($result['shipped'] ?? false);

            if ($shipped) {
                $order->update([
                    'driver_call_status'   => 'success',
                    'driver_call_message'  => null,
                    'driver_call_response' => $result,
                ]);
                $order->refresh();

                return true;
            }

            $msg = (string) ($result['message'] ?? 'TikTok readyToShip gagal tanpa pesan');
            $order->update([
                'driver_call_status'   => 'failed',
                'driver_call_message'  => mb_substr($msg, 0, 500),
                'driver_call_response' => $result,
            ]);
        } catch (\Throwable $e) {
            $order->update([
                'driver_call_status'   => 'failed',
                'driver_call_message'  => mb_substr($e->getMessage(), 0, 500),
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
                'driver_call_status'       => 'failed',
                'driver_call_message'      => 'Lazada: shipping_provider order kosong.',
                'driver_call_attempted_at' => now(),
            ]);
            $order->refresh();

            return false;
        }

        if ($deliveryType === 'dropship' && $trackingNumber === null) {
            $order->update([
                'driver_call_status'       => 'failed',
                'driver_call_message'      => 'Lazada dropship: nomor resi (tracking_number) belum ada. Ambil resi lebih dulu sebelum panggil driver.',
                'driver_call_attempted_at' => now(),
            ]);
            $order->refresh();

            return false;
        }

        $order->update([
            'driver_call_status'       => 'pending',
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
                'driver_call_status'   => 'success',
                'driver_call_message'  => null,
                'driver_call_response' => ['queued' => true, 'pipeline' => 'lazada_fulfillment'],
            ]);
            $order->refresh();

            return true;
        } catch (\Throwable $e) {
            $order->update([
                'driver_call_status'   => 'failed',
                'driver_call_message'  => mb_substr($e->getMessage(), 0, 500),
                'driver_call_response' => ['exception' => $e->getMessage(), 'class' => get_class($e)],
            ]);
            $order->refresh();

            return false;
        }
    }

    private function markUnsupported(SalesOrder $order, string $message): bool
    {
        $order->update([
            'driver_call_status'       => 'failed',
            'driver_call_message'      => mb_substr($message, 0, 500),
            'driver_call_attempted_at' => now(),
        ]);

        return false;
    }
}
