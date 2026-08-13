<?php

namespace Modules\Outbound\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Outbound\Services\OutboundFulfillmentService;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class StageAwbFilterTest extends TestCase
{
    use RefreshDatabase;

    private function seedReadyToProcess(?string $tracking): SalesOrder
    {
        return SalesOrder::factory()->create([
            'status' => 'reserved',
            'handed_to_warehouse_at' => now(),
            'tracking_number' => $tracking,
        ]);
    }

    private function idsWithFilter(?string $awb): array
    {
        request()->replace($awb === null ? [] : ['filter' => ['awb' => $awb]]);

        $result = app(OutboundFulfillmentService::class)->getOrdersByStage('ready-to-process', 50);

        return collect($result->items())->pluck('id')->sort()->values()->all();
    }

    public function test_menyaring_pesanan_yang_belum_punya_resi(): void
    {
        $belum = $this->seedReadyToProcess(null);
        $kosong = $this->seedReadyToProcess('');
        $this->seedReadyToProcess('JP1234567890');

        $this->assertSame(
            collect([$belum->id, $kosong->id])->sort()->values()->all(),
            $this->idsWithFilter('no'),
            'Operator perlu menyapu pesanan yang resinya belum ditarik. Kolom kosong dan '
                .'NULL harus sama-sama terhitung belum ada.',
        );
    }

    public function test_menyaring_pesanan_yang_sudah_punya_resi(): void
    {
        $this->seedReadyToProcess(null);
        $sudah = $this->seedReadyToProcess('JP1234567890');

        $this->assertSame([$sudah->id], $this->idsWithFilter('yes'));
    }

    public function test_tanpa_filter_semua_pesanan_tetap_muncul(): void
    {
        $this->seedReadyToProcess(null);
        $this->seedReadyToProcess('JP1234567890');

        $this->assertCount(2, $this->idsWithFilter(null));
    }
}
