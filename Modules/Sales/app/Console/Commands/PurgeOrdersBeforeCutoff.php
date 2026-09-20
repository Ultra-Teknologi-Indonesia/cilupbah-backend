<?php

declare(strict_types=1);

namespace Modules\Sales\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Sales\Services\SalesOrderCutoffPurgeService;
use RuntimeException;
use Throwable;

final class PurgeOrdersBeforeCutoff extends Command
{
    private const TIMEZONE = 'Asia/Jakarta';

    protected $signature = 'orders:purge-before-cutoff
        {--cutoff= : Cutoff transaction_date dalam WIB, format YYYY-MM-DD HH:MM}
        {--source=* : Batasi source; dapat diulang. Kosong berarti semua source}
        {--chunk=200 : Jumlah order per transaksi, 25-500}
        {--apply : Terapkan penghapusan permanen}
        {--confirm= : Token konfirmasi yang ditampilkan oleh dry-run}';

    protected $description = 'Dry-run dan hapus permanen order dengan transaction_date sebelum cutoff WIB secara terkontrol.';

    public function handle(SalesOrderCutoffPurgeService $service): int
    {
        try {
            $cutoff = $this->parseCutoff((string) $this->option('cutoff'));
            $sources = (array) $this->option('source');
            $chunkSize = max(25, min(500, (int) $this->option('chunk')));
            $preview = $service->preview($cutoff, $sources);

            $this->renderPreview($cutoff, $preview);

            if (! (bool) $this->option('apply')) {
                $this->newLine();
                $this->warn('DRY-RUN: tidak ada data yang diubah.');
                $this->line('Untuk apply, gunakan token: '.$this->confirmationToken($cutoff));

                return self::SUCCESS;
            }

            if ((int) $preview['blocked_count'] > 0) {
                $this->error('APPLY DIBATALKAN: masih ada order dengan jejak operasional/stock/finance/media.');
                $this->line('Selesaikan blocker yang tercantum di atas, lalu jalankan dry-run ulang.');

                return self::FAILURE;
            }

            $expectedToken = $this->confirmationToken($cutoff);
            if (! hash_equals($expectedToken, (string) $this->option('confirm'))) {
                $this->error('Token konfirmasi tidak cocok. Gunakan: --confirm='.$expectedToken);

                return self::FAILURE;
            }

            if ((int) $preview['candidate_count'] === 0) {
                $this->info('Tidak ada order yang perlu dihapus.');

                return self::SUCCESS;
            }

            $result = $service->purge($cutoff, $sources, $chunkSize);

            $this->newLine();
            $this->info('PEMBERSIHAN SELESAI');
            $this->table(['Hasil', 'Jumlah'], [
                ['Order dihapus', $result['deleted_count']],
                ['Order tersisa sebelum cutoff', $result['remaining_count']],
                ['Finance state dibersihkan', $result['deleted_finance_states']],
                ['Dead letter ditandai selesai', $result['resolved_dead_letters']],
            ]);
            $this->line('Audit run ID: '.$result['run_id']);

            return (int) $result['remaining_count'] === 0 ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function parseCutoff(string $value): CarbonImmutable
    {
        $value = trim($value);
        if ($value === '') {
            throw new RuntimeException('Opsi --cutoff wajib diisi, contoh: --cutoff="2026-09-16 16:00"');
        }

        foreach (['!Y-m-d H:i', '!Y-m-d H:i:s'] as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat($format, $value, self::TIMEZONE);
            } catch (Throwable) {
                continue;
            }

            $outputFormat = $format === '!Y-m-d H:i' ? 'Y-m-d H:i' : 'Y-m-d H:i:s';
            $errors = \DateTimeImmutable::getLastErrors();
            $hasErrors = is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);

            if ($parsed !== false && ! $hasErrors && $parsed->format($outputFormat) === $value) {
                return $parsed;
            }
        }

        throw new RuntimeException('Format cutoff tidak valid. Gunakan WIB: YYYY-MM-DD HH:MM');
    }

    private function renderPreview(CarbonImmutable $cutoff, array $preview): void
    {
        $this->info('PEMBERSIHAN ORDER BERDASARKAN TANGGAL TRANSAKSI');
        $this->line('Mode       : '.((bool) $this->option('apply') ? 'APPLY' : 'DRY-RUN'));
        $this->line('Aturan     : transaction_date < '.$cutoff->format('Y-m-d H:i:s').' WIB');
        $this->line('Cutoff UTC : '.$preview['cutoff_utc']);
        $this->line('Source     : '.($preview['sources'] === [] ? 'SEMUA SOURCE (termasuk manual/internal)' : implode(', ', $preview['sources'])));

        $this->newLine();
        $this->table(['Ringkasan', 'Jumlah'], [
            ['Kandidat sebelum cutoff', $preview['candidate_count']],
            ['Aman dihapus', $preview['safe_count']],
            ['Diblokir demi keamanan', $preview['blocked_count']],
            ['Kandidat yang baru masuk sistem setelah cutoff', $preview['created_at_or_after_cutoff_count']],
        ]);

        if ($preview['by_source_status'] !== []) {
            $this->table(
                ['Source', 'Status', 'Order'],
                array_map(static fn (array $row): array => [
                    $row['source'], $row['status'], $row['total'],
                ], $preview['by_source_status']),
            );
        }

        if ($preview['blockers'] !== []) {
            $this->warn('Blocker ditemukan; apply tidak boleh dilanjutkan sebelum diperiksa.');
            $this->table(
                ['Jenis blocker', 'Record', 'Order', 'Contoh order'],
                array_map(static fn (array $row): array => [
                    $row['name'],
                    $row['rows'],
                    $row['orders'],
                    implode(', ', $row['samples']),
                ], $preview['blockers']),
            );
        }

        if ($preview['samples'] !== []) {
            $this->line('Contoh kandidat paling dekat dengan cutoff:');
            $this->table(
                ['Order internal', 'Order channel', 'Source', 'Status', 'Transaction UTC', 'Masuk sistem UTC'],
                array_map(static fn (array $row): array => [
                    $row['salesorder_no'],
                    $row['channel_order_no'],
                    $row['source'],
                    $row['status'],
                    $row['transaction_date'],
                    $row['created_at'],
                ], $preview['samples']),
            );
        }
    }

    private function confirmationToken(CarbonImmutable $cutoff): string
    {
        return 'HAPUS-SEBELUM-'.$cutoff->format('Ymd-Hi');
    }
}
