<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use RuntimeException;
use setasign\Fpdi\Fpdi;
use Symfony\Component\Process\Process;

final class MarketplaceLabelPdfSplitter
{
    /** Split only after every page has exactly one verifiable order identity. */
    public function split(string $bytes, array $identities): array
    {
        if ($identities === [] || count($identities) > 50 || ! str_starts_with($bytes, '%PDF-')
            || strlen($bytes) > (int) config('bulk-labels.mass_download_max_bytes', 16 * 1024 * 1024)) {
            throw new RuntimeException('Dokumen massal tidak memenuhi batas aman PDF.');
        }
        $path = tempnam(sys_get_temp_dir(), 'verified-label-');
        if ($path === false) {
            throw new RuntimeException('Penyimpanan sementara label tidak tersedia.');
        }
        try {
            if (file_put_contents($path, $bytes) !== strlen($bytes)) {
                throw new RuntimeException('Dokumen massal tidak tersimpan lengkap.');
            }
            unset($bytes);
            $this->guardMemory();
            $reader = new Fpdi;
            $pageCount = $reader->setSourceFile($path);
            if ($pageCount < count($identities) || $pageCount > (int) config('bulk-labels.mass_download_max_pages', 100)) {
                throw new RuntimeException('Jumlah halaman dokumen massal tidak sesuai.');
            }
            $command = ['pdftotext', '-layout', '-enc', 'UTF-8', $path, '-'];
            if (PHP_OS_FAMILY === 'Linux') {
                // Native parsing shares the pod budget. Bound its address space and CPU,
                // independently from PHP's memory_limit; all arguments remain positional.
                $command = ['sh', '-c', 'ulimit -v 131072 || exit 70; ulimit -t 10 || exit 70; exec "$@"', 'label-text', ...$command];
            }
            $process = new Process($command);
            $process->setTimeout(15);
            $text = '';
            $process->run(function ($type, $buffer) use (&$text): void {
                if ($type === Process::OUT) {
                    $text .= $buffer;
                    if (strlen($text) > 2 * 1024 * 1024) {
                        throw new RuntimeException('Teks dokumen massal melebihi batas aman.');
                    }
                }
            });
            if (! $process->isSuccessful()) {
                throw new RuntimeException('Identitas label massal tidak dapat diperiksa.');
            }
            $pages = explode("\f", $text);
            if (trim((string) end($pages)) === '') {
                array_pop($pages);
            }
            if (count($pages) !== $pageCount) {
                throw new RuntimeException('Pemetaan halaman dokumen massal tidak lengkap.');
            }
            $mapping = [];
            foreach ($pages as $index => $page) {
                $matches = [];
                foreach ($identities as $id => $tokens) {
                    foreach (array_unique($tokens) as $token) {
                        $token = trim((string) $token);
                        if (strlen($token) >= 6 && preg_match('/(?<![A-Za-z0-9])'.preg_quote($token, '/').'(?![A-Za-z0-9])/i', $page)) {
                            $matches[$id] = true;
                        }
                    }
                }
                if (count($matches) !== 1) {
                    throw new RuntimeException('Identitas halaman label ambigu; gunakan unduhan per pesanan.');
                }
                $mapping[array_key_first($matches)][] = $index + 1;
            }
            if (count($mapping) !== count($identities)) {
                throw new RuntimeException('Sebagian pesanan tidak ditemukan dalam PDF massal.');
            }
            $result = [];
            $total = 0;
            foreach ($mapping as $id => $pageNumbers) {
                $this->guardMemory();
                $pdf = new Fpdi;
                $pdf->setSourceFile($path);
                foreach ($pageNumbers as $page) {
                    $this->guardMemory();
                    $template = $pdf->importPage($page);
                    $size = $pdf->getTemplateSize($template);
                    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $pdf->useTemplate($template);
                }
                $result[$id] = $pdf->Output('S');
                $total += strlen($result[$id]);
                if ($total > (int) config('bulk-labels.mass_download_max_bytes', 16 * 1024 * 1024)) {
                    throw new RuntimeException('Hasil pemisahan label terlalu besar.');
                }
            }

            return $result;
        } finally {
            @unlink($path);
        }
    }

    private function guardMemory(): void
    {
        $limit = ini_parse_quantity((string) ini_get('memory_limit'));
        if (memory_get_usage(true) > ($limit > 0 ? min(160 * 1024 * 1024, $limit - 48 * 1024 * 1024) : 160 * 1024 * 1024)) {
            throw new RuntimeException('Pemisahan label mencapai batas memori aman.');
        }
    }
}
