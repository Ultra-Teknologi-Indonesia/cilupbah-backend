<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class PdfRenderer
{
    public function bytes(string $view, array $data = [], string|array $paper = 'a4', string $orientation = 'portrait'): string
    {
        $gotenbergPdf = $this->renderViaGotenberg($view, $data, $paper, $orientation);
        if ($gotenbergPdf !== null) {
            return $gotenbergPdf;
        }

        return $this->make($view, $data, $paper, $orientation)->output();
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
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    public function download(string $view, array $data, string $filename, string|array $paper = 'a4', string $orientation = 'portrait'): Response
    {
        $bytes = $this->bytes($view, $data, $paper, $orientation);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function response(string $view, array $data, string $filename, bool $download = false, string|array $paper = 'a4', string $orientation = 'portrait'): Response
    {
        return $download
            ? $this->download($view, $data, $filename, $paper, $orientation)
            : $this->stream($view, $data, $filename, $paper, $orientation);
    }

    protected function renderViaGotenberg(string $view, array $data, string|array $paper, string $orientation): ?string
    {
        $gotenbergUrl = env('GOTENBERG_URL');
        if (! $gotenbergUrl) {
            return null;
        }

        try {
            $html = View::make($view, $data)->render();
            $isLandscape = strtolower($orientation) === 'landscape';

            $response = Http::timeout(60)
                ->attach('files', $html, 'index.html')
                ->post(rtrim($gotenbergUrl, '/') . '/forms/chromium/convert/html', [
                    'landscape' => $isLandscape ? 'true' : 'false',
                    'preferCssPageSize' => 'true',
                    'printBackground' => 'true',
                ]);

            if ($response->successful()) {
                return $response->body();
            }

            Log::warning('Gotenberg conversion failed, falling back to Dompdf: ' . substr($response->body(), 0, 500));
        } catch (\Throwable $e) {
            Log::warning('Gotenberg unreachable, falling back to Dompdf: ' . $e->getMessage());
        }

        return null;
    }

    protected function make(string $view, array $data, string|array $paper, string $orientation)
    {
        return Pdf::loadView($view, $data)->setPaper($paper, $orientation);
    }
}
