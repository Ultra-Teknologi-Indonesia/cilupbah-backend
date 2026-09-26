<?php

declare(strict_types=1);

namespace FlashSaleSimulation;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Models\ChannelSyncSetting;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariantChannelMapping;
use Symfony\Component\Process\Process;

final class SimulationRepository
{
    public function initialize(int $shops, int $skus): void
    {
        if (Schema::hasTable('simulation_cases') || DB::table('sales_orders')->exists() || DB::table('channel_shops')->exists()) {
            throw new \RuntimeException('Initialization requires a new empty simulation database. Refusing to reset existing data.');
        }
        DB::statement('CREATE TABLE simulation_cases (
            seq bigint PRIMARY KEY, order_no varchar(40) UNIQUE NOT NULL,
            offered_at timestamptz NOT NULL, sent_at timestamptz, webhook_accepted_at timestamptz, http_status integer,
            lag_seconds double precision NOT NULL DEFAULT 0,
            batch_id uuid, requested_at timestamptz,
            outbox_id uuid, stock_version bigint, stock_requested_at timestamptz,
            error text, awb_at timestamptz
        )');
        DB::statement('CREATE INDEX simulation_unbatched ON simulation_cases(seq) WHERE batch_id IS NULL');
        DB::statement('CREATE INDEX simulation_batch ON simulation_cases(batch_id)');
        DB::statement('CREATE INDEX simulation_outbox ON simulation_cases(outbox_id, stock_version)');
        DB::statement('CREATE TABLE simulation_catalog (shop_number integer, sku integer, mapping_id uuid NOT NULL, PRIMARY KEY(shop_number, sku))');
        DB::statement('CREATE TABLE simulation_meta (key text PRIMARY KEY, value text NOT NULL)');
        DB::unprepared("CREATE FUNCTION simulation_awb_time() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.tracking_number IS NOT NULL AND NEW.tracking_number <> '' THEN
                    UPDATE simulation_cases SET awb_at=coalesce(awb_at,clock_timestamp()) WHERE order_no=NEW.channel_order_no;
                END IF;
                RETURN NEW;
            END $$");
        DB::unprepared('CREATE TRIGGER simulation_awb_time AFTER INSERT OR UPDATE OF tracking_number ON sales_orders FOR EACH ROW EXECUTE FUNCTION simulation_awb_time()');
        $user = User::create(['name' => 'Simulation', 'email' => 'simulation@example.invalid', 'password' => bcrypt(Str::random(40))]);
        DB::table('simulation_meta')->insert(['key' => 'user_id', 'value' => $user->id]);
        $channel = Channel::create(['code' => 'shopee', 'name' => 'SIM Shopee', 'is_active' => true]);
        ChannelSyncSetting::query()->updateOrCreate([], ['sync_enabled' => true, 'auto_pause_at' => now()->addYears(10)]);
        $category = DB::table('categories')->insertGetId(['name' => 'SIM', 'created_at' => now(), 'updated_at' => now()]);
        $location = (string) Str::uuid();
        DB::table('locations')->insert(['id' => $location, 'location_code' => 'SIM-WH', 'location_name' => 'SIM Warehouse',
            'location_type' => 'warehouse', 'is_warehouse' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $bin = (string) Str::uuid();
        DB::table('location_bins')->insert(['id' => $bin, 'location_id' => $location, 'bin_final_code' => 'SIM-RACK',
            'is_inbound' => false, 'created_at' => now(), 'updated_at' => now()]);
        $products = [];
        for ($sku = 1; $sku <= $skus; $sku++) {
            $product = (string) Str::uuid();
            $variant = (string) Str::uuid();
            DB::table('products')->insert(['id' => $product, 'category_id' => $category, 'name' => 'SIM '.$sku, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('product_variants')->insert(['id' => $variant, 'product_id' => $product, 'sku' => 'SIM-SKU-'.$sku, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('inventories')->insert(['id' => (string) Str::uuid(), 'item_id' => $variant, 'location_id' => $location, 'bin_id' => $bin,
                'on_hand' => 20000000, 'available' => 20000000, 'created_at' => now(), 'updated_at' => now()]);
            $products[$sku] = [$product, $variant];
        }
        for ($shopNumber = 1; $shopNumber <= $shops; $shopNumber++) {
            $shop = ChannelShop::create([
                'channel_id' => $channel->id, 'shop_id' => (string) (900000 + $shopNumber), 'shop_name' => 'SIM '.$shopNumber,
                'access_token' => 'simulation-only', 'refresh_token' => 'simulation-only', 'token_expires_at' => now()->addYears(1),
                'is_active' => true, 'order_sync_enabled' => true, 'is_shadow_mode' => false,
                'stock_push_enabled' => true, 'price_push_enabled' => true, 'fulfillment_push_enabled' => true,
                'stock_source_mode' => 'total', 'handover_method' => 'dropoff',
            ]);
            foreach ($products as $sku => [$product, $variant]) {
                $mapping = ProductChannelMapping::create(['product_id' => $product, 'channel_shop_id' => $shop->id, 'external_product_id' => (string) $sku, 'sync_status' => 'synced']);
                ProductVariantChannelMapping::create(['product_channel_mapping_id' => $mapping->id, 'variant_id' => $variant,
                    'external_sku_id' => (string) $sku, 'channel_seller_sku' => 'SIM-SKU-'.$sku, 'sync_enabled' => true]);
                DB::table('simulation_catalog')->insert(['shop_number' => $shopNumber, 'sku' => $sku, 'mapping_id' => $mapping->id]);
            }
        }
    }

    public function begin(int $seq, float $offered, float $lag): void
    {
        DB::table('simulation_cases')->insert([
            'seq' => $seq, 'order_no' => sprintf('SIM%012d', $seq),
            'offered_at' => date('c', (int) $offered), 'sent_at' => now(), 'lag_seconds' => $lag,
        ]);
    }

    public function update(int $seq, array $changes): void
    {
        DB::table('simulation_cases')->where('seq', $seq)->update($changes);
    }

    public function mapping(int $shop, int $sku): ProductChannelMapping
    {
        return ProductChannelMapping::findOrFail(DB::table('simulation_catalog')->where('shop_number', $shop)->where('sku', $sku)->value('mapping_id'));
    }

    public function user(): User
    {
        return User::findOrFail(DB::table('simulation_meta')->where('key', 'user_id')->value('value'));
    }

    public function unbatched(int $limit): Collection
    {
        return DB::table('simulation_cases as c')->join('sales_orders as o', 'o.channel_order_no', '=', 'c.order_no')
            ->where('o.source', 'shopee')->whereNull('c.batch_id')->orderBy('c.seq')
            ->limit($limit)->get(['c.seq', 'o.id']);
    }

    public function attachBatch(array $seqs, string $batch): void
    {
        DB::table('simulation_cases')->whereIn('seq', $seqs)->update(['batch_id' => $batch, 'requested_at' => now()]);
    }

    public function snapshot(bool $final = false): array
    {
        $result = [
            'time' => now()->toIso8601String(),
            'cases' => DB::selectOne('SELECT count(*) AS offered, count(*) FILTER (WHERE http_status=200) AS http_accepted,
                count(batch_id) AS label_requested, count(outbox_id) AS stock_requested,
                count(*) FILTER (WHERE error IS NOT NULL OR http_status<>200) AS producer_errors,
                coalesce(max(lag_seconds),0) AS producer_max_lag_seconds FROM simulation_cases'),
            'orders' => DB::table('sales_orders')->where('source', 'shopee')->count(),
            'awb_ready' => DB::table('sales_orders')->whereNotNull('tracking_number')->where('tracking_number', '<>', '')->count(),
            'items' => DB::table('bulk_shipping_label_items')->selectRaw('status, count(*) AS total')->groupBy('status')->get(),
            'batches' => DB::table('bulk_shipping_label_batches')->selectRaw('status, count(*) AS total, sum(total_count) AS labels, sum(failed_count) AS failed')->groupBy('status')->get(),
            'inbox' => DB::table('channel_webhook_inbox')->selectRaw('status, count(*) AS total')->groupBy('status')->get(),
            'inbox_deferred' => DB::table('channel_webhook_inbox')->where('error', 'ilike', '%DEFER%')->count(),
            'inbox_oldest_unfinished' => DB::table('channel_webhook_inbox')->where('status', 'RECEIVED')->min('received_at'),
            'stock' => DB::table('channel_stock_sync_outbox')->selectRaw('status, count(*) AS total')->groupBy('status')->get(),
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'database' => DB::selectOne("SELECT pg_database_size(current_database()) AS bytes,
                (SELECT count(*) FROM pg_stat_activity WHERE datname=current_database()) AS connections,
                (SELECT count(*) FROM pg_stat_activity WHERE datname=current_database() AND wait_event_type='Lock') AS lock_waiters"),
        ];
        if ($final) {

            $latency = DB::selectOne("WITH timings AS (
                SELECT
                    extract(epoch FROM (c.webhook_accepted_at - c.sent_at)) AS webhook_accepted,
                    extract(epoch FROM (o.created_at - c.sent_at)) AS order_recorded,
                    extract(epoch FROM (c.awb_at - c.requested_at)) AS awb_ready,
                    extract(epoch FROM (i.downloaded_at - c.requested_at)) AS label_downloaded,
                    extract(epoch FROM (b.finished_at - c.requested_at)) AS merged_pdf,
                    extract(epoch FROM (s.completed_at - c.stock_requested_at)) AS stock_converged
                FROM simulation_cases c
                LEFT JOIN sales_orders o ON o.channel_order_no = c.order_no AND o.source = 'shopee'
                LEFT JOIN bulk_shipping_label_batches b ON b.id = c.batch_id
                LEFT JOIN bulk_shipping_label_items i ON i.batch_id = c.batch_id AND i.order_id = o.id
                LEFT JOIN channel_stock_sync_outbox s ON s.id = c.outbox_id
            )
            SELECT jsonb_build_object(
                'webhook_accepted', (SELECT jsonb_build_object(
                    'samples', count(webhook_accepted), 'min', min(webhook_accepted), 'average', avg(webhook_accepted),
                    'p50', percentile_cont(0.50) WITHIN GROUP (ORDER BY webhook_accepted),
                    'p95', percentile_cont(0.95) WITHIN GROUP (ORDER BY webhook_accepted),
                    'p99', percentile_cont(0.99) WITHIN GROUP (ORDER BY webhook_accepted), 'max', max(webhook_accepted)
                ) FROM timings WHERE webhook_accepted IS NOT NULL),
                'order_recorded', (SELECT jsonb_build_object(
                    'samples', count(order_recorded), 'min', min(order_recorded), 'average', avg(order_recorded),
                    'p50', percentile_cont(0.50) WITHIN GROUP (ORDER BY order_recorded),
                    'p95', percentile_cont(0.95) WITHIN GROUP (ORDER BY order_recorded),
                    'p99', percentile_cont(0.99) WITHIN GROUP (ORDER BY order_recorded), 'max', max(order_recorded)
                ) FROM timings WHERE order_recorded IS NOT NULL),
                'awb_ready', (SELECT jsonb_build_object(
                    'samples', count(awb_ready), 'min', min(awb_ready), 'average', avg(awb_ready),
                    'p50', percentile_cont(0.50) WITHIN GROUP (ORDER BY awb_ready),
                    'p95', percentile_cont(0.95) WITHIN GROUP (ORDER BY awb_ready),
                    'p99', percentile_cont(0.99) WITHIN GROUP (ORDER BY awb_ready), 'max', max(awb_ready)
                ) FROM timings WHERE awb_ready IS NOT NULL),
                'label_downloaded', (SELECT jsonb_build_object(
                    'samples', count(label_downloaded), 'min', min(label_downloaded), 'average', avg(label_downloaded),
                    'p50', percentile_cont(0.50) WITHIN GROUP (ORDER BY label_downloaded),
                    'p95', percentile_cont(0.95) WITHIN GROUP (ORDER BY label_downloaded),
                    'p99', percentile_cont(0.99) WITHIN GROUP (ORDER BY label_downloaded), 'max', max(label_downloaded)
                ) FROM timings WHERE label_downloaded IS NOT NULL),
                'merged_pdf', (SELECT jsonb_build_object(
                    'samples', count(merged_pdf), 'min', min(merged_pdf), 'average', avg(merged_pdf),
                    'p50', percentile_cont(0.50) WITHIN GROUP (ORDER BY merged_pdf),
                    'p95', percentile_cont(0.95) WITHIN GROUP (ORDER BY merged_pdf),
                    'p99', percentile_cont(0.99) WITHIN GROUP (ORDER BY merged_pdf), 'max', max(merged_pdf)
                ) FROM timings WHERE merged_pdf IS NOT NULL),
                'stock_converged', (SELECT jsonb_build_object(
                    'samples', count(stock_converged), 'min', min(stock_converged), 'average', avg(stock_converged),
                    'p50', percentile_cont(0.50) WITHIN GROUP (ORDER BY stock_converged),
                    'p95', percentile_cont(0.95) WITHIN GROUP (ORDER BY stock_converged),
                    'p99', percentile_cont(0.99) WITHIN GROUP (ORDER BY stock_converged), 'max', max(stock_converged)
                ) FROM timings WHERE stock_converged IS NOT NULL)
            ) AS values");
            $result['latency_seconds'] = json_decode($latency->values, true, 512, JSON_THROW_ON_ERROR);
            $result['missing_orders'] = DB::table('simulation_cases as c')->leftJoin('sales_orders as o', 'o.channel_order_no', '=', 'c.order_no')->whereNull('o.id')->count();
            $result['incomplete_stock_versions'] = DB::table('simulation_cases as c')->leftJoin('channel_stock_sync_outbox as s', 's.id', '=', 'c.outbox_id')
                ->where(fn ($q) => $q->whereNull('s.id')->orWhere('s.status', '<>', 'succeeded')->orWhereColumn('s.dispatched_version', '<', 'c.stock_version'))->count();
        }

        return $result;
    }

    public function verifyFiles(): array
    {
        $checked = $invalid = 0;
        DB::table('bulk_shipping_label_batches')->where('status', 'ready')->orderBy('id')->chunkById(100, function ($batches) use (&$checked, &$invalid): void {
            foreach ($batches as $batch) {
                $disk = Storage::disk($batch->print_pdf_path ? 'print_spool' : 'documents');
                $path = $batch->print_pdf_path ?: $batch->merged_pdf_path;
                $checked++;
                if (! $path || ! $disk->exists($path)) {
                    $invalid++;

                    continue;
                }
                $process = new Process(['pdfinfo', $disk->path($path)]);
                $process->setTimeout(30)->run();
                if (! $process->isSuccessful() || ! preg_match('/^Pages:\s+(\d+)/m', $process->getOutput(), $match)
                    || (int) $match[1] !== (int) $batch->total_count) {
                    $invalid++;
                }
            }
        });

        return ['checked' => $checked, 'invalid' => $invalid, 'verification' => 'PDF parse and page count; not barcode visual verification'];
    }
}
