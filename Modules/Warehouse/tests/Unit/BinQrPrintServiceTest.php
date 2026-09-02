<?php

namespace Modules\Warehouse\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Warehouse\Models\QrPrintJob;
use Modules\Warehouse\Services\BinQrPrintService;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class BinQrPrintServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ready_qr_pdf_is_downloaded_as_a_remote_storage_stream(): void
    {
        $jobId = '74d53dd9-56bd-439b-8a8e-501479d34929';
        $path = BinQrPrintService::storagePathFor($jobId);
        $expectedResponse = new StreamedResponse;

        QrPrintJob::create([
            'id' => $jobId,
            'location_id' => '019f9932-eda5-7055-b4e8-e9909d1df3d4',
            'status' => QrPrintJob::STATUS_READY,
            'paper' => 'thermal_50x40',
            'total_bins' => 1,
            'processed_bins' => 1,
            'file_path' => $path,
        ]);

        $disk = \Mockery::mock('Illuminate\Contracts\Filesystem\Filesystem');
        $disk->shouldReceive('exists')->once()->with($path)->andReturnTrue();
        $disk->shouldReceive('path')->never();
        $disk->shouldReceive('download')->once()->with(
            $path,
            'qr-rak-74d53dd9.pdf',
            ['Content-Type' => 'application/pdf'],
        )->andReturn($expectedResponse);

        Storage::shouldReceive('disk')
            ->once()
            ->with(BinQrPrintService::STORAGE_DISK)
            ->andReturn($disk);

        $response = app(BinQrPrintService::class)->downloadJobPdf($jobId);

        $this->assertSame($expectedResponse, $response);
    }
}
