<?php

namespace Tests\Feature;

use FlashSaleSimulation\SimulationRepository;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Channel\Services\ChannelStockSyncOutboxService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Sales\Services\BulkShippingLabelService;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class FlashSaleSimulationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixture_seeds_real_mapping_and_rejects_reinitialization(): void
    {
        require_once base_path('tests/Load/flash-sale/SimulationRepository.php');
        Queue::fake();
        $repository = new SimulationRepository;
        $repository->initialize(2, 3);
        $mapping = $repository->mapping(2, 3);
        $this->assertSame('3', $mapping->external_product_id);
        $this->assertSame('900002', $mapping->channelShop->shop_id);
        $outbox = app(ChannelStockSyncOutboxService::class)->request($mapping, 'sync_stock');
        $this->assertSame(1, $outbox->requested_version);
        $repository->begin(1, microtime(true), 0);
        $repository->update(1, ['http_status' => 200, 'outbox_id' => $outbox->id, 'stock_version' => 1]);
        $snapshot = $repository->snapshot(true);
        $this->assertEquals(1, $snapshot['cases']->http_accepted);
        $this->assertEquals(1, $snapshot['missing_orders']);
        $this->expectException(\RuntimeException::class);
        $repository->initialize(2, 3);
    }

    public function test_fixture_order_passes_real_order_import_and_measurement(): void
    {
        require_once base_path('tests/Load/flash-sale/SimulationRepository.php');
        Queue::fake();
        Http::preventStrayRequests();
        $repository = new SimulationRepository;
        $repository->initialize(1, 1);
        $repository->begin(1, microtime(true), 0);
        config(['services.shopee.partner_id' => '1', 'services.shopee.partner_key' => 'simulation-only',
            'services.shopee.host' => 'http://marketplace:8080', 'queue.channel_order_intake.cutoff_at' => '']);
        Http::fake([
            'http://marketplace:8080/api/v2/order/get_order_detail*' => Http::response(['response' => ['order_list' => [[
                'order_sn' => 'SIM000000000001', 'order_status' => 'READY_TO_SHIP', 'create_time' => time(),
                'total_amount' => 10000, 'buyer_username' => 'simulation',
                'recipient_address' => ['name' => 'SIMULATION', 'phone' => '000', 'full_address' => 'TEST ONLY'],
                'package_list' => [['package_number' => 'PKGSIM000000000001', 'logistics_channel_id' => 8001]],
                'item_list' => [['item_id' => 1, 'item_name' => 'SIM SKU', 'model_id' => 1, 'model_sku' => 'SIM-SKU-1',
                    'model_quantity_purchased' => 1, 'model_discounted_price' => 10000]],
            ]]]]),
            'http://marketplace:8080/api/v2/logistics/get_channel_list*' => Http::response(['response' => ['logistics_channel_list' => []]]),
            'http://marketplace:8080/api/v2/logistics/get_tracking_number*' => Http::response(['response' => ['tracking_number' => '']]),
        ]);
        $this->assertSame(1, app(ShopeeOrderService::class)->pullOrderById('900001', 'SIM000000000001', true));
        $snapshot = $repository->snapshot(true);
        $this->assertSame(1, $snapshot['orders']);
        $this->assertSame(0, $snapshot['missing_orders']);
        $this->assertCount(1, $repository->unbatched(100));
    }

    public function test_simulator_contract_drives_real_awb_download_and_pdf_jobs(): void
    {
        require_once base_path('tests/Load/flash-sale/SimulationRepository.php');
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('print_spool');
        Storage::fake('documents');
        $repository = new SimulationRepository;
        $repository->initialize(1, 1);
        $repository->begin(1, microtime(true), 0);
        config(['services.shopee.partner_id' => '1', 'services.shopee.partner_key' => 'simulation-only',
            'services.shopee.host' => 'http://marketplace:8080', 'queue.channel_order_intake.cutoff_at' => '',
            'bulk-labels.async_shopee_preparation' => true, 'ratelimit.channel_api_per_second_by_channel.shopee' => 10000]);
        $ledger = tempnam(sys_get_temp_dir(), 'cilupbah-simulator-contract-');
        $python = <<<'PY'
import sys,json,base64
from urllib.parse import urlsplit,parse_qs
sys.path.insert(0,sys.argv[1])
from marketplace import Marketplace
request=json.loads(sys.argv[3]); parsed=urlsplit(request['url'])
status,result=Marketplace(sys.argv[2],0).handle(parsed.path,parse_qs(parsed.query),request['body'])
binary=isinstance(result,bytes)
print(json.dumps({'status':status,'binary':binary,'body':base64.b64encode(result).decode() if binary else result}))
PY;
        try {
            Http::fake(function ($request) use ($ledger, $python) {
                $this->assertSame('marketplace', parse_url($request->url(), PHP_URL_HOST));
                $process = new Process(['python3', '-c', $python,
                    base_path('tests/Load/flash-sale'), $ledger,
                    json_encode(['url' => $request->url(), 'body' => $request->data()], JSON_THROW_ON_ERROR)], null, ['SIM_SKUS' => '1']);
                $process->mustRun();
                $response = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

                return Http::response($response['binary'] ? base64_decode($response['body']) : $response['body'],
                    $response['status'], ['Content-Type' => $response['binary'] ? 'application/pdf' : 'application/json']);
            });
            app(ShopeeOrderService::class)->pullOrderById('900001', 'SIM000000000001', true);
            $rows = $repository->unbatched(100);
            $labels = app(BulkShippingLabelService::class);
            $batch = $labels->createBatch($repository->user(), $rows->pluck('id')->all(), ['document_size' => $labels::DEFAULT_SIZE]);
            $repository->attachBatch([1], (string) $batch->id);
            $labels->queueBatch($batch);
            $processed = [];
            for ($round = 0; $round < 40; $round++) {
                $didWork = false;
                foreach (Queue::pushedJobs() as $class => $entries) {
                    if (! str_starts_with($class, 'Modules\\Sales\\Jobs\\') || str_contains($class, 'Finance') || str_contains($class, 'AdminAlert')) {
                        continue;
                    }
                    foreach ($entries as $index => $entry) {
                        $key = $class.':'.$index;
                        if (isset($processed[$key])) {
                            continue;
                        }
                        $processed[$key] = true;
                        $job = $entry['job'];
                        app()->call([$job, 'handle']);
                        if ($job instanceof ShouldBeUnique) {
                            (new UniqueLock(app(Repository::class)))->release($job);
                        }
                        $didWork = true;
                    }
                }
                if (! $didWork) {
                    break;
                }
            }
            $this->assertSame('ready', $batch->fresh()->status, json_encode($batch->fresh()->items->toArray()));
            $this->assertSame(1, $repository->snapshot(true)['awb_ready']);
            $this->assertSame(['checked' => 1, 'invalid' => 0, 'verification' => 'PDF parse and page count; not barcode visual verification'], $repository->verifyFiles());
        } finally {
            foreach ([$ledger, $ledger.'-wal', $ledger.'-shm'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
