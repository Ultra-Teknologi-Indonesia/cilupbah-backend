<?php

namespace Modules\Report\Tests\Unit;

use Mockery;
use Modules\Report\Repositories\ReportRepository;
use Modules\Report\Services\ShipmentByCourierReportService;
use Tests\TestCase;

class ShipmentByCourierReportServiceTest extends TestCase
{
    public function test_summary_separates_regular_spx_from_instant_sameday_spx(): void
    {
        $service = $this->serviceWithRows([
            (object) ['provider' => 'SPX', 'no_resi' => 'SPX-REG-1', 'shipment_type' => 'REGULAR', 'qty' => 1],
            (object) ['provider' => 'SPX', 'no_resi' => 'SPX-REG-2', 'shipment_type' => 'REGULAR', 'qty' => 1],
            (object) ['provider' => 'SPX Sameday', 'no_resi' => 'SPX-INST-1', 'shipment_type' => 'INSTANT', 'qty' => 1],
        ]);

        $report = $service->sectioned(false, ['from' => '2026-09-01', 'to' => '2026-09-02']);
        $summary = collect($report->rows)
            ->where('type', 'data')
            ->map(fn (array $row) => $row['cells'])
            ->values()
            ->all();

        $this->assertSame([
            ['Instan/Sameday', 1, 1],
            ['SPX', 2, 2],
        ], $summary);
    }

    public function test_pdf_payload_uses_the_same_split_for_spx(): void
    {
        $service = $this->serviceWithRows([
            (object) ['provider' => 'SPX', 'no_resi' => 'SPX-REG-1', 'shipment_type' => 'REGULAR', 'qty' => 2],
            (object) ['provider' => 'SPX Sameday', 'no_resi' => 'SPX-INST-1', 'shipment_type' => 'SAME_DAY', 'qty' => 1],
        ]);

        $payload = $service->pdfPayload(false, ['from' => '2026-09-01', 'to' => '2026-09-02']);

        $this->assertSame([
            ['ekspedisi' => 'Instan/Sameday', 'total_pesanan' => '1', 'total_quantity' => '1'],
            ['ekspedisi' => 'SPX', 'total_pesanan' => '1', 'total_quantity' => '2'],
        ], $payload['data']['summary']);
    }

    public function test_explicit_regular_type_keeps_spx_in_regular_family(): void
    {
        $this->assertSame('SPX', \Modules\Report\Support\EkspedisiNormalizer::family('SPX Sameday', null, 'REGULAR'));
        $this->assertSame('Instan/Sameday', \Modules\Report\Support\EkspedisiNormalizer::family('SPX', null, 'INSTANT'));
    }

    private function serviceWithRows(array $rows): ShipmentByCourierReportService
    {
        $repository = Mockery::mock(ReportRepository::class);
        $repository->shouldReceive('shipmentByCourierRows')->andReturn($rows);

        return new ShipmentByCourierReportService($repository);
    }
}
