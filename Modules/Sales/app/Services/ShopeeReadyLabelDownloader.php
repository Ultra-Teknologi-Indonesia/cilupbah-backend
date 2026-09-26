<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\Cache;
use Modules\Channel\Exceptions\ShopeeApiException;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Sales\Exceptions\ShippingLabelPreparingException;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Repositories\BulkShippingLabelRequestRepository;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

/** Downloads an already prepared document; never arranges shipping or pickup. */
final class ShopeeReadyLabelDownloader
{
    public function __construct(private readonly ShopeeOrderService $shopee) {}

    public function download(SalesOrder $order, string $type): string
    {
        $packages = array_values(array_unique(array_filter(array_map('strval', (array) $order->channel_package_ids))));
        if ($packages === [] && filled($order->package_number)) {
            $packages = [(string) $order->package_number];
        }
        // Never silently select the first package of a split order.
        if (count($packages) > 50) {
            throw new RuntimeException('Pesanan memiliki lebih dari 50 paket. Unduh dokumen paket melalui Seller Center; dokumen sebagian tidak diterbitkan.');
        }
        try {
            if (count($packages) > 1) {
                $result = $this->shopee->downloadShippingDocumentsMass((string) $order->channel_shop_id,
                    array_map(fn ($package) => ['order_sn' => (string) $order->channel_order_no, 'package_number' => $package], $packages), $type);
                $download = $result['batches'][0] ?? [];
                if (! empty($result['error']) || count($result['batches'] ?? []) !== 1) {
                    throw new ShippingLabelPreparingException('Label seluruh paket Shopee belum tersedia. Coba lagi sebentar.');
                }
            } else {
                $download = $this->shopee->downloadShippingDocument((string) $order->channel_shop_id,
                    (string) $order->channel_order_no, $type, $order->tracking_number, $packages[0] ?? null);
            }
        } catch (ShopeeApiException $exception) {
            if (str_replace('.', '_', $exception->errorCode) === 'logistics_shipping_document_should_print_first') {
                // Explicit channel evidence permits renewing the document, not re-shipping.
                $lock = Cache::lock('shipping-label:prepare:'.$order->id, 240);
                if ($lock->get()) {
                    try {
                        app(BulkShippingLabelRequestRepository::class)->invalidateShopeeDocument($order);
                        app(ShopeeShippingDocumentTypeCache::class)->forget($order);
                    } finally {
                        $lock->release();
                    }
                    app(ShippingLabelPreparationDispatcher::class)->dispatch($order->refresh());
                }
                throw new ShippingLabelPreparingException('Dokumen label perlu disiapkan kembali oleh Shopee. Nomor resi dan pengiriman tidak diminta ulang.');
            }
            throw $exception;
        }
        $bytes = (string) ($download['content'] ?? '');
        if (empty($download['binary']) || ! str_starts_with($bytes, '%PDF-')
            || strlen($bytes) > (int) config('bulk-labels.mass_download_max_bytes', 16 * 1024 * 1024)) {
            throw new ShippingLabelPreparingException('Dokumen PDF Shopee belum tersedia atau tidak lengkap. Coba lagi sebentar.');
        }
        if (count($packages) > 1) {
            $pdf = new Fpdi;
            if ($pdf->setSourceFile(StreamReader::createByString($bytes)) < count($packages)) {
                throw new ShippingLabelPreparingException('Label sebagian paket Shopee belum tersedia. Dokumen sebagian tidak diterbitkan.');
            }
        }

        return $bytes;
    }
}
