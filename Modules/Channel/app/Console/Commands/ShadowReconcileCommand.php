<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Modules\Channel\Models\ChannelShop;
use Modules\Sales\Models\SalesOrder;

/**
 * Membandingkan order shadow di sistem ini dengan data sistem lama (Jubelio).
 *
 * Sisi Jubelio dibaca dari CSV export, karena sistem ini belum punya kredensial
 * API ke sana. Kolom CSV yang dipakai: channel_order_no (wajib), grand_total,
 * channel_status. Header lain diabaikan.
 *
 * Tanpa --jubelio, command tetap berguna: ia mencetak ringkasan sisi kita saja
 * sebagai baseline harian.
 */
class ShadowReconcileCommand extends Command
{
    protected $signature = 'channel:shadow-reconcile
        {--shop= : Batasi ke satu shop_id marketplace}
        {--date= : Tanggal yang direkonsiliasi (WIB). Default kemarin}
        {--from= : Awal rentang (WIB), menggantikan --date}
        {--to= : Akhir rentang (WIB), menggantikan --date}
        {--jubelio= : Path CSV export order Jubelio untuk dibandingkan}
        {--tolerance=1 : Toleransi selisih nilai order (rupiah) sebelum dihitung mismatch}
        {--json= : Path file hasil. Default storage/app/shadow-reconcile/}';

    protected $description = 'Rekonsiliasi order shadow terhadap export sistem lama, untuk menilai kesiapan cutover.';

    private const TIMEZONE = 'Asia/Jakarta';

