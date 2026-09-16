<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Inventory\Jobs\RunOrderCutoverConsoleJob;
use Modules\Inventory\Models\OrderCutoverConsoleJob;
use Tests\TestCase;

final class OrderCutoverConsoleStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_persists_all_four_csv_files_and_queues_job(): void
    {
        $token = str_repeat('d', 64);
        config([
            'operations.order_cutover_console.token' => $token,
            'operations.order_cutover_console.upload_disk' => 's3',
        ]);
        Storage::fake('s3');
        Queue::fake();
        $csv = "Nomor,Tgl.Pesanan,Lokasi\nSP-ORDER-001,10 Sep 2026 21:00,Gudang Kecil\n";

        $response = $this->post("/_ops/order-cutover/{$token}/preview", [
            'failed_pickup' => UploadedFile::fake()->createWithContent('failed.csv', $csv),
            'no_internal_stock' => UploadedFile::fake()->createWithContent('stock.csv', $csv),
            'ready_to_process' => UploadedFile::fake()->createWithContent('ready.csv', $csv),
            'awaiting_payment' => UploadedFile::fake()->createWithContent('payment.csv', $csv),
        ]);

        $response->assertRedirect();
        $job = OrderCutoverConsoleJob::query()->sole();
        self::assertCount(4, $job->files);
        self::assertSame(['O'], $job->location_codes);
        self::assertSame('2026-09-10 14:00:00', $job->cutoff_at->utc()->toDateTimeString());
        foreach ($job->files as $file) {
            Storage::disk('s3')->assertExists($file['path']);
        }
        Queue::assertPushed(RunOrderCutoverConsoleJob::class, fn (RunOrderCutoverConsoleJob $queued): bool => $queued->consoleJobId === $job->id);
    }

    public function test_hard_cutoff_preview_uses_manual_wib_cutoff_without_csv_files(): void
    {
        $token = str_repeat('e', 64);
        config([
            'operations.order_cutover_console.token' => $token,
            'operations.order_cutover_console.small_warehouse_location' => 'O',
        ]);
        Queue::fake();

        $response = $this->post("/_ops/order-cutover/{$token}/preview", [
            'cutoff_at' => '2026-09-11T23:00',
        ]);

        $response->assertRedirect();
        $job = OrderCutoverConsoleJob::query()->sole();
        self::assertSame('hard_preview', $job->type);
        self::assertSame([], $job->files);
        self::assertSame(['O'], $job->location_codes);
        self::assertSame('2026-09-11 16:00:00', $job->cutoff_at->utc()->toDateTimeString());
        Queue::assertPushed(RunOrderCutoverConsoleJob::class, fn (RunOrderCutoverConsoleJob $queued): bool => $queued->consoleJobId === $job->id);
    }

    public function test_intake_preview_queues_a_two_warehouse_intake_job(): void
    {
        $token = str_repeat('f', 64);
        config(['operations.order_cutover_console.token' => $token]);
        Queue::fake();

        $response = $this->post("/_ops/order-cutover/{$token}/intake/preview");

        $response->assertRedirect();
        $job = OrderCutoverConsoleJob::query()->sole();
        self::assertSame('intake_preview', $job->type);
        self::assertSame(['O', 'WH-PUSAT'], $job->location_codes);
        self::assertSame([], $job->files);
        Queue::assertPushed(RunOrderCutoverConsoleJob::class, fn (RunOrderCutoverConsoleJob $queued): bool => $queued->consoleJobId === $job->id);
    }

    public function test_intake_apply_requires_ready_preview_and_queues_apply_job(): void
    {
        $token = str_repeat('a', 64);
        config(['operations.order_cutover_console.token' => $token]);
        Queue::fake();
        $preview = OrderCutoverConsoleJob::create([
            'type' => 'intake_preview',
            'status' => OrderCutoverConsoleJob::STATUS_READY,
            'files' => [],
            'location_codes' => ['O', 'WH-PUSAT'],
            'cutoff_at' => now(),
            'report' => ['blocking' => 0],
        ]);

        $response = $this->post("/_ops/order-cutover/{$token}/jobs/{$preview->id}/intake-apply", [
            'understood' => '1',
            'confirmation' => 'CLOSE-ORDER-INTAKE',
        ]);

        $response->assertRedirect();
        $apply = OrderCutoverConsoleJob::query()->where('type', 'intake_apply')->sole();
        self::assertSame(['O', 'WH-PUSAT'], $apply->location_codes);
        Queue::assertPushed(RunOrderCutoverConsoleJob::class, fn (RunOrderCutoverConsoleJob $queued): bool => $queued->consoleJobId === $apply->id);
    }
}
