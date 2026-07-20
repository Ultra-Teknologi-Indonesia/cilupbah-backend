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

    public function test_filter_status_mp_menerjemahkan_status_mentah_ke_nilai_kanonik(): void
    {
        $this->makeOrder([
            'salesorder_no' => 'SP-RTS',
            'transaction_date' => '2026-07-18 08:00:00',
            'source' => 'shopee',
            'channel_status' => ChannelStatus::READY_TO_SHIP->value,
        ]);
        $this->makeOrder([
            'salesorder_no' => 'SP-SHIPPED',
            'transaction_date' => '2026-07-18 09:00:00',
            'source' => 'shopee',
            'channel_status' => ChannelStatus::SHIPPED->value,
        ]);

        $rows = $this->rows([
            'from' => '2026-07-18', 'to' => '2026-07-18',
            'status_mp' => 'shopee::ready_to_ship',
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('SP-RTS', $rows[0]->salesorder_no);
    }

    public function test_filter_status_mp_memisahkan_channel(): void
    {
        $this->makeOrder([
            'salesorder_no' => 'SP-CANCEL',
            'transaction_date' => '2026-07-18 08:00:00',
            'source' => 'shopee',
            'channel_status' => ChannelStatus::CANCELLED->value,
        ]);
        $this->makeOrder([
            'salesorder_no' => 'LZ-CANCEL',
            'transaction_date' => '2026-07-18 09:00:00',
            'source' => 'lazada',
            'channel_status' => ChannelStatus::CANCELLED->value,
        ]);

        $shopee = $this->rows([
            'from' => '2026-07-18', 'to' => '2026-07-18',
            'status_mp' => 'shopee::cancelled',
        ]);
        $lazada = $this->rows([
            'from' => '2026-07-18', 'to' => '2026-07-18',
            'status_mp' => 'lazada::canceled',
        ]);

        $this->assertCount(1, $shopee);
        $this->assertSame('SP-CANCEL', $shopee[0]->salesorder_no);
        $this->assertCount(1, $lazada);
        $this->assertSame('LZ-CANCEL', $lazada[0]->salesorder_no);
    }

    public function test_opsi_status_mp_berupa_katalog_statis_semua_channel(): void
    {
        $statuses = $this->service->shipmentFilterOptions()['statuses'];
        $labels = array_column($statuses, 'label');

        $this->assertGreaterThan(
            40,
            count($statuses),
            'Katalog harus kaya seperti daftar status_mp Jubelio',
        );

        foreach (['SHOPEE', 'TIKTOK', 'LAZADA', 'WOOCOMMERCE'] as $channel) {
            $this->assertNotEmpty(
                array_filter($labels, fn ($l) => str_ends_with($l, " - {$channel}")),
                "Channel {$channel} harus punya opsi",
            );
        }

        $this->assertContains('Ready To Ship - SHOPEE', $labels);
        $this->assertContains('To Confirm Receive - SHOPEE', $labels);
        $this->assertContains('Packed - LAZADA', $labels);
        $this->assertContains('Lost By 3PL - LAZADA', $labels);
        $this->assertContains('Awaiting Shipment - TIKTOK', $labels);
    }

    public function test_katalog_tidak_bergantung_pada_data(): void
    {
        $this->assertSame(
            $this->service->shipmentFilterOptions()['statuses'],
            $this->service->shipmentFilterOptions()['statuses'],
            'Katalog statis, tidak boleh berubah karena isi tabel',
        );

        $this->assertNotEmpty(
            $this->service->shipmentFilterOptions()['statuses'],
            'Dropdown harus tetap terisi meski belum ada pesanan sama sekali',
        );
    }

    public function test_setiap_opsi_katalog_terpetakan_ke_channel_status_yang_sah(): void
    {
        $sah = array_map(fn (ChannelStatus $c) => $c->value, ChannelStatus::cases());

        foreach ($this->service->shipmentFilterOptions()['statuses'] as $option) {
            [$source, $raw] = \Modules\Report\Repositories\ReportRepository::splitStatusMp($option['value']);
            $canonical = \Modules\Sales\Support\ChannelStatusNormalizer::normalize($source, $raw);

            $this->assertNotNull($canonical, "Opsi {$option['value']} tidak terpetakan");
            $this->assertContains(
                $canonical->value,
                $sah,
                "Opsi {$option['value']} memetakan ke nilai di luar CHECK constraint",
            );
            $this->assertNotSame(
                ChannelStatus::UNKNOWN,
                $canonical,
                "Opsi {$option['value']} jatuh ke UNKNOWN — filter tidak akan menemukan apa pun",
            );
        }
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

    public function test_kolom_status_pesanan_memakai_label_bukan_nilai_mentah(): void
    {
        foreach ([
            ['SP-LBL-1', '08:00:00', 'pending'],
            ['SP-LBL-2', '09:00:00', 'cancelled'],
            ['SP-LBL-3', '10:00:00', 'ready-to-ship'],
            ['SP-LBL-4', '11:00:00', 'AWAITING_BUYER_CONFIRMATION'],
            ['SP-LBL-5', '12:00:00', 'packed'],
        ] as [$no, $jam, $status]) {
            $this->makeOrder([
                'salesorder_no' => $no,
                'transaction_date' => "2026-07-18 {$jam}",
                'status' => $status,
            ]);
        }

        $export = new ShipmentListReportExport($this->service, [
            'from' => '2026-07-18', 'to' => '2026-07-18',
        ]);

        \Maatwebsite\Excel\Facades\Excel::store($export, 'test-pengiriman-label.xlsx', 'local');
        $path = \Illuminate\Support\Facades\Storage::disk('local')->path('test-pengiriman-label.xlsx');
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();

        $this->assertSame('Menunggu', $sheet->getCell('H2')->getValue());
        $this->assertSame('Dibatalkan', $sheet->getCell('H3')->getValue());
        $this->assertSame('Siap Kirim', $sheet->getCell('H4')->getValue(), 'Tanda hubung harus dinormalkan');
        $this->assertSame(
            'Menunggu Konfirmasi Pembeli',
            $sheet->getCell('H5')->getValue(),
            'Garis bawah dan huruf besar harus dinormalkan',
        );
        $this->assertSame('Dikemas - Siap Dikirim', $sheet->getCell('H6')->getValue());

        @unlink($path);
    }

    public function test_status_pesanan_tak_dikenal_ditampilkan_apa_adanya(): void
    {
        $this->assertSame('STATUS_BARU_DARI_CHANNEL', ReportService::orderStatusLabel('STATUS_BARU_DARI_CHANNEL'));
        $this->assertNull(ReportService::orderStatusLabel(null));
        $this->assertNull(ReportService::orderStatusLabel(''));
    }

    public function test_endpoint_export_dan_validasinya(): void
    {
        $this->get('/api/v1/reports/wms/shipment/export?from=2026-07-01&to=2026-07-31')
            ->assertOk();

        $this->getJson('/api/v1/reports/wms/shipment/export?from=2026-07-31&to=2026-07-01')
            ->assertStatus(422);

        $this->getJson('/api/v1/reports/wms/shipment/export')
            ->assertStatus(422);
    }

    public function test_endpoint_options_mengembalikan_kurir_dan_status(): void
    {
        $this->makeOrder([
            'salesorder_no' => 'SP-OPT-EP',
            'transaction_date' => '2026-07-18 08:00:00',
            'source' => 'shopee',
            'channel_fulfillment_status' => 'Shipped',
        ]);

        $response = $this->getJson('/api/v1/reports/wms/shipment/options')->assertOk();

        $labels = array_column($response->json('data.statuses'), 'label');
        $this->assertContains('Shipped - SHOPEE', $labels);
        $this->assertContains('Ready To Ship - LAZADA', $labels);
        $this->assertCount(2, $response->json('data.couriers'));
    }
}
