<?php

declare(strict_types=1);

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Modules\Channel\Models\ChannelWebhookInbox;
use Modules\Channel\Support\ChannelWebhookReferenceExtractor;

class BackfillWebhookReturnReferences extends Command
{
    protected $signature = 'channel:webhooks-backfill-return-references
        {--dry-run : Tampilkan jumlah yang dapat diisi tanpa mengubah database}
        {--chunk=500 : Jumlah baris per batch}
        {--limit=0 : Batasi baris yang dipindai; 0 berarti tanpa batas}';

    protected $description = 'Isi referensi retur terstruktur pada inbox webhook secara bertahap.';

    public function handle(): int
    {
        $chunkSize = (int) $this->option('chunk');
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        if ($chunkSize < 1 || $limit < 0) {
            $this->error('--chunk harus positif dan --limit tidak boleh negatif.');

            return self::FAILURE;
        }

        $scanned = 0;
        $matched = 0;
        $updated = 0;

        $query = ChannelWebhookInbox::query()
            ->whereNull('channel_return_id')
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $query->chunkById($chunkSize, function (Collection $rows) use (
            $dryRun,
            &$scanned,
            &$matched,
            &$updated,
        ): void {
            foreach ($rows as $row) {
                $scanned++;
                $payload = is_array($row->payload)
                    ? $row->payload
                    : json_decode((string) $row->payload, true);
                $returnId = is_array($payload)
                    ? ChannelWebhookReferenceExtractor::returnId((string) $row->channel, $payload)
                    : null;

                if ($returnId === null) {
                    continue;
                }

                $matched++;
                if (! $dryRun) {
                    $updated += (int) ChannelWebhookInbox::query()
                        ->whereKey($row->id)
                        ->whereNull('channel_return_id')
                        ->update(['channel_return_id' => $returnId]);
                }
            }
        });

        $this->table(['Mode', 'Scanned', 'Matched', 'Updated'], [[
            $dryRun ? 'DRY-RUN' : 'APPLY',
            $scanned,
            $matched,
            $updated,
        ]]);

        if ($dryRun) {
            $this->warn('DRY-RUN: tidak ada perubahan database.');
        }

        return self::SUCCESS;
    }
}
