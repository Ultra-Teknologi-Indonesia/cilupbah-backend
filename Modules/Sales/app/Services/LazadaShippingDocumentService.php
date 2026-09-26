<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\Http;
use Modules\Sales\Exceptions\ShippingLabelPreparingException;
use Modules\Webhook\Support\WebhookUrlGuard;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

final class LazadaShippingDocumentService
{
    public function __construct(private readonly ShippingLabelCacheService $cache) {}

    public function collect(string $shopId, array $packageIds, string $docType, callable $fetch): array
    {
        if (strtoupper($docType) !== 'PDF') {
            throw new RuntimeException('Dokumen Lazada multi-batch harus menggunakan PDF.');
        }
        if (count($packageIds) > (int) config('bulk-labels.lazada_document_max_packages', 500)) {
            throw new RuntimeException('Jumlah paket dalam satu dokumen melebihi batas aman. Pisahkan pemilihan; tidak ada paket yang dipotong.');
        }
        $paths = [];
        $totalBytes = $pages = 0;
        $maxBytes = (int) config('bulk-labels.lazada_document_max_bytes', 16 * 1024 * 1024);
        $deadline = microtime(true) + 90;
        $pdf = new Fpdi;
        try {
            foreach (array_chunk($packageIds, 20) as $chunk) {
                if (microtime(true) > $deadline) {
                    throw new ShippingLabelPreparingException('Sebagian dokumen Lazada masih diproses. Bagian yang sudah diambil disimpan untuk percobaan berikutnya.');
                }

                $key = 'shipping-label-cache/lazada-'.hash('sha256', $shopId).'/'.hash('sha256', json_encode([
                    $chunk, $docType, (int) floor(now()->timestamp / 600),
                ], JSON_THROW_ON_ERROR)).'.pdf';
                $bytes = $this->cache->read($key, legacyFallback: false);
                if ($bytes === null) {
                    $document = $fetch($chunk);
                    if (empty($document['file']) && empty($document['pdf_url'])) {
                        throw new ShippingLabelPreparingException('Label Lazada belum tersedia untuk seluruh paket. Dokumen sebagian tidak dicetak sebagai lengkap.');
                    }
                    if (strtoupper((string) ($document['doc_type'] ?? 'PDF')) !== 'PDF') {
                        throw new RuntimeException('Format dokumen Lazada tidak sesuai PDF.');
                    }
                    $bytes = $this->download($document, $maxBytes - $totalBytes);

                    $this->validatePdf($bytes);
                    $this->cache->store($key, $bytes);
                }
                $totalBytes += strlen($bytes);
                if ($totalBytes > $maxBytes) {
                    throw new RuntimeException('Total ukuran label Lazada melebihi batas aman; dokumen belum lengkap.');
                }
                $path = tempnam(sys_get_temp_dir(), 'lazada-label-');
                if ($path === false) {
                    throw new RuntimeException('Penyimpanan sementara dokumen Lazada tidak tersedia.');
                }
                $paths[] = $path;
                if (file_put_contents($path, $bytes) !== strlen($bytes)) {
                    throw new RuntimeException('Dokumen Lazada tidak tersimpan lengkap.');
                }
                unset($bytes);
                $count = $pdf->setSourceFile($path);
                $pages += $count;
                if ($count < 1 || $pages > (int) config('bulk-labels.lazada_document_max_pages', 1000)) {
                    throw new RuntimeException('Jumlah halaman label Lazada kosong atau melebihi batas aman.');
                }
                for ($page = 1; $page <= $count; $page++) {
                    $phpLimit = ini_parse_quantity((string) ini_get('memory_limit'));
                    $safeMemory = $phpLimit > 0 ? min(160 * 1024 * 1024, $phpLimit - 48 * 1024 * 1024) : 160 * 1024 * 1024;
                    if (memory_get_usage(true) > $safeMemory) {
                        throw new RuntimeException('Penggabungan label mencapai batas memori aman. Dokumen sebagian tidak diterbitkan.');
                    }
                    $template = $pdf->importPage($page);
                    $size = $pdf->getTemplateSize($template);
                    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $pdf->useTemplate($template);
                }
            }
            $bytes = $pdf->Output('S');
            if (strlen($bytes) > $maxBytes) {
                throw new RuntimeException('Hasil gabungan label Lazada melebihi batas aman.');
            }

            return ['file' => base64_encode($bytes), 'pdf_url' => null, 'doc_type' => 'PDF', 'package_ids' => $packageIds];
        } finally {
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function download(array $document, int $remaining): string
    {
        if ($remaining <= 0) {
            throw new RuntimeException('Batas ukuran dokumen Lazada tercapai.');
        }
        if (! empty($document['file'])) {
            if (strlen((string) $document['file']) > (int) ceil($remaining * 4 / 3) + 4) {
                throw new RuntimeException('Dokumen Lazada terlalu besar.');
            }
            $bytes = base64_decode((string) $document['file'], true);
            if ($bytes === false || $bytes === '' || strlen($bytes) > $remaining) {
                throw new RuntimeException('Dokumen Lazada kosong atau base64 tidak valid.');
            }

            return $bytes;
        }
        $url = (string) $document['pdf_url'];
        if (parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_USER) !== null
            || ! app(WebhookUrlGuard::class)->isSafe($url)) {
            throw new RuntimeException('Alamat dokumen Lazada harus HTTPS.');
        }
        $response = Http::connectTimeout(5)->timeout(20)->withOptions([
            'allow_redirects' => false,
            'progress' => static function ($downloadTotal, $downloaded) use ($remaining): void {
                if ($downloadTotal > $remaining || $downloaded > $remaining) {
                    throw new RuntimeException('Unduhan label Lazada melebihi batas aman.');
                }
            },
        ])->get($url);
        if (! $response->successful() || strlen($response->body()) > $remaining) {
            throw new RuntimeException('Unduhan label Lazada tidak lengkap.');
        }

        return $response->body();
    }

    private function validatePdf(string $bytes): void
    {
        if (! str_starts_with($bytes, '%PDF-')) {
            throw new RuntimeException('Dokumen Lazada bukan PDF yang valid.');
        }
        $pdf = new Fpdi;
        if ($pdf->setSourceFile(StreamReader::createByString($bytes)) < 1) {
            throw new RuntimeException('Dokumen Lazada tidak memiliki halaman.');
        }
    }
}
