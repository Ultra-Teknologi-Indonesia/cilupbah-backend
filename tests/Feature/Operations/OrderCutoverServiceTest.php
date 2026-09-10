<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Services\OrderCutoverService;
use Tests\TestCase;

final class OrderCutoverServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_and_apply_keep_csv_and_newer_orders_only(): void
    {
        $locationId = (string) Str::uuid();
        $locationCode = 'CUT-'.Str::upper(Str::random(6));
        DB::table('locations')->insert([
            'id' => $locationId, 'location_code' => $locationCode, 'location_name' => 'Gudang Kecil',
            'location_type' => 'warehouse', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $oldId = (string) Str::uuid();
        $csvId = (string) Str::uuid();
        $newerId = (string) Str::uuid();
        foreach ([
            [$oldId, 'SO-OLD', '2026-09-09 10:00:00'],
            [$csvId, 'SO-CSV', '2026-09-09 10:00:00'],
            [$newerId, 'SO-NEWER', '2026-09-10 22:00:00'],
        ] as [$id, $number, $created]) {
            DB::table('sales_orders')->insert([
                'id' => $id, 'salesorder_no' => $number, 'location_id' => $locationId,
                'status' => 'pending', 'created_at' => $created, 'updated_at' => $created,
            ]);
        }
        $upload = UploadedFile::fake()->createWithContent('orders.csv', "Nomor,Tgl.Pesanan,Lokasi\nSO-CSV,10 Sep 2026 10:00,Gudang Kecil\n");
        $service = app(OrderCutoverService::class);
        $meta = [$upload->getRealPath() => ['category' => 'ready_to_process', 'original_name' => 'orders.csv']];
        $cutoff = CarbonImmutable::parse('2026-09-10 21:00:00', 'Asia/Jakarta');
        $audit = $service->preview([$upload->getRealPath()], $cutoff, [$locationCode], $meta);

        self::assertSame(0, $audit['blocking']);
        self::assertSame(1, $audit['orders_to_delete']);
        self::assertSame(1, $audit['csv_orders_kept']);
        self::assertSame(1, $audit['newer_orders_kept']);

        $result = $service->apply([$upload->getRealPath()], $cutoff, [$locationCode], $meta);
        self::assertSame(1, $result['deleted_orders']);
        self::assertDatabaseMissing('sales_orders', ['id' => $oldId]);
        self::assertDatabaseHas('sales_orders', ['id' => $csvId]);
        self::assertDatabaseHas('sales_orders', ['id' => $newerId]);
    }
}
