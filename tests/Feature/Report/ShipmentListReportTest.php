<?php

namespace Tests\Feature\Report;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Outbound\Models\Shipment;
use Modules\Report\Exports\ShipmentListReportExport;
use Modules\Report\Services\ReportService;
use Modules\Sales\Enums\ChannelStatus;
use Modules\Sales\Models\SalesOrder;
use Modules\Warehouse\Models\Location;
use Tests\TestCase;

class ShipmentListReportTest extends TestCase
{
    use RefreshDatabase;

    private Location $gudang;

    private ReportService $service;

    private string $courierSpxId;

    private string $courierJntId;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('owner', 'web'));
        $this->actingAs($user, 'sanctum');

        $this->service = app(ReportService::class);

        $this->gudang = Location::create([
            'location_code' => 'SHP-KECIL', 'location_name' => 'Gudang Kecil',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true,
        ]);

        $this->courierSpxId = $this->makeCourier('SPX', 'SPX Hemat');
        $this->courierJntId = $this->makeCourier('JNT', 'J&T Express Standard');
    }

    private function makeCourier(string $code, string $name): string
    {
        $id = (string) Str::uuid7();
        DB::table('couriers')->insert([
            'id' => $id, 'code' => $code, 'name' => $name, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function makeOrder(array $attrs): SalesOrder
    {
        return SalesOrder::create(array_merge([
            'customer_name' => 'Pembeli',
            'status' => 'SHIPPED',
        ], $attrs));
    }

    private function manifest(SalesOrder $order, string $shipmentNo, string $courierName): Shipment
    {
        $shipment = Shipment::create([
            'shipment_no' => $shipmentNo,
            'location_id' => $this->gudang->id,
            'courier_name' => $courierName,
            'courier_code' => 'SPX',
            'shipment_date' => '2026-07-18',
            'status' => Shipment::STATUS_HANDED_OVER,
            'created_by' => 'tester',
        ]);

        DB::table('shipment_orders')->insert([
            'id' => (string) Str::uuid7(),
            'shipment_id' => $shipment->id,
            'order_id' => $order->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $shipment;
    }

    private function rows(array $filters): array
    {
        return $this->service->shipmentListQuery($filters)->get()->all();
    }

    public function test_export_menghasilkan_sembilan_kolom_sesuai_urutan_jubelio(): void
    {
        $export = new ShipmentListReportExport($this->service, [
            'from' => '2026-07-01', 'to' => '2026-07-31',
        ]);

        $this->assertSame([
            'Nomor', 'No Pesanan', 'No Manifest', 'Tanggal Pesanan', 'Kurir',
            'No Resi', 'Note', 'Status Pesanan', 'Status Channel',
        ], $export->headings());
    }

    public function test_pesanan_belum_dimanifes_tetap_muncul_dengan_manifest_kosong(): void
    {
        $batal = $this->makeOrder([
            'salesorder_no' => 'SP-26071806BY6NAN',
            'transaction_date' => '2026-07-18 00:00:44',
            'shipping_provider' => 'Hemat Kargo',
            'status' => 'CANCELED',
            'channel_status' => ChannelStatus::CANCELLED->value,
        ]);

        $terkirim = $this->makeOrder([
            'salesorder_no' => 'SP-26071806C4VCU6',
            'transaction_date' => '2026-07-18 00:00:50',
            'shipping_provider' => 'SPX Hemat',
            'tracking_number' => 'SPXID067109318917',
            'status' => 'COMPLETED',
            'channel_status' => ChannelStatus::COMPLETED->value,
        ]);
        $this->manifest($terkirim, 'SPX 18-07-2026', 'SPX Hemat');

        $rows = $this->rows(['from' => '2026-07-18', 'to' => '2026-07-18']);

        $this->assertCount(2, $rows);
        $byOrder = collect($rows)->keyBy('salesorder_no');

        $this->assertNull($byOrder['SP-26071806BY6NAN']->shipment_no);
        $this->assertSame('Hemat Kargo', $byOrder['SP-26071806BY6NAN']->courier);
        $this->assertNull($byOrder['SP-26071806BY6NAN']->tracking_number);

        $this->assertSame('SPX 18-07-2026', $byOrder['SP-26071806C4VCU6']->shipment_no);
        $this->assertSame('SPXID067109318917', $byOrder['SP-26071806C4VCU6']->tracking_number);

        unset($batal);
    }

    public function test_status_pesanan_dan_status_channel_adalah_kolom_berbeda(): void
    {
        $this->makeOrder([
            'salesorder_no' => 'SP-DUA-STATUS',
            'transaction_date' => '2026-07-18 08:00:00',
            'status' => 'PROCESSING',
            'channel_status' => ChannelStatus::READY_TO_SHIP->value,
        ]);

        $rows = $this->rows(['from' => '2026-07-18', 'to' => '2026-07-18']);

        $this->assertSame('PROCESSING', $rows[0]->status);
        $this->assertSame('READY_TO_SHIP', $rows[0]->channel_status);
    }

    public function test_filter_kurir_membatasi_hasil(): void
    {
        $this->makeOrder([
            'salesorder_no' => 'SP-KURIR-SPX',
            'transaction_date' => '2026-07-18 08:00:00',
            'courier_id' => $this->courierSpxId,
            'shipping_provider' => 'SPX Hemat',
        ]);
        $this->makeOrder([
            'salesorder_no' => 'SP-KURIR-JNT',
            'transaction_date' => '2026-07-18 09:00:00',
            'courier_id' => $this->courierJntId,
            'shipping_provider' => 'J&T Express Standard',
        ]);

        $semua = $this->rows(['from' => '2026-07-18', 'to' => '2026-07-18']);
        $hanyaJnt = $this->rows([
            'from' => '2026-07-18', 'to' => '2026-07-18',
            'courier_ids' => [$this->courierJntId],
        ]);

        $this->assertCount(2, $semua);
        $this->assertCount(1, $hanyaJnt);
        $this->assertSame('SP-KURIR-JNT', $hanyaJnt[0]->salesorder_no);
    }

    public function test_filter_status_channel_membatasi_hasil(): void
    {
        $this->makeOrder([
            'salesorder_no' => 'SP-STATUS-SHIPPED',
            'transaction_date' => '2026-07-18 08:00:00',
            'channel_status' => ChannelStatus::SHIPPED->value,
        ]);
        $this->makeOrder([
            'salesorder_no' => 'SP-STATUS-CANCEL',
            'transaction_date' => '2026-07-18 09:00:00',
            'channel_status' => ChannelStatus::CANCELLED->value,
        ]);

        $rows = $this->rows([
            'from' => '2026-07-18', 'to' => '2026-07-18',
            'channel_status' => ChannelStatus::CANCELLED->value,
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('SP-STATUS-CANCEL', $rows[0]->salesorder_no);
    }

    public function test_opsi_status_persis_mengikuti_enum_channel_status(): void
    {
        $options = $this->service->shipmentFilterOptions();

        $this->assertSame(
            array_map(fn (ChannelStatus $c) => $c->value, ChannelStatus::cases()),
            array_column($options['statuses'], 'value'),
        );

        $labels = array_column($options['statuses'], 'label');
        $this->assertContains('Siap Kirim', $labels);
        $this->assertContains('Menunggu Konfirmasi Terima', $labels);
        $this->assertNotContains('', $labels, 'Setiap status wajib punya label Indonesia');
    }

    public function test_opsi_kurir_hanya_yang_aktif_dan_terurut(): void
    {
        $this->makeCourier('NONAKTIF', 'Aaa Kurir Nonaktif');
        DB::table('couriers')->where('code', 'NONAKTIF')->update(['is_active' => false]);

        $options = $this->service->shipmentFilterOptions();
        $labels = array_column($options['couriers'], 'label');

        $this->assertNotContains('Aaa Kurir Nonaktif', $labels);
        $sorted = $labels;
        sort($sorted);
        $this->assertSame($sorted, $labels, 'Kurir harus terurut menurut nama');
    }

    public function test_kolom_nomor_berurut_dan_tanggal_berupa_serial_datetime(): void
    {
        foreach (['SP-A' => '08:00:00', 'SP-B' => '09:00:00', 'SP-C' => '10:00:00'] as $no => $jam) {
            $this->makeOrder([
                'salesorder_no' => $no,
                'transaction_date' => "2026-07-18 {$jam}",
                'channel_status' => ChannelStatus::SHIPPED->value,
            ]);
        }

        $export = new ShipmentListReportExport($this->service, [
            'from' => '2026-07-18', 'to' => '2026-07-18',
        ]);

        \Maatwebsite\Excel\Facades\Excel::store($export, 'test-pengiriman.xlsx', 'local');
        $path = \Illuminate\Support\Facades\Storage::disk('local')->path('test-pengiriman.xlsx');
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();

        $this->assertSame(1, $sheet->getCell('A2')->getValue());
        $this->assertSame(2, $sheet->getCell('A3')->getValue());
        $this->assertSame(3, $sheet->getCell('A4')->getValue());

        $tanggal = $sheet->getCell('D2')->getValue();
        $this->assertIsFloat($tanggal, 'Tanggal Pesanan harus serial Excel, bukan teks');
        $this->assertSame(
            '2026-07-18 08:00',
            \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($tanggal)->format('Y-m-d H:i'),
        );

        $this->assertSame('Dikirim', $sheet->getCell('I2')->getValue(), 'Status Channel dilabeli Indonesia');

        @unlink($path);
    }

    public function test_endpoint_export_dan_validasinya(): void
    {
        $this->get('/api/v1/reports/wms/shipment/export?from=2026-07-01&to=2026-07-31')
            ->assertOk();

        $this->getJson('/api/v1/reports/wms/shipment/export?from=2026-07-31&to=2026-07-01')
            ->assertStatus(422);

        $this->getJson('/api/v1/reports/wms/shipment/export?from=2026-07-01&to=2026-07-31&channel_status=NGARANG')
            ->assertStatus(422);
    }

    public function test_endpoint_options_mengembalikan_kurir_dan_status(): void
    {
        $response = $this->getJson('/api/v1/reports/wms/shipment/options')->assertOk();

        $this->assertCount(count(ChannelStatus::cases()), $response->json('data.statuses'));
        $this->assertCount(2, $response->json('data.couriers'));
    }
}