    public function handle(): int
    {
        [$from, $to] = $this->resolveRange();
        $tolerance = (float) $this->option('tolerance');

        $jubelioPath = $this->option('jubelio');
        $jubelioRows = null;

        if ($jubelioPath) {
            if (! is_readable($jubelioPath)) {
                $this->error("File Jubelio tidak terbaca: {$jubelioPath}");

                return self::FAILURE;
            }

            $jubelioRows = $this->readJubelioCsv($jubelioPath);

            if ($jubelioRows === null) {
                $this->error('CSV Jubelio wajib punya kolom channel_order_no.');

                return self::FAILURE;
            }
        }

        $shops = ChannelShop::with('channel')
            ->where('is_shadow_mode', true)
            ->when($this->option('shop'), fn ($query, $shopId) => $query->where('shop_id', $shopId))
            ->get();

        if ($shops->isEmpty()) {
            $this->info('Tidak ada toko dengan Shadow Mode.');

            return self::SUCCESS;
        }

        $this->info("Rekonsiliasi {$from->format('d/m/Y H:i')} - {$to->format('d/m/Y H:i')} WIB");

        $results = [];

        foreach ($shops as $shop) {
            $result = $this->reconcileShop($shop, $from, $to, $jubelioRows, $tolerance);
            $results[] = $result;
            $this->renderShop($result, $jubelioRows !== null);
        }

        $path = $this->writeResults($results, $from, $to);
        $this->line("Hasil disimpan: {$path}");

        return self::SUCCESS;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveRange(): array
    {
        $tz = self::TIMEZONE;

        if ($this->option('from') || $this->option('to')) {
            $from = Carbon::parse($this->option('from') ?: 'today', $tz)->startOfDay();
            $to = Carbon::parse($this->option('to') ?: 'today', $tz)->endOfDay();

            return [$from, $to];
        }

        $date = Carbon::parse($this->option('date') ?: 'yesterday', $tz);

        return [$date->copy()->startOfDay(), $date->copy()->endOfDay()];
    }

    /**
     * @param  array<string, array{grand_total: ?float, channel_status: ?string}>|null  $jubelioRows
     */
    private function reconcileShop(ChannelShop $shop, Carbon $from, Carbon $to, ?array $jubelioRows, float $tolerance): array
    {
        $ours = SalesOrder::query()
            ->onlyShadow()
            ->where('channel_shop_id', $shop->shop_id)
            ->whereBetween('transaction_date', [$from->copy()->utc(), $to->copy()->utc()])
            ->get(['channel_order_no', 'grand_total', 'channel_status'])
            ->keyBy(fn ($order) => (string) $order->channel_order_no);

        $result = [
            'shop_id'      => $shop->shop_id,
            'shop_name'    => $shop->shop_name,
            'channel'      => $shop->channel->code ?? null,
            'cutoff'       => optional($shop->shadow_started_at)->toIso8601String(),
            'orders_ours'  => $ours->count(),
            'value_ours'   => round((float) $ours->sum('grand_total'), 2),
        ];

        if ($jubelioRows === null) {
            return $result;
        }

        $missingInOurs = [];
        $missingInJubelio = [];
        $valueMismatch = [];
        $statusMismatch = [];
        $matched = 0;

        foreach ($jubelioRows as $orderNo => $row) {
            $our = $ours->get($orderNo);

            if (! $our) {
                $missingInOurs[] = $orderNo;
                continue;
            }

            $matched++;

            if ($row['grand_total'] !== null && abs((float) $our->grand_total - $row['grand_total']) > $tolerance) {
                $valueMismatch[] = [
                    'channel_order_no' => $orderNo,
                    'ours'             => (float) $our->grand_total,
                    'jubelio'          => $row['grand_total'],
                ];
            }

            if ($row['channel_status'] && $our->channel_status && strcasecmp((string) $our->channel_status, $row['channel_status']) !== 0) {
                $statusMismatch[] = [
                    'channel_order_no' => $orderNo,
                    'ours'             => (string) $our->channel_status,
                    'jubelio'          => $row['channel_status'],
                ];
            }
        }

        foreach ($ours as $orderNo => $_) {
            if (! array_key_exists($orderNo, $jubelioRows)) {
                $missingInJubelio[] = $orderNo;
            }
        }

        $jubelioCount = count($jubelioRows);
        $cleanMatches = $matched - count($valueMismatch) - count($statusMismatch);
        $denominator = max($jubelioCount, $ours->count());

        return $result + [
            'orders_jubelio'     => $jubelioCount,
            'matched'            => $matched,
            'missing_in_ours'    => $missingInOurs,
            'missing_in_jubelio' => $missingInJubelio,
            'value_mismatch'     => $valueMismatch,
            'status_mismatch'    => $statusMismatch,
            'match_rate'         => $denominator > 0 ? round(max($cleanMatches, 0) / $denominator * 100, 2) : null,
        ];
    }

    private function renderShop(array $result, bool $compared): void
    {
        $this->newLine();
        $this->line("<info>{$result['shop_name']}</info> ({$result['channel']})");

        if (! $compared) {
            $this->table(
                ['Order (shadow)', 'Nilai'],
                [[$result['orders_ours'], number_format($result['value_ours'], 2)]],
            );

            return;
        }

        $this->table(
            ['Metrik', 'Nilai'],
            [
                ['Order di sistem ini', $result['orders_ours']],
                ['Order di Jubelio', $result['orders_jubelio']],
                ['Cocok', $result['matched']],
                ['Tidak ada di sistem ini', count($result['missing_in_ours'])],
                ['Tidak ada di Jubelio', count($result['missing_in_jubelio'])],
                ['Selisih nilai', count($result['value_mismatch'])],
                ['Selisih status', count($result['status_mismatch'])],
                ['Match rate', $result['match_rate'] === null ? '-' : $result['match_rate'] . '%'],
            ],
        );

        foreach (['missing_in_ours' => 'Tidak ada di sistem ini', 'missing_in_jubelio' => 'Tidak ada di Jubelio'] as $key => $label) {
            if ($result[$key] === []) {
                continue;
            }

            $sample = array_slice($result[$key], 0, 10);
            $suffix = count($result[$key]) > 10 ? ' (10 pertama)' : '';
            $this->warn("{$label}{$suffix}: " . implode(', ', $sample));
        }
    }

    /**
     * @return array<string, array{grand_total: ?float, channel_status: ?string}>|null
     */
    private function readJubelioCsv(string $path): ?array
    {
        $handle = fopen($path, 'rb');
        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            return null;
        }

        $columns = array_flip(array_map(fn ($name) => strtolower(trim((string) $name)), $header));

        if (! isset($columns['channel_order_no'])) {
            fclose($handle);

            return null;
        }

        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            $orderNo = trim((string) ($row[$columns['channel_order_no']] ?? ''));

            if ($orderNo === '') {
                continue;
            }

            $rawTotal = isset($columns['grand_total']) ? trim((string) ($row[$columns['grand_total']] ?? '')) : '';
            $rawStatus = isset($columns['channel_status']) ? trim((string) ($row[$columns['channel_status']] ?? '')) : '';

            $rows[$orderNo] = [
                'grand_total'    => $rawTotal === '' ? null : (float) str_replace(',', '', $rawTotal),
                'channel_status' => $rawStatus === '' ? null : $rawStatus,
            ];
        }

        fclose($handle);

        return $rows;
    }

    private function writeResults(array $results, Carbon $from, Carbon $to): string
    {
        $path = $this->option('json')
            ?: 'shadow-reconcile/' . $from->format('Ymd') . '-' . $to->format('Ymd') . '.json';

        Storage::put($path, json_encode([
            'from'         => $from->toIso8601String(),
            'to'           => $to->toIso8601String(),
            'generated_at' => now()->toIso8601String(),
            'shops'        => $results,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return Storage::path($path);
    }
}
