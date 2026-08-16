<?php

namespace Modules\Channel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Models\ChannelWebhookInbox;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Sales\Services\SalesOrderService;

class CleanOrderCutoverCommand extends Command
{
    protected $signature = 'channel:clean-order-cutover
                            {file=/tmp/salesorder-latest.csv : Path to Jubelio open orders CSV file}
                            {--cutoff=2026-08-16 14:34:00 : Cutoff timestamp in UTC (equivalent to 21:34 WIB)}
                            {--disable-stock-push : Ensure stock push is disabled across all channel shops}
                            {--purge-historical-inbox : Purge webhooks older than cutoff not in CSV whitelist}
                            {--commit : Apply changes to database (default is DRY-RUN)}';

    protected $description = 'Clean cutover of active sales orders from Jubelio CSV whitelist and new post-cutoff webhooks, allocating on_order without pushing stock to channels.';

    public function handle(
        ShopeeOrderService $shopeeOrderService,
        TikTokOrderService $tiktokOrderService,
        LazadaOrderService $lazadaOrderService,
        SalesOrderService $salesOrderService
    ): int {
        $filePath = $this->argument('file');
        $cutoff = $this->option('cutoff');
        $commit = (bool) $this->option('commit');
        $disableStockPush = (bool) $this->option('disable-stock-push');
        $purgeHistoricalInbox = (bool) $this->option('purge-historical-inbox');

        if (! file_exists($filePath)) {
            $this->error("❌ File CSV {$filePath} tidak ditemukan.");
            return self::FAILURE;
        }

        $this->info('====================================================================================================');
        $this->info('  CLEAN ORDER CUTOVER & WEBHOOK INTAKE PIPELINE');
        $this->info('  Mode: ' . ($commit ? '<fg=green;options=bold>LIVE COMMIT (PERUBAHAN DITERAPKAN KE DATABASE)</>' : '<fg=yellow;options=bold>DRY-RUN (SIMULASI AMAN - TANPA PERUBAHAN)</>'));
        $this->info('  Cutoff Time (UTC): ' . $cutoff);
        $this->info('====================================================================================================');

        // 1. SAFETY GATE: DISABLE STOCK PUSH
        if ($disableStockPush || $commit) {
            $activePushes = DB::table('channel_shops')->where('stock_push_enabled', true)->count();
            if ($activePushes > 0) {
                if ($commit) {
                    DB::table('channel_shops')->update(['stock_push_enabled' => false]);
                    $this->info("🛡️ Safety Gate: {$activePushes} toko berhasil dinonaktifkan fitur stock push-nya.");
                } else {
                    $this->warn("🛡️ [DRY-RUN] Safety Gate: {$activePushes} toko akan dinonaktifkan fitur stock push-nya saat commit.");
                }
            } else {
                $this->info('🛡️ Safety Gate: Seluruh toko sudah dalam keadaan stock push disabled (AMAN).');
            }
        }

        // 2. BACA WHITELIST DARI CSV JUBELIO
        $handle = fopen($filePath, 'r');
        $header = fgetcsv($handle, 0, ',', '"', '\\');

        $whitelistOrders = [];
        $csvCount = 0;
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $no = trim($row[0] ?? '');
            if (! $no) continue;

            $channel = 'unknown';
            $cleanId = $no;
            if (str_starts_with($no, 'SP-')) {
                $channel = 'shopee';
                $cleanId = substr($no, 3);
            } elseif (str_starts_with($no, 'TT-') || str_starts_with($no, 'TP-')) {
                $channel = 'tiktok';
                $cleanId = preg_replace('/^(TT|TP)-(\d+)(-\d+)?$/', '$2', $no);
            } elseif (str_starts_with($no, 'LZ-')) {
                $channel = 'lazada';
                $cleanId = substr($no, 3);
            }

            $whitelistOrders[$cleanId] = [
                'full_no'  => $no,
                'channel'  => $channel,
                'clean_id' => $cleanId,
                'customer' => $row[2] ?? '',
                'shop'     => $row[6] ?? '',
                'total'    => (float) str_replace(',', '', $row[7] ?? '0'),
            ];
            $csvCount++;
        }
        fclose($handle);

