<?php

namespace Modules\Channel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\LazadaOrderService;

/**
 * Jalankan fulfillPack -> printAwb -> readyToShip sebagai satu alur idempoten.
 *
 * Setiap langkah memutuskan sendiri apakah masih perlu dijalankan berdasarkan status
 * item TERBARU (GetOrderItems dipanggil ulang tiap kali) — jadi retry aman: item yang
 * sudah RTS otomatis dilewati, partial-success otomatis dilanjutkan, tidak ada double-ship.
 *
 * Error transient (timeout/5xx/429/token expired) ditangani oleh retry bawaan queue
 * ($tries + $backoff). Error bisnis (item bukan status yang diharapkan, dsb.) membuat
 * step terkait dilewati, bukan di-retry. Setelah $tries habis, job masuk failed_jobs
 * (mekanisme retry manual bawaan Laravel) — dicatat jelas di failed() agar operator tahu
 * order mana yang butuh tindak lanjut RTS manual.
 */
class ProcessLazadaFulfillmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 300];

    public function __construct(
        public string $shopId,
        public string $orderId,
        public string $shippingProviderId,
        public string $deliveryType = 'dropship',
        public ?string $trackingNumber = null,
        public ?string $packageId = null,
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("lazada_fulfillment:{$this->shopId}:{$this->orderId}"))->releaseAfter(60),
        ];
    }

    public function handle(LazadaOrderService $orderService): void
    {
        $statuses = $orderService->itemStatuses($this->shopId, $this->orderId);

        if (array_intersect($statuses, ['pending', 'repacked'])) {
            $orderService->fulfillPack($this->shopId, $this->orderId, $this->shippingProviderId, $this->deliveryType);
        } else {
            Log::info("Lazada fulfillment: order {$this->orderId} sudah melewati tahap pack, dilewati.");
        }

        $orderService->printAwb($this->shopId, $this->orderId);

        $statusesAfterPack = $orderService->itemStatuses($this->shopId, $this->orderId);

        if (array_intersect($statusesAfterPack, ['packed'])) {
            $orderService->readyToShip(
                $this->shopId,
                $this->orderId,
                $this->trackingNumber,
                $this->packageId,
                $this->deliveryType
            );
        } else {
            Log::info("Lazada fulfillment: order {$this->orderId} sudah melewati tahap ready-to-ship, dilewati.");
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Lazada fulfillment GAGAL PERMANEN — perlu RTS manual untuk order {$this->orderId} (toko {$this->shopId}): " . $exception->getMessage());
    }
}
