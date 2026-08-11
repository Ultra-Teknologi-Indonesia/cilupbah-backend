<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Exceptions\TokenExpiredException;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\LazadaAuthService;
use Modules\Channel\Services\LazadaClient;
use Modules\Channel\Services\LazadaProductService;
use Modules\Channel\Services\ShopeeProductService;
use Modules\Channel\Services\TikTokProductService;
use Modules\Channel\Services\WooCommerceClient;

class AuditChannelProductActivity extends Command
{
    protected $signature = 'channel:audit-active-products
        {--channel= : Batasi ke satu channel (shopee|tiktok|lazada|woocommerce)}
        {--shop= : Batasi ke satu shop_id}
        {--reasons=3 : Berapa contoh alasan non-aktif yang ditampilkan per status}';

    protected $description = 'DRY-RUN: audit distribusi status produk di API channel (aktif vs arsip). Tidak menulis apa pun.';

    private const ACTIVE_STATUSES = [
        'shopee' => ['normal'],
        'tiktok' => ['activate'],
        'lazada' => ['active', 'live'],
        'woocommerce' => ['publish'],
    ];

    public function handle(): int
    {
        $channelFilter = strtolower((string) $this->option('channel')) ?: null;
        $shopFilter = (string) $this->option('shop') ?: null;
        $reasonsN = max(0, (int) $this->option('reasons'));

        $shops = ChannelShop::with('channel')
            ->where('is_active', true)
            ->when($shopFilter, fn ($q) => $q->where('shop_id', $shopFilter))
            ->get()
            ->filter(fn ($s) => $s->channel && (! $channelFilter || strtolower($s->channel->code) === $channelFilter));

        if ($shops->isEmpty()) {
            $this->warn('Tidak ada channel shop aktif yang cocok. (channel/shop filter atau memang belum ada toko terhubung.)');

            return self::SUCCESS;
        }

        $grand = ['total' => 0, 'active' => 0, 'excluded' => 0];

        foreach ($shops as $shop) {
            $code = strtolower($shop->channel->code);
            $label = "{$shop->channel->code} · {$shop->name} (shop_id={$shop->shop_id})";
            $this->line('');
            $this->info("=== {$label} ===");

            if (! $shop->access_token && $code !== 'woocommerce') {
                $this->warn('  ⚠ Tidak ada access_token — lewati (perlu re-auth).');

                continue;
            }

            try {
                $statuses = $this->fetchStatuses($code, $shop);
            } catch (TokenExpiredException $e) {
                $this->error('  ⚠ Token kedaluwarsa — perlu re-auth toko ini sebelum dry-run.');

                continue;
            } catch (\Throwable $e) {
                $this->error('  ✗ Gagal ambil status: ' . $e->getMessage());

                continue;
            }

            $this->report($code, $statuses, $reasonsN, $grand);
        }

        $this->line('');
        $this->info('================ RINGKASAN GLOBAL ================');
        $this->line(sprintf(
            '  Total produk    : %d',
            $grand['total'],
        ));
        $this->line(sprintf(
            '  Aktif (aman)    : %d',
            $grand['active'],
        ));
        $this->line(sprintf(
            '  Akan disaring   : %d  ← non-aktif/arsip yang saat ini masih ikut ter-download',
            $grand['excluded'],
        ));

        return self::SUCCESS;
    }

    private function fetchStatuses(string $code, ChannelShop $shop): array
    {
        return match ($code) {
            'shopee' => app(ShopeeProductService::class)->fetchProductStatuses($shop->shop_id),
            'tiktok' => app(TikTokProductService::class)->fetchProductStatuses($shop->shop_id),
            'lazada' => $this->fetchLazadaRaw($shop),
            'woocommerce' => $this->fetchWooRaw($shop),
            default => [],
        };
    }

    private function fetchLazadaRaw(ChannelShop $shop): array
    {
        $client = app(LazadaClient::class);
        $auth = app(LazadaAuthService::class);
        $out = [];
        $offset = 0;
        $limit = 20;

        do {
            $params = ['filter' => 'all', 'offset' => $offset, 'limit' => $limit];
            try {
                $res = $client->request('GET', '/products/get', $params, $shop->access_token);
            } catch (TokenExpiredException $e) {
                $auth->refreshStoreToken((string) $shop->id);
                $shop->refresh();
                $res = $client->request('GET', '/products/get', $params, $shop->access_token);
            }

            $products = $res['data']['products'] ?? [];
            foreach ($products as $item) {
                $extId = (string) ($item['item_id'] ?? '');
                if ($extId === '') {
                    continue;
                }
                $live = strtolower((string) ($item['status'] ?? ''));
                $qc = strtolower((string) ($item['qc_status'] ?? ''));
                $reason = $item['reasons'] ?? $item['reason'] ?? null;
                $out[$extId] = [
                    'status' => $live !== '' ? $live : $qc,
                    'reason' => is_array($reason) ? json_encode($reason) : $reason,
                    'qc' => $qc,
                ];
            }

            $offset += $limit;
        } while (count($products) === $limit);

        return $out;
    }

    private function fetchWooRaw(ChannelShop $shop): array
    {
        $client = app(WooCommerceClient::class);
        $out = [];

        foreach ($client->paginate($shop, 'products', [], 100, 100) as $p) {
            $extId = (string) ($p['id'] ?? '');
            if ($extId === '') {
                continue;
            }
            $out[$extId] = [
                'status' => strtolower((string) ($p['status'] ?? '')),
                'reason' => null,
            ];
        }

        return $out;
    }

    private function report(string $code, array $statuses, int $reasonsN, array &$grand): void
    {
        $total = count($statuses);
        if ($total === 0) {
            $this->warn('  (Tidak ada produk dikembalikan API.)');

            return;
        }

        $activeSet = self::ACTIVE_STATUSES[$code] ?? [];
        $dist = [];
        $reasons = [];
        $active = 0;
        $excluded = 0;

        foreach ($statuses as $row) {
            $st = strtolower((string) ($row['status'] ?? '')) ?: '(kosong)';
            $qcLabel = isset($row['qc']) && $row['qc'] !== '' ? " [qc={$row['qc']}]" : '';
            $key = $st . $qcLabel;
            $dist[$key] = ($dist[$key] ?? 0) + 1;

            $isActive = in_array($st, $activeSet, true);
            if ($isActive) {
                $active++;
            } else {
                $excluded++;
                if (! empty($row['reason']) && count($reasons[$key] ?? []) < $reasonsN) {
                    $reasons[$key][] = (string) $row['reason'];
                }
            }
        }

        arsort($dist);

        $this->line("  Total: {$total}   Aktif: {$active}   Non-aktif/arsip: {$excluded}");
        $this->line('  Distribusi status (mentah dari API):');
        foreach ($dist as $status => $count) {
            $bareStatus = trim(explode('[', $status)[0]);
            $mark = in_array($bareStatus, $activeSet, true) ? '✓ aktif ' : '✗ saring';
            $this->line(sprintf('    %s  %-24s %6d', $mark, $status, $count));
            foreach ($reasons[$status] ?? [] as $r) {
                $this->line('              alasan: ' . mb_strimwidth($r, 0, 100, '…'));
            }
        }

        $grand['total'] += $total;
        $grand['active'] += $active;
        $grand['excluded'] += $excluded;
    }
}
