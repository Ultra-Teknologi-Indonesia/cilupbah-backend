<?php

namespace App\Services;

use App\Exceptions\PdfRenderException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class PdfRenderer
{
    public function bytes(string $view, array $data = [], string|array $paper = 'a4', string $orientation = 'portrait'): string
    {
        return $this->renderViaGotenberg($view, $data, $paper, $orientation);
    }

    public function save(string $view, array $data, string $path, string|array $paper = 'a4', string $orientation = 'portrait'): void
    {
        $bytes = $this->bytes($view, $data, $paper, $orientation);
        file_put_contents($path, $bytes);
    }

    public function stream(string $view, array $data, string $filename, string|array $paper = 'a4', string $orientation = 'portrait'): Response
    {
        $bytes = $this->bytes($view, $data, $paper, $orientation);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    public function download(string $view, array $data, string $filename, string|array $paper = 'a4', string $orientation = 'portrait'): Response
    {
        $bytes = $this->bytes($view, $data, $paper, $orientation);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function response(string $view, array $data, string $filename, bool $download = false, string|array $paper = 'a4', string $orientation = 'portrait'): Response
    {
        return $download
            ? $this->download($view, $data, $filename, $paper, $orientation)
            : $this->stream($view, $data, $filename, $paper, $orientation);
    }

    protected function renderViaGotenberg(string $view, array $data, string|array $paper, string $orientation): string
    {
        $gotenbergUrl = rtrim((string) config('pdf.gotenberg.url'), '/');
        if ($gotenbergUrl === '') {
            throw new PdfRenderException('Layanan PDF belum dikonfigurasi.');
        }

        try {
            $html = View::make($view, $data)->render();
            $isLandscape = strtolower($orientation) === 'landscape';
            $pageSize = $this->gotenbergPageSize($paper);

            $html = $this->withGotenbergPageSize($html, $pageSize);

            $response = Http::connectTimeout((int) config('pdf.gotenberg.connect_timeout_seconds', 5))
                ->timeout((int) config('pdf.gotenberg.timeout_seconds', 60))
                ->attach('files', $html, 'index.html')
                ->post(rtrim($gotenbergUrl, '/').'/forms/chromium/convert/html', [
                    'paperWidth' => $pageSize['width'],
                    'paperHeight' => $pageSize['height'],
                    'landscape' => $isLandscape ? 'true' : 'false',
                    'preferCssPageSize' => 'true',
                    'printBackground' => 'true',
                ]);

            if ($response->successful() && $response->body() !== '') {
                return $response->body();
            }

            Log::warning('Gotenberg conversion failed.', [
                'status' => $response->status(),
                'response' => substr($response->body(), 0, 500),
            ]);

            throw new PdfRenderException('Layanan PDF sedang sibuk. Silakan coba lagi.');
        } catch (\Throwable $e) {
            if ($e instanceof PdfRenderException) {
                throw $e;
            }

            Log::warning('Gotenberg is unreachable.', [
                'exception' => $e->getMessage(),
            ]);

            throw new PdfRenderException('Layanan PDF tidak dapat dihubungi. Silakan coba lagi.', previous: $e);
        }
    }

    protected function gotenbergPageSize(string|array $paper): array
    {
        if (is_array($paper) && count($paper) >= 4) {
            $width = (float) ($paper[2] ?? 0);
            $height = (float) ($paper[3] ?? 0);

            if ($width > 0 && $height > 0) {
                return [
                    'width' => $this->pointsToMillimetres($width),
                    'height' => $this->pointsToMillimetres($height),
                ];
            }
        }

        $sizes = [
            'a0' => [841.0, 1189.0],
            'a1' => [594.0, 841.0],
            'a2' => [420.0, 594.0],
            'a3' => [297.0, 420.0],
            'a4' => [210.0, 297.0],
            'a5' => [148.0, 210.0],
            'a6' => [105.0, 148.0],
            'letter' => [215.9, 279.4],
            'legal' => [215.9, 355.6],
            'tabloid' => [279.4, 431.8],
            'ledger' => [431.8, 279.4],
        ];

        [$width, $height] = $sizes[strtolower((string) $paper)] ?? $sizes['a4'];

        return [
            'width' => $this->millimetres($width),
            'height' => $this->millimetres($height),
        ];
    }

    protected function withGotenbergPageSize(string $html, array $pageSize): string
    {
        $style = sprintf(
            '<style data-cilupbah-page-size="true">@page { size: %s %s; }</style>',
            $pageSize['width'],
            $pageSize['height'],
        );

        $updated = preg_replace('/<\/head>/i', $style.'</head>', $html, 1);

        return is_string($updated) ? $updated : $style.$html;
    }

    protected function pointsToMillimetres(float $points): string
    {
        return $this->millimetres($points * 25.4 / 72);
    }

    protected function millimetres(float $millimetres): string
    {
        return number_format($millimetres, 3, '.', '').'mm';
    }
}
