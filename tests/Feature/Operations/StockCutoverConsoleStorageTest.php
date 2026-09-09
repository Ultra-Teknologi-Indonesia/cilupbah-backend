<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Inventory\Jobs\RunStockCutoverConsoleJob;
use Modules\Inventory\Models\StockCutoverConsoleJob;
use Tests\TestCase;

final class StockCutoverConsoleStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_persists_uploaded_files_on_the_configured_object_storage_disk(): void
    {
        config([
            'operations.stock_cutover_console.token' => str_repeat('a', 64),
            'operations.stock_cutover_console.upload_disk' => 's3',
            'operations.stock_cutover_console.report_disk' => 's3',
        ]);
        Storage::fake('s3');
        Queue::fake();

        $response = $this->post('/_ops/stock-cutover/'.str_repeat('a', 64).'/preview', [
            'gudang_kecil' => UploadedFile::fake()->create('migrasi-kecil.xlsx', 32),
            'pusat' => UploadedFile::fake()->create('migrasi-pusat.xlsx', 32),
        ]);

        $response->assertRedirect();

        $job = StockCutoverConsoleJob::query()->sole();
        self::assertSame('s3', $job->files['O']['disk']);
        self::assertSame('s3', $job->files['WH-PUSAT']['disk']);
        Storage::disk('s3')->assertExists("stock-cutover-console/{$job->id}/gudang_kecil.xlsx");
        Storage::disk('s3')->assertExists("stock-cutover-console/{$job->id}/pusat.xlsx");
        Queue::assertPushed(
            RunStockCutoverConsoleJob::class,
            fn (RunStockCutoverConsoleJob $queued): bool => $queued->consoleJobId === $job->id
                && $queued->connection === config('operations.stock_cutover_console.queue_connection', 'redis-long')
                && $queued->queue === config('operations.stock_cutover_console.queue', 'stock-cutover'),
        );
    }

    public function test_worker_reads_from_r2_and_returns_reports_to_r2_without_leaving_temporary_files(): void
    {
        config([
            'operations.stock_cutover_console.upload_disk' => 's3',
            'operations.stock_cutover_console.report_disk' => 's3',
        ]);
        Storage::fake('s3');

        $id = (string) Str::uuid7();
        $sourcePath = "stock-cutover-console/{$id}/gudang_kecil.xlsx";
        $source = 'xlsx content for storage transport test';
        Storage::disk('s3')->put($sourcePath, $source);
        $job = StockCutoverConsoleJob::create([
            'id' => $id,
            'type' => 'preview',
            'status' => StockCutoverConsoleJob::STATUS_QUEUED,
            'files' => [
                'O' => [
                    'disk' => 's3',
                    'path' => $sourcePath,
                    'original_name' => 'migrasi-kecil.xlsx',
                    'sha256' => hash('sha256', $source),
                ],
            ],
        ]);

        Artisan::shouldReceive('call')
            ->once()
            ->withArgs(function (string $command, array $arguments): bool {
                if ($command !== 'inventory:import-baseline') {
                    return false;
                }

                file_put_contents($arguments['--export'], "sku,status\nTEST-001,valid\n");

                return is_file($arguments['file']) && ($arguments['--location'] ?? null) === 'O';
            })
            ->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn('Tidak ada baris bermasalah.');

        (new RunStockCutoverConsoleJob($job->id))->handle();

        $job->refresh();
        self::assertSame(StockCutoverConsoleJob::STATUS_READY, $job->status);
        self::assertSame('s3', $job->report_disk);
        Storage::disk('s3')->assertExists("stock-cutover-console/{$id}/O-report.csv");
        Storage::disk('s3')->assertExists("stock-cutover-console/{$id}/report.json");
        Storage::disk('local')->assertMissing("stock-cutover-console-tmp/{$id}/O-source.xlsx");
        Storage::disk('local')->assertMissing("stock-cutover-console-tmp/{$id}/O-report.csv");
    }

    public function test_status_polling_is_not_limited_by_the_preview_rate_limit(): void
    {
        $token = str_repeat('b', 64);
        config(['operations.stock_cutover_console.token' => $token]);

        $job = StockCutoverConsoleJob::create([
            'type' => 'preview',
            'status' => StockCutoverConsoleJob::STATUS_PROCESSING,
            'files' => [],
        ]);

        for ($attempt = 0; $attempt < 11; $attempt++) {
            $this->get("/_ops/stock-cutover/{$token}/jobs/{$job->id}")
                ->assertOk();
        }
    }
}