        $this->info(sprintf('📂 Membaca %d pesanan dari file CSV whitelist (%s).', $csvCount, $filePath));

        // 3. SCANNING & FILTERING WEBHOOK INBOX
        $this->line('🔍 Memindai tabel channel_webhook_inbox...');

        $keepWebhookIds = [];
        $purgeWebhookIds = [];
        $matchedOrders = [];
        $postCutoffOrders = [];
        $totalInbox = 0;

        DB::table('channel_webhook_inbox')
            ->orderBy('id', 'asc')
            ->chunk(2000, function ($rows) use (&$whitelistOrders, $cutoff, &$keepWebhookIds, &$purgeWebhookIds, &$matchedOrders, &$postCutoffOrders, &$totalInbox) {
                foreach ($rows as $row) {
                    $totalInbox++;
                    $payload = json_decode($row->payload, true) ?: [];
                    $orderSn = null;

                    if ($row->channel === 'shopee') {
                        $orderSn = $payload['data']['ordersn'] ?? $payload['data']['order_sn'] ?? null;
                    } elseif ($row->channel === 'tiktok') {
                        $orderSn = $payload['data']['order_id'] ?? null;
                    }

                    $isPostCutoff = $row->received_at >= $cutoff;
                    $isWhitelisted = $orderSn && isset($whitelistOrders[$orderSn]);

                    if ($isWhitelisted) {
                        $keepWebhookIds[] = $row->id;
                        if (! isset($matchedOrders[$orderSn])) {
                            $matchedOrders[$orderSn] = [
                                'channel'     => $row->channel,
                                'order_sn'    => $orderSn,
                                'received_at' => $row->received_at,
                                'payload'     => $payload,
                                'source'      => 'csv_whitelist',
                            ];
                        }
                    } elseif ($isPostCutoff) {
                        $keepWebhookIds[] = $row->id;
                        if ($orderSn && ! isset($postCutoffOrders[$orderSn])) {
                            $postCutoffOrders[$orderSn] = [
                                'channel'     => $row->channel,
                                'order_sn'    => $orderSn,
                                'received_at' => $row->received_at,
                                'payload'     => $payload,
                                'source'      => 'post_cutoff_live',
                            ];
                        }
                    } else {
                        $purgeWebhookIds[] = $row->id;
                    }
                }
            });

        $this->info("📊 Hasil Pemindaian Webhook Inbox (Total: {$totalInbox} baris):");
        $this->line("   - Webhook yang DIPERTAHANKAN : " . count($keepWebhookIds) . " baris");
        $this->line("   - Webhook LAMA yang DISISIKAN: " . count($purgeWebhookIds) . " baris");
        $this->line("   - Pesanan CSV cocok di inbox : " . count($matchedOrders) . " pesanan");
        $this->line("   - Pesanan Baru Post-Cutoff   : " . count($postCutoffOrders) . " pesanan");

        // 4. PURGE HISTORICAL WEBHOOKS JIKA DIMINTA
        if ($purgeHistoricalInbox && count($purgeWebhookIds) > 0) {
            if ($commit) {
                $this->warn('🧹 Membersihkan ' . count($purgeWebhookIds) . ' webhook historis lama dari database...');
                foreach (array_chunk($purgeWebhookIds, 1000) as $chunk) {
                    DB::table('channel_webhook_inbox')->whereIn('id', $chunk)->delete();
                }
                $this->info('✅ Pembersihan webhook lama selesai.');
            } else {
                $this->warn('🧹 [DRY-RUN] ' . count($purgeWebhookIds) . ' webhook historis lama akan dihapus saat commit.');
            }
        }

        // 5. INGESTION ORDER KE SALES_ORDERS
        $allOrdersToProcess = array_merge($matchedOrders, $postCutoffOrders);
        $this->info("🚀 Mempersiapkan proses intake untuk " . count($allOrdersToProcess) . " pesanan...");

        $successCount = 0;
        $failedCount = 0;

