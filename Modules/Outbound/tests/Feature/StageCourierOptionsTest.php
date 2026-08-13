<?php

namespace Modules\Outbound\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Outbound\Services\OutboundFulfillmentService;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class StageCourierOptionsTest extends TestCase
{
    use RefreshDatabase;

    private function seedReadyToProcess(string $shippingProvider): SalesOrder
    {
        return SalesOrder::factory()->create([
            'status' => 'reserved',
            'handed_to_warehouse_at' => now(),
            'shipping_provider' => $shippingProvider,
        ]);
    }

    private function courierOptions(string $stage = 'ready-to-process'): array
    {
        return app(OutboundFulfillmentService::class)->getCourierOptionsByStage($stage);
    }

    public function test_daftar_kurir_diambil_dari_nama_asli_marketplace(): void
    {
        $this->seedReadyToProcess('SPX Hemat');
        $this->seedReadyToProcess('GrabExpress Instant');
        $this->seedReadyToProcess('J&T Express NEXT-DAY DELIVERY');

        $this->assertSame(
            ['GrabExpress Instant', 'J&T Express NEXT-DAY DELIVERY', 'SPX Hemat'],
            $this->courierOptions(),
            'Daftar kurir harus memakai nama apa adanya dari marketplace, bukan master kurir '
                .'yang isinya merek datar (SPX, J&T) dan tidak punya GrabExpress maupun GoSend.',
        );
    }

    public function test_nama_kurir_yang_sama_tidak_muncul_dua_kali(): void
    {
        $this->seedReadyToProcess('SPX Hemat');
        $this->seedReadyToProcess('SPX Hemat');
        $this->seedReadyToProcess('SPX Sameday');

        $this->assertSame(['SPX Hemat', 'SPX Sameday'], $this->courierOptions());
    }

    public function test_hanya_kurir_dari_pesanan_di_tahap_itu_yang_muncul(): void
    {
        $this->seedReadyToProcess('SPX Hemat');

        SalesOrder::factory()->create([
            'status' => 'reserved',
            'handed_to_warehouse_at' => null,
            'shipping_provider' => 'Kurir Tahap Lain',
        ]);

        $this->assertSame(
            ['SPX Hemat'],
            $this->courierOptions(),
            'Operator tidak boleh ditawari kurir yang tidak ada pesanannya di tab yang sedang dibuka.',
        );
    }

    public function test_pesanan_tanpa_kurir_tidak_bikin_opsi_kosong(): void
    {
        $this->seedReadyToProcess('SPX Hemat');
        $this->seedReadyToProcess('');
        SalesOrder::factory()->create([
            'status' => 'reserved',
            'handed_to_warehouse_at' => now(),
            'shipping_provider' => null,
        ]);

        $this->assertSame(['SPX Hemat'], $this->courierOptions());
    }

    public function test_opsi_yang_diberikan_cocok_persis_dengan_filter_exact(): void
    {
        $target = $this->seedReadyToProcess('GoTo Logistics GTL Standard');
        $this->seedReadyToProcess('SPX Hemat');

        $option = $this->courierOptions()[0];

        request()->merge(['filter' => ['shipping_provider' => $option]]);

        $result = app(OutboundFulfillmentService::class)->getOrdersByStage('ready-to-process', 50);
        $ids = collect($result->items())->pluck('id')->all();

        $this->assertSame(
            [$target->id],
            $ids,
            'Nilai dari dropdown harus cocok persis dengan AllowedFilter::exact(shipping_provider). '
                .'Kalau tidak, memilih kurir akan menghasilkan nol pesanan.',
        );
    }
}
