<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use setasign\Fpdi\Fpdi;
use Symfony\Component\Process\Process;

final class ChunkedPdfMerger
{
    public function merge(array $ids, callable $renderChunk, string $targetPath, ?int $chunkSize = null): void
    {
        $ids = array_values(array_unique(array_filter($ids, static fn ($id): bool => (string) $id !== '')));
        if ($ids === []) {
            throw new RuntimeException('Tidak ada dokumen yang dipilih.');
        }

        $chunkSize = max(1, $chunkSize ?? (int) config('exports.pdf_chunk_size', 250));
        $chunkPaths = [];

        try {
            foreach (array_chunk($ids, $chunkSize) as $chunkIds) {
                $bytes = $renderChunk($chunkIds);
                if (! is_string($bytes) || $bytes === '') {
                    continue;
                }

                $chunkPath = tempnam(sys_get_temp_dir(), 'cilupbah-pdf-chunk-');
                if ($chunkPath === false) {
                    throw new RuntimeException('Tidak dapat membuat bagian PDF sementara.');
                }

                if (file_put_contents($chunkPath, $bytes) === false) {
                    throw new RuntimeException('Tidak dapat menulis bagian PDF sementara.');
                }
                $chunkPaths[] = $chunkPath;
                unset($bytes);
                gc_collect_cycles();
            }

            if ($chunkPaths === []) {
                throw new RuntimeException('Tidak ada dokumen yang dapat dibuat menjadi PDF.');
            }

            if ($this->canUsePdfUnite()) {
                $this->mergeWithPdfUnite($chunkPaths, $targetPath);
            } else {
                $this->mergeWithFpdi($chunkPaths, $targetPath);
            }
        } finally {
            foreach ($chunkPaths as $chunkPath) {
                @unlink($chunkPath);
            }
            gc_collect_cycles();
        }
    }

    private function canUsePdfUnite(): bool
    {
        $process = new Process(['sh', '-c', 'command -v pdfunite']);
        $process->setTimeout(5);
        $process->run();

        return $process->isSuccessful();
    }

    private function mergeWithPdfUnite(array $chunkPaths, string $targetPath): void
    {
        $process = new Process(array_merge(['pdfunite'], $chunkPaths, [$targetPath]));
        $process->setTimeout((int) config('exports.timeout', 900));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Gagal menggabungkan bagian PDF: '.trim($process->getErrorOutput()));
        }
    }

    private function mergeWithFpdi(array $chunkPaths, string $targetPath): void
    {
        $pdf = new Fpdi;
        foreach ($chunkPaths as $chunkPath) {
            $pages = $pdf->setSourceFile($chunkPath);
            for ($page = 1; $page <= $pages; $page++) {
                $template = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($template);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($template);
            }
        }

        $pdf->Output('F', $targetPath);
    }
}
