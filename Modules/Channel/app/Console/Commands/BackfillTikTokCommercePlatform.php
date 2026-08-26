<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Services\TikTokOrderPlatformBackfillService;

class BackfillTikTokCommercePlatform extends Command
{
    protected $signature = 'channel:backfill-tiktok-commerce-platform
        {--order= : Batasi ke salesorder_no atau channel_order_no}
        {--shop= : Batasi ke shop_id TikTok}
        {--limit=500 : Jumlah pesanan maksimum}
        {--apply : Terapkan perubahan; tanpa opsi ini hanya dry-run}';

    protected $description = 'Rekonsiliasi platform Tokopedia/TikTok dan prefix nomor pesanan dari API TikTok.';

    public function handle(TikTokOrderPlatformBackfillService $service): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $apply = (bool) $this->option('apply');

        if (! $apply) {
            $this->warn('DRY-RUN: tidak ada data yang diubah. Tambahkan --apply setelah hasil ditinjau.');
        }

        $result = $service->run(
            $this->option('order') ?: null,
            $this->option('shop') ?: null,
            $limit,
            $apply,
        );

        $rows = collect($result['rows'])->map(fn (array $row): array => [
            'order_id' => $row['order_id'],
            'current_no' => $row['current_no'],
            'target_no' => $row['target_no'] ?? '-',
            'platform' => $row['target_platform'] ?? $row['current_platform'] ?? '-',
            'action' => $row['action'],
            'message' => $row['message'] ?? '',
        ])->all();

        if ($rows !== []) {
            $this->table(
                ['Order ID', 'Nomor sekarang', 'Nomor tujuan', 'Platform', 'Aksi', 'Keterangan'],
                $rows,
            );
        }

        $this->newLine();
        $this->info(sprintf(
            'Selesai. Total: %d, %s: %d, tetap: %d, tidak ditemukan: %d, konflik: %d, gagal: %d.',
            $result['total'],
            $apply ? 'diperbarui' : 'akan diperbarui',
            $result['updated'],
            $result['unchanged'],
            $result['not_found'],
            $result['conflicts'],
            $result['errors'],
        ));

        return ($result['errors'] > 0 || $result['not_found'] > 0 || $result['conflicts'] > 0)
            ? self::FAILURE
            : self::SUCCESS;
    }
}
