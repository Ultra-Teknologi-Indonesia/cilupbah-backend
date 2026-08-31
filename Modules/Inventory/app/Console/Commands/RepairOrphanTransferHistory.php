<?php

declare(strict_types=1);

namespace Modules\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RepairOrphanTransferHistory extends Command
{
    protected $signature = 'inventory:repair-orphan-transfer-history
                            {transactions : Nomor transfer dipisahkan koma atau spasi}
                            {--item= : Batasi ke item_id tertentu}
                            {--location= : Batasi ke location_id tertentu}
                            {--apply : Hapus histori orphan. Tanpa flag ini hanya dry-run.}
                            {--pair-outbound : Hapus TRANSFER_OUT negatif yang tertutup reversal positif.}';

    protected $description = 'Membersihkan histori TRANSFER_OUT positif yang orphan setelah transfer dihapus.';

    public function handle(): int
    {
        $transactions = collect(preg_split(
            '/[\s,]+/',
            trim((string) $this->argument('transactions')),
            -1,
            PREG_SPLIT_NO_EMPTY,
        ))
            ->map(static fn (string $value): string => trim($value))
            ->filter()
            ->unique()
            ->values();

        if ($transactions->isEmpty()) {
            $this->error('Minimal satu nomor transfer wajib diberikan.');

            return self::FAILURE;
        }

        $baseQuery = DB::table('inventory_movements as m')
            ->whereIn('m.transaction_number', $transactions->all())
            ->where('m.source', 'TRANSFER_OUT')
            ->when($this->option('item'), fn ($query, $itemId) => $query->where('m.item_id', $itemId))
            ->when($this->option('location'), fn ($query, $locationId) => $query->where('m.location_id', $locationId));

        $positiveQuery = (clone $baseQuery)->where('m.qty', '>', 0);

        $candidateRows = (clone $positiveQuery)
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'm.item_id')
            ->leftJoin('locations as l', 'l.id', '=', 'm.location_id')
            ->select([
                'm.id',
                'm.transaction_number',
                'm.item_id',
                'pv.sku',
                'm.location_id',
                'l.location_name',
                'm.bin_id',
                'm.qty',
                'm.balance',
                'm.created_by',
                'm.transaction_date',
            ])
            ->whereNotExists(function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('inventory_transfers as t')
                    ->whereColumn('t.transfer_number', 'm.transaction_number');
            })
            ->orderBy('m.transaction_number')
            ->orderBy('m.transaction_date')
            ->orderBy('m.id')
            ->get();

        $outboundRows = (clone $baseQuery)
            ->where('m.qty', '<', 0)
            ->select([
                'm.id',
                'm.transaction_number',
                'm.item_id',
                'm.location_id',
                'm.bin_id',
                'm.qty',
            ])
            ->orderBy('m.transaction_number')
            ->orderBy('m.transaction_date')
            ->orderBy('m.id')
            ->get();

        $cell = static fn (object $row): string => implode('|', [
            (string) $row->transaction_number,
            (string) $row->item_id,
            (string) $row->location_id,
            (string) ($row->bin_id ?? 'NULL'),
        ]);

        $positiveQtyByCell = $candidateRows
            ->groupBy($cell)
            ->map(static fn ($rows): int => (int) $rows->sum(fn (object $row): int => (int) $row->qty));

        $pairedOutboundRows = collect();
        foreach ($outboundRows as $row) {
            $key = $cell($row);
            $availablePositiveQty = (int) ($positiveQtyByCell[$key] ?? 0);
            $outboundQty = abs((int) $row->qty);

            if ($outboundQty <= 0 || $outboundQty > $availablePositiveQty) {
                continue;
            }

            $pairedOutboundRows->push($row);
            $positiveQtyByCell[$key] = $availablePositiveQty - $outboundQty;
        }

        $blockedCount = (clone $positiveQuery)
            ->whereExists(function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('inventory_transfers as t')
                    ->whereColumn('t.transfer_number', 'm.transaction_number');
            })
            ->count();

        $this->line('Mode: '.($this->option('apply') ? 'APPLY' : 'DRY-RUN / READ ONLY'));
        $this->line('Transfer: '.$transactions->implode(', '));
        $this->line('Kandidat orphan: '.$candidateRows->count());
        $this->line('Pasangan outbound yang dapat ditutup: '.$pairedOutboundRows->count());

        if ($blockedCount > 0) {
            $this->warn("{$blockedCount} baris memiliki header transfer aktif dan tidak disentuh.");
        }

        foreach ($candidateRows as $row) {
            $this->line(sprintf(
                '  %s | %s | %s | qty %+d | balance %d | oleh %s | %s',
                $row->transaction_number,
                $row->sku ?: $row->item_id,
                $row->location_name ?: $row->location_id,
                (int) $row->qty,
                (int) $row->balance,
                $row->created_by ?: '-',
                $row->transaction_date,
            ));
        }

        foreach ($pairedOutboundRows as $row) {
            $this->line(sprintf(
                '  pasangan %s | %s | qty %d | id %s',
                $row->transaction_number,
                $row->item_id,
                (int) $row->qty,
                $row->id,
            ));
        }

        if ($candidateRows->isEmpty()) {
            $this->info('Tidak ada histori orphan yang memenuhi kriteria.');

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->warn('Dry-run: tidak ada data yang diubah. Jalankan ulang dengan --apply setelah verifikasi.');

            return self::SUCCESS;
        }

        $ids = $candidateRows->pluck('id')->all();
        if ($this->option('pair-outbound')) {
            $ids = array_merge($ids, $pairedOutboundRows->pluck('id')->all());
        }

        $deleted = DB::transaction(static function () use ($ids): int {
            return DB::table('inventory_movements')
                ->whereIn('id', $ids)
                ->where('source', 'TRANSFER_OUT')
                ->whereNotExists(function ($query): void {
                    $query
                        ->selectRaw('1')
                        ->from('inventory_transfers as t')
                        ->whereColumn('t.transfer_number', 'inventory_movements.transaction_number');
                })
                ->delete();
        });

        $this->info("Selesai. {$deleted} histori dihapus; tabel inventories tidak diubah.");

        return self::SUCCESS;
    }
}
