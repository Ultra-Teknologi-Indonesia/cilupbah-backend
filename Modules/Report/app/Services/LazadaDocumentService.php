<?php

namespace Modules\Report\Services;

use App\Exceptions\UserFacingException;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Report\Http\Resources\LazadaDocumentResource;
use Throwable;

class LazadaDocumentService
{
    public function __construct(
        protected ReportService $reportService,
    ) {}

    public function resolve(?string $orderId, ?string $shopId, string $docType): array
    {
        if ($shopId && $orderId) {
            return $this->fromChannel($shopId, $orderId, $docType);
        }

        if ($orderId) {
            return $this->fromLocalOrder($orderId);
        }

        return $this->placeholder();
    }

    protected function fromChannel(string $shopId, string $orderId, string $docType): array
    {
        try {
            $document = app(LazadaOrderService::class)->getDocument($shopId, $orderId, $docType);
        } catch (Throwable $e) {
            throw new UserFacingException(
                'Terjadi kesalahan',
                'Gagal mengambil dokumen Lazada.',
                422,
                ['detail' => $e->getMessage()],
            );
        }

        return [
            'message' => 'Dokumen Lazada berhasil diambil.',
            'payload' => [
                'report_type' => 'lazada_document',
                'generated_at' => now()->toIso8601String(),
                'data' => $document,
            ],
        ];
    }

    protected function fromLocalOrder(string $orderId): array
    {
        $order = $this->reportService->lazadaOrder($orderId);

        return [
            'message' => 'Lazada document berhasil diambil.',
            'payload' => [
                'report_type' => 'lazada_document',
                'generated_at' => now()->toIso8601String(),
                'data' => new LazadaDocumentResource($order),
            ],
        ];
    }

    protected function placeholder(): array
    {
        return [
            'message' => 'Lazada document placeholder.',
            'payload' => [
                'report_type' => 'lazada_document',
                'generated_at' => now()->toIso8601String(),
                'data' => null,
                'message' => 'Integrasi Lazada belum tersedia. Gunakan parameter order_id untuk mengambil data order.',
            ],
        ];
    }
}
