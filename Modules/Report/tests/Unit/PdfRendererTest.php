<?php

declare(strict_types=1);

namespace Modules\Report\Tests\Unit;

use App\Exceptions\PdfRenderException;
use App\Services\PdfRenderer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PdfRendererTest extends TestCase
{
    public function test_gotenberg_receives_the_requested_custom_thermal_page_size(): void
    {
        $previousUrl = config('pdf.gotenberg.url');
        config(['pdf.gotenberg.url' => 'http://gotenberg.test']);

        try {
            $requestBody = '';
            Http::fake(function (Request $request) use (&$requestBody) {
                $requestBody = $request->body();

                return Http::response('%PDF-1.4 fake', 200);
            });

            $pdf = app(PdfRenderer::class)->bytes(
                'report::pdf.barcode',
                [
                    'cells' => [],
                    'mode' => 'tanpa_harga',
                    'paper' => 'thermal_50x50',
                ],
                [0, 0, 141.7, 141.7],
                'portrait',
            );

            $this->assertSame('%PDF-1.4 fake', $pdf);
            $this->assertStringContainsString('paperWidth', $requestBody);
            $this->assertStringContainsString('paperHeight', $requestBody);
            $this->assertStringContainsString('49.989mm 49.989mm', $requestBody);
            Http::assertSentCount(1);
        } finally {
            config(['pdf.gotenberg.url' => $previousUrl]);
        }
    }

    public function test_pdf_rendering_never_falls_back_to_dompdf_when_gotenberg_is_unavailable(): void
    {
        $previousUrl = config('pdf.gotenberg.url');
        config(['pdf.gotenberg.url' => null]);

        try {
            $this->expectException(PdfRenderException::class);
            $this->expectExceptionMessage('belum dikonfigurasi');

            app(PdfRenderer::class)->bytes('report::pdf.barcode', [
                'cells' => [],
                'mode' => 'tanpa_harga',
                'paper' => 'thermal_50x50',
            ]);
        } finally {
            config(['pdf.gotenberg.url' => $previousUrl]);
        }
    }
}
