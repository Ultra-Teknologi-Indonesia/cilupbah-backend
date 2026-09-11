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

    public function test_partial_apply_menghapus_hanya_kandidat_aman(): void
    {
        $locationId = (string) Str::uuid();
        $locationCode = 'CUT-'.Str::upper(Str::random(6));
        DB::table('locations')->insert([
            'id' => $locationId, 'location_code' => $locationCode, 'location_name' => 'Gudang Kecil',
            'location_type' => 'warehouse', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $safeId = (string) Str::uuid();
        $processedId = (string) Str::uuid();
        foreach ([
            [$safeId, 'SO-SAFE', 'pending'],
            [$processedId, 'SO-PROCESSED', 'packed'],
        ] as [$id, $number, $status]) {
            DB::table('sales_orders')->insert([
                'id' => $id, 'salesorder_no' => $number, 'location_id' => $locationId,
                'status' => $status, 'created_at' => '2026-09-09 10:00:00', 'updated_at' => '2026-09-09 10:00:00',
            ]);
        }

        $upload = UploadedFile::fake()->createWithContent(
            'orders.csv',
            "Nomor,Tgl.Pesanan,Lokasi\nSO-NOT-INTERNAL,10 Sep 2026 10:00,Gudang Kecil\n",
        );
        $service = app(OrderCutoverService::class);
        $meta = [$upload->getRealPath() => ['category' => 'ready_to_process', 'original_name' => 'orders.csv']];
        $result = $service->apply(
            [$upload->getRealPath()],
            CarbonImmutable::parse('2026-09-10 21:00:00', 'Asia/Jakarta'),
            [$locationCode],
            $meta,
            true,
        );

        self::assertSame('APPLY_PARTIAL', $result['mode']);
        self::assertSame(1, $result['deleted_orders']);
        self::assertDatabaseMissing('sales_orders', ['id' => $safeId]);
        self::assertDatabaseHas('sales_orders', ['id' => $processedId]);
    }

    public function test_hard_cutoff_keeps_boundary_and_newer_orders_without_csv_whitelist(): void
    {
        $locationId = (string) Str::uuid();
        $locationCode = 'CUT-'.Str::upper(Str::random(6));
        DB::table('locations')->insert([
            'id' => $locationId, 'location_code' => $locationCode, 'location_name' => 'Gudang Kecil',
            'location_type' => 'warehouse', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $oldId = (string) Str::uuid();
        $boundaryId = (string) Str::uuid();
        $newerId = (string) Str::uuid();
        foreach ([
            [$oldId, 'SO-OLD-HARD', '2026-09-11 14:59:59'],
            [$boundaryId, 'SO-BOUNDARY-HARD', '2026-09-11 15:00:00'],
            [$newerId, 'SO-NEWER-HARD', '2026-09-11 15:00:01'],
        ] as [$id, $number, $created]) {
            DB::table('sales_orders')->insert([
                'id' => $id, 'salesorder_no' => $number, 'location_id' => $locationId,
                'status' => 'pending', 'created_at' => $created, 'transaction_date' => $created, 'updated_at' => $created,
            ]);
        }

        $service = app(OrderCutoverService::class);
        $cutoff = CarbonImmutable::parse('2026-09-11 22:00:00', 'Asia/Jakarta');
        $audit = $service->previewHardCutoff($cutoff, [$locationCode]);

        self::assertSame('HARD_CUTOFF', $audit['mode']);
        self::assertSame('2026-09-11 22:00:00', $audit['cutoff_at_wib']);
        self::assertSame(1, $audit['orders_before_cutoff_to_delete']);
        self::assertSame(2, $audit['orders_at_or_after_cutoff_kept']);
        self::assertSame(0, $audit['blocking']);

        $result = $service->applyHardCutoff($cutoff, [$locationCode]);

        self::assertSame(1, $result['deleted_orders']);
        self::assertDatabaseMissing('sales_orders', ['id' => $oldId]);
        self::assertDatabaseHas('sales_orders', ['id' => $boundaryId]);
        self::assertDatabaseHas('sales_orders', ['id' => $newerId]);
    }

    public function test_manual_cutoff_requires_exact_wib_datetime_local_format(): void
    {
        $service = app(OrderCutoverService::class);

        self::assertSame(
            '2026-09-11 15:00:00',
            $service->parseManualCutoff('2026-09-11T22:00')->utc()->toDateTimeString(),
        );

        $this->expectExceptionMessage('format cutoff tidak valid');
        $service->parseManualCutoff('2026-09-11 22:00');
    }

    public function test_cutoff_uses_latest_timestamp_across_csv_files_with_gaps(): void
    {
        $first = UploadedFile::fake()->createWithContent(
            'first.csv',
            "Nomor,Tgl.Pesanan,Lokasi\nSO-OLD,29 Jul 2026 01:34,Gudang Kecil\nSO-MIDDLE,02 Aug 2026 08:00,Gudang Kecil\n",
        );
        $last = UploadedFile::fake()->createWithContent(
            'last.csv',
            "salesorder_no,transaction_date,location_name\nSO-LATEST,10 Sep 2026 22:30,Gudang Kecil\n",
        );

        $cutoff = app(OrderCutoverService::class)->deriveCutoffFromFiles([
            $first->getRealPath(),
            $last->getRealPath(),
        ]);

        self::assertSame('2026-09-10 15:30:00', $cutoff->toDateTimeString());
    }

    public function test_cutoff_rejects_order_rows_without_valid_timestamp(): void
    {
        $upload = UploadedFile::fake()->createWithContent(
            'invalid.csv',
            "Nomor,Tgl.Pesanan,Lokasi\nSO-MISSING,,Gudang Kecil\n",
        );

        $this->expectExceptionMessage('tidak memiliki tanggal/jam order');
        app(OrderCutoverService::class)->deriveCutoffFromFiles([$upload->getRealPath()]);
    }

    public function test_cutoff_rejects_impossible_timestamp(): void
    {
        $upload = UploadedFile::fake()->createWithContent(
            'invalid-date.csv',
            "Nomor,Tgl.Pesanan,Lokasi\nSO-INVALID,31 Feb 2026 12:00,Gudang Kecil\n",
        );

        $this->expectExceptionMessage('tanggal/jam tidak valid');
        app(OrderCutoverService::class)->deriveCutoffFromFiles([$upload->getRealPath()]);
    }

    public function test_cutoff_falls_back_to_created_date_when_transaction_date_is_empty(): void
    {
        $upload = UploadedFile::fake()->createWithContent(
            'fallback.csv',
            "salesorder_no,transaction_date,created_date,location_name\nSO-FALLBACK,,10 Sep 2026 22:30,Gudang Kecil\n",
        );

        $cutoff = app(OrderCutoverService::class)->deriveCutoffFromFiles([$upload->getRealPath()]);

        self::assertSame('2026-09-10 15:30:00', $cutoff->toDateTimeString());
    }
}
