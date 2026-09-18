<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class PdfRenderer
{
    public function bytes(string $view, array $data = [], string $paper = 'a4', string $orientation = 'portrait'): string
    {
        $gotenbergPdf = $this->renderViaGotenberg($view, $data, $paper, $orientation);
        if ($gotenbergPdf !== null) {
            return $gotenbergPdf;
        }

        return $this->make($view, $data, $paper, $orientation)->output();
    }

    public function save(string $view, array $data, string $path, string $paper = 'a4', string $orientation = 'portrait'): void
    {
        $bytes = $this->bytes($view, $data, $paper, $orientation);
        file_put_contents($path, $bytes);
    }

    public function stream(string $view, array $data, string $filename, string $paper = 'a4', string $orientation = 'portrait'): Response
    {
        $bytes = $this->bytes($view, $data, $paper, $orientation);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    public function download(string $view, array $data, string $filename, string $paper = 'a4', string $orientation = 'portrait'): Response
    {
        $bytes = $this->bytes($view, $data, $paper, $orientation);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function response(string $view, array $data, string $filename, bool $download = false, string $paper = 'a4', string $orientation = 'portrait'): Response
    {
        return $download
            ? $this->download($view, $data, $filename, $paper, $orientation)
            : $this->stream($view, $data, $filename, $paper, $orientation);
    }

    protected function renderViaGotenberg(string $view, array $data, string $paper, string $orientation): ?string
    {
        $gotenbergUrl = env('GOTENBERG_URL');
        if (! $gotenbergUrl) {
            return null;
        }

        try {
            $html = View::make($view, $data)->render();
            $isLandscape = strtolower($orientation) === 'landscape';
            $printDate = now()->timezone('Asia/Jakarta')->format('d M Y H:i');

            $footerHtml = <<<HTML
<!DOCTYPE html>
<html>
<head>
<style>
  body {
    margin: 0;
    padding: 0 12mm;
    width: 100%;
    box-sizing: border-box;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 9px;
    color: #555555;
    -webkit-print-color-adjust: exact;
  }
  table { width: 100%; border-collapse: collapse; }
  td { padding: 0; vertical-align: middle; }
  .right { text-align: right; }
</style>
</head>
<body>
  <table>
    <tr>
      <td>Tgl. Cetak: {$printDate}</td>
      <td class="right">Hal: <span class="pageNumber"></span> / <span class="totalPages"></span></td>
    </tr>
  </table>
</body>
</html>
HTML;

            $response = Http::timeout(60)
                ->attach('files', $html, 'index.html')
                ->attach('files', $footerHtml, 'footer.html')
                ->post(rtrim($gotenbergUrl, '/') . '/forms/chromium/convert/html', [
                    'landscape' => $isLandscape ? 'true' : 'false',
                    'printBackground' => 'true',
                    'marginTop' => '0.47', // 12mm
                    'marginBottom' => '0.6', // 15mm
                    'marginLeft' => '0.47', // 12mm
                    'marginRight' => '0.47', // 12mm
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

    protected function make(string $view, array $data, string $paper, string $orientation)
    {
        return Pdf::loadView($view, $data)->setPaper($paper, $orientation);
    }
}
