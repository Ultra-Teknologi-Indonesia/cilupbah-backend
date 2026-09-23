<?php

declare(strict_types=1);

namespace Modules\Report\Tests\Unit;

use App\Services\PdfRenderer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PdfRendererTest extends TestCase
{
    public function test_gotenberg_receives_the_requested_custom_thermal_page_size(): void
    {
        $previousEnv = $_ENV['GOTENBERG_URL'] ?? null;
        $previousServer = $_SERVER['GOTENBERG_URL'] ?? null;
        $_ENV['GOTENBERG_URL'] = 'http://gotenberg.test';
        $_SERVER['GOTENBERG_URL'] = 'http://gotenberg.test';

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
            if ($previousEnv === null) {
                unset($_ENV['GOTENBERG_URL']);
            } else {
                $_ENV['GOTENBERG_URL'] = $previousEnv;
            }
            if ($previousServer === null) {
                unset($_SERVER['GOTENBERG_URL']);
            } else {
                $_SERVER['GOTENBERG_URL'] = $previousServer;
            }
        }
    }
}
