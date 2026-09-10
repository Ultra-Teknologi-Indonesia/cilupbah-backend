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
            'cutoff' => '2026-09-10T21:00',
            'locations' => 'O',
        ]);

        $response->assertRedirect();
        $job = OrderCutoverConsoleJob::query()->sole();
        self::assertCount(4, $job->files);
        self::assertSame(['O'], $job->location_codes);
        foreach ($job->files as $file) {
            Storage::disk('s3')->assertExists($file['path']);
        }
        Queue::assertPushed(RunOrderCutoverConsoleJob::class, fn (RunOrderCutoverConsoleJob $queued): bool => $queued->consoleJobId === $job->id);
    }
}