        if ($commit) {
            $bar = $this->output->createProgressBar(count($allOrdersToProcess));
            $bar->start();

            foreach ($allOrdersToProcess as $orderInfo) {
                try {
                    $channel = $orderInfo['channel'];
                    $payload = $orderInfo['payload'];

                    // Eksekusi intake pesanan melalui controller / service resmi
                    if ($channel === 'shopee') {
                        $shopId = (string) ($payload['shop_id'] ?? '');
                        $data = $payload['data'] ?? [];
                        $orderSn = (string) ($data['ordersn'] ?? $data['order_sn'] ?? '');
                        if ($shopId && $orderSn) {
                            $shopeeOrderService->pullOrderById($shopId, $orderSn);
                        }
                    } elseif ($channel === 'tiktok') {
                        $shopId = (string) ($payload['shop_id'] ?? '');
                        $data = $payload['data'] ?? [];
                        $orderId = (string) ($data['order_id'] ?? '');
                        if ($shopId && $orderId) {
                            $tiktokOrderService->pullOrderById($shopId, $orderId);
                        }
                    }
                    $successCount++;
                } catch (\Throwable $e) {
                    $failedCount++;
                    Log::warning("Gagal intake order {$orderInfo['order_sn']}: " . $e->getMessage());
                }
                $bar->advance();
            }
            $bar->finish();
            $this->newLine();

            // PULL 10 ORDER LAZADA LIVE
            $this->info('📦 Menarik pesanan aktif Lazada via OpenAPI...');
            $lazadaOrders = [
                '2140417937409228', '2140409930409228', '2140321288409228', '2139626490409228',
                '2138769399409228', '2138706323409228', '2138656602409228', '2138548981409228',
                '2137651036409228', '2137604510409228'
            ];

            $lzShops = DB::table('channel_shops')
                ->whereIn('channel_id', DB::table('channels')->select('id')->where('code', 'lazada'))
                ->where('is_active', true)
                ->get();

            foreach ($lazadaOrders as $lzOrderNo) {
                foreach ($lzShops as $shop) {
                    try {
                        $lazadaOrderService->pullOrderById($shop->shop_id, $lzOrderNo);
                    } catch (\Throwable $e) {
                        // ignore if not from this shop
                    }
                }
            }
            $this->info('✅ Penarikan pesanan Lazada selesai.');
        } else {
            $this->info('ℹ️ [DRY-RUN] Melewati eksekusi API pull & DB insert.');
        }

        // 6. RINGKASAN DATA
        $this->newLine();
        $this->info('====================================================================================================');
        $this->info('  STATUS SALES ORDERS DI DATABASE');
        $this->info('====================================================================================================');

        $totalSalesOrders = DB::table('sales_orders')->count();
        $reservedOrders = DB::table('sales_orders')->where('status', 'reserved')->count();
        $pendingOrders = DB::table('sales_orders')->where('status', 'pending')->count();
        $packedOrders = DB::table('sales_orders')->where('status', 'packed')->count();
        $shippedOrders = DB::table('sales_orders')->where('status', 'shipped')->count();
        $cancelledOrders = DB::table('sales_orders')->where('status', 'cancelled')->count();

        $this->table(
            ['Status / Tab', 'Jumlah Pesanan', 'Keterangan'],
            [
                ['Siap Proses (reserved)', $reservedOrders, 'Pesanan aktif dibayar siap diproses gudang'],
                ['Belum Dibayar (pending)', $pendingOrders, 'Pesanan checkout belum bayar (stok ter-booking)'],
                ['Dikemas (packed)', $packedOrders, 'Pesanan sudah ada resi / dipacking'],
                ['Terkirim (shipped)', $shippedOrders, 'Pesanan dalam pengiriman'],
                ['Batal (cancelled)', $cancelledOrders, 'Pesanan dibatalkan'],
                ['TOTAL SALES ORDERS', $totalSalesOrders, 'Seluruh pesanan di database internal'],
            ]
        );

        $totalOnOrder = (int) DB::table('inventories')->sum('on_order');
        $this->info("🔒 Total Stok Komitmen (on_order): {$totalOnOrder} pcs dialokasikan.");

        return self::SUCCESS;
    }
}
