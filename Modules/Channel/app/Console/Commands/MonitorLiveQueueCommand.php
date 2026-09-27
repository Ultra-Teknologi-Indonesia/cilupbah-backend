<?php

declare(strict_types=1);

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Services\QueueOperationalSnapshotService;

final class MonitorLiveQueueCommand extends Command
{
    protected $signature = 'channel:monitor-live {--once : Jalankan hanya 1 kali snapshot tanpa loop}';

    protected $description = 'Monitor antrean webhook dan order intake secara real-time dengan proteksi memori anti-leak.';

    public function __construct(
        private readonly QueueOperationalSnapshotService $service,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $isOnce = (bool) $this->option('once');
        $previousProcessed = null;
        $previousTime = null;

        do {
            $currentTime = microtime(true);
            $snapshot = $this->service->snapshot();
            $webhookQueue = $snapshot['queues']['webhooks'];
            $orderQueue = $snapshot['queues']['orders'];
            $processed = $snapshot['webhooks']['processed'];
            $received = $snapshot['webhooks']['received'];
            $failed = $snapshot['webhooks']['failed'];
            $skipped = $snapshot['webhooks']['skipped'];
            $successRate = $snapshot['webhooks']['success_rate'];
            $processedLastMinute = $snapshot['webhooks']['processed_last_minute'];
            $totalQueue = $webhookQueue['queue_depth'] + $orderQueue['queue_depth'];
            $speedPerSecond = round($processedLastMinute / 60, 1);

            if ($previousProcessed !== null && $previousTime !== null) {
                $deltaJobs = $processed - $previousProcessed;
                $deltaTime = $currentTime - $previousTime;

                if ($deltaTime > 0) {
                    $speedPerSecond = round(max(0, $deltaJobs / $deltaTime), 1);
                }
            }

            $previousProcessed = $processed;
            $previousTime = $currentTime;

            if (! $isOnce) {
                echo "\033[2J\033[;H";
            }

            $this->line('========================================================================================');
            $this->info(' 🚀 CILUPBAH ENTERPRISE REAL-TIME MONITORING DASHBOARD (HORIZON & WEBHOOK INTAKE)');
            $this->line('    Waktu Server: '.date('Y-m-d H:i:s').' WIB | RAM CLI: '.round(memory_get_usage(true) / 1024 / 1024, 1).' MB');
            $this->line('========================================================================================');
            $this->line('⚡ KECEPATAN & KINERJA WORKER:');
            $this->line(sprintf('   · Throughput Pemrosesan : %.1f jobs/detik (≈ %d jobs/menit)', $speedPerSecond, $speedPerSecond * 60));
            $this->line(sprintf('   · Diproses 1 Menit Lalu : %s webhooks', number_format($processedLastMinute)));
            $this->line(sprintf('   · Tingkat Keberhasilan  : %.2f%%', $successRate));
            $this->newLine();
            $this->line('📥 STATUS ANTREAN REDIS (SISA MENUNGGU DIEKSEKUSI):');
            $this->line(sprintf('   · 📡 Webhook Channel    : %-6s jobs  [%s]', number_format($webhookQueue['queue_depth']), $webhookQueue['queue_depth'] === 0 ? '🟢 BERSIH' : '🔵 MEMPROSES'));
            $this->line(sprintf('   · 📦 Priority Orders    : %-6s jobs  [%s]', number_format($orderQueue['queue_depth']), $orderQueue['queue_depth'] === 0 ? '🟢 KOSONG' : '⚡ PRIORITAS TINGGI'));
            $this->line(sprintf('   · ⏱️  Delayed / Reserved  : %-6s / %s jobs', number_format($webhookQueue['delayed'] + $orderQueue['delayed']), number_format($webhookQueue['reserved'] + $orderQueue['reserved'])));
            $this->line(sprintf('   · 📊 Total Sisa Queue   : %-6s jobs', number_format($totalQueue)));
            $this->newLine();
            $this->line('📊 TOTAL AKUMULASI DI DATABASE:');
            $this->line(sprintf('   · ✅ SUKSES (PROCESSED)   : %s', number_format($processed)));
            $this->line(sprintf('   · 🛡️  DEDUPLIKASI (SKIPPED): %s', number_format($skipped)));
            $this->line(sprintf('   · ⏳ ANTRIAN (RECEIVED)   : %s', number_format($received)));
            $this->line(sprintf('   · ❌ GAGAL (FAILED)       : %s', number_format($failed)));
            $this->newLine();
            $this->line('📦 3 PESANAN TERBARU YANG LANGSUNG MASUK SECARA REAL-TIME:');

            foreach ($snapshot['latest_orders'] as $order) {
                $this->line(sprintf(
                    '   · 🛒 %-24s | Channel: %-7s | Cust: %-15s | %s',
                    $order->salesorder_no,
                    strtoupper((string) $order->source),
                    mb_substr($order->customer_name ?: 'Buyer', 0, 15),
                    $order->created_at,
                ));
            }

            $this->line('========================================================================================');
            unset($snapshot);
            gc_collect_cycles();

            if (! $isOnce) {
                sleep(3);
            }
        } while (! $isOnce);

        return self::SUCCESS;
    }
}
