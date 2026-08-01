<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

class PdfRenderer
{
    public function bytes(string $view, array $data = [], string $paper = 'a4', string $orientation = 'portrait'): string
    {
        return $this->make($view, $data, $paper, $orientation)->output();
    }

    public function stream(string $view, array $data, string $filename, string $paper = 'a4', string $orientation = 'portrait'): Response
    {
        $pdf = $this->make($view, $data, $paper, $orientation);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    public function download(string $view, array $data, string $filename, string $paper = 'a4', string $orientation = 'portrait'): Response
    {
        $pdf = $this->make($view, $data, $paper, $orientation);

        return response($pdf->output(), 200, [
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

    protected function make(string $view, array $data, string $paper, string $orientation)
    {
        return Pdf::loadView($view, $data)->setPaper($paper, $orientation);
    }
}
