<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use App\Services\ChunkedPdfMerger;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Modules\Sales\Repositories\SalesOrderRepository;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Throwable;

final class BulkInvoiceService
{
    public function __construct(
        private readonly SalesOrderRepository $salesOrderRepository,
        private readonly ChunkedPdfMerger $merger,
    ) {}

    public function write(array $orderIds, string $targetPath): void
    {
        $this->merger->merge($orderIds, function (array $chunkIds): string {
            $orders = $this->salesOrderRepository->getForBulkInvoice($chunkIds);
            if ($orders->isEmpty()) {
                return '';
            }

            $pdf = new Fpdi;
            $rendered = 0;

            foreach ($orders as $order) {
                try {
                    $bytes = $this->renderOrder($order);
                    $pageCount = $pdf->setSourceFile(StreamReader::createByString($bytes));

                    for ($page = 1; $page <= $pageCount; $page++) {
                        $template = $pdf->importPage($page);
                        $size = $pdf->getTemplateSize($template);
                        $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                        $pdf->useTemplate($template);
                    }

                    $rendered++;
                } catch (Throwable $exception) {
                    Log::warning('Bulk invoice render failed for order', [
                        'order_id' => $order->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            return $rendered > 0 ? $pdf->Output('S') : '';
        }, $targetPath);
    }

    public function assertOrdersAccessible(array $orderIds): void
    {
        $this->salesOrderRepository->assertManyAccessible($orderIds);
    }

    public function render(array $orderIds): ?array
    {
        $orders = $this->salesOrderRepository->getForBulkInvoice($orderIds);

        if ($orders->isEmpty()) {
            return null;
        }

        $pdf = new Fpdi;
        $rendered = 0;

        foreach ($orders as $order) {
            try {
                $bytes = $this->renderOrder($order);

                $pageCount = $pdf->setSourceFile(StreamReader::createByString($bytes));
                for ($page = 1; $page <= $pageCount; $page++) {
                    $template = $pdf->importPage($page);
                    $size = $pdf->getTemplateSize($template);
                    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $pdf->useTemplate($template);
                }

                $rendered++;
            } catch (Throwable $exception) {
                Log::warning('Bulk invoice render failed for order', [
                    'order_id' => $order->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($rendered === 0) {
            return [
                'content' => '',
                'rendered' => 0,
                'total' => $orders->count(),
            ];
        }

        return [
            'content' => $pdf->Output('S'),
            'rendered' => $rendered,
            'total' => $orders->count(),
        ];
    }

    private function renderOrder(object $order): string
    {
        $order->shipping = (object) [
            'full_name' => $order->shipping_full_name,
            'phone' => $order->shipping_phone,
            'address' => $order->shipping_address,
            'city' => $order->shipping_city,
            'province' => $order->shipping_province,
            'post_code' => $order->shipping_post_code,
        ];

        return Pdf::loadView('sales::pdf.invoice', ['order' => $order])
            ->setPaper('a4', 'portrait')
            ->output();
    }
}
