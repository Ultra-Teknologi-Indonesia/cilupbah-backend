<?php

declare(strict_types=1);

use FlashSaleSimulation\SimulationRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Modules\Channel\Helpers\ShopeeSignature;
use Modules\Channel\Services\ChannelStockSyncOutboxService;
use Modules\Sales\Services\BulkShippingLabelService;

if (getenv('APP_ENV') !== 'simulation' || getenv('DB_DATABASE') !== 'cilupbah_simulation'
    || ! in_array(getenv('DB_HOST'), ['postgres', 'pgbouncer'], true) || getenv('REDIS_HOST') !== 'redis'
    || getenv('SHOPEE_HOST') !== 'http://marketplace:8080'
    || ! preg_match('/^cilupbah-sim-[a-z0-9-]+$/', (string) getenv('SIM_NAMESPACE'))) {
    fwrite(STDERR, "Refusing to operate outside the isolated simulation namespace.\n");
    exit(2);
}
$root = getenv('SIM_APP_ROOT') ?: '/var/www/html';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
require __DIR__.'/SimulationRepository.php';
$repository = new SimulationRepository;
$mode = $argv[1] ?? 'snapshot';
$count = (int) getenv('SIM_COUNT');
$shops = (int) getenv('SIM_SHOPS');
$skus = (int) getenv('SIM_SKUS');
$duration = (int) getenv('SIM_SECONDS');
$trafficProfile = (string) getenv('SIM_TRAFFIC_PROFILE');
$burstSeconds = (int) getenv('SIM_BURST_SECONDS');
$selectionSize = (int) getenv('SIM_LABEL_SELECTION_SIZE');
$selectionMode = (string) getenv('SIM_LABEL_SELECTION_MODE');
if ($count < 1 || $shops < 1 || $skus < 1 || $duration < 1 || $selectionSize < 1
    || ! in_array($trafficProfile, ['steady', 'flash_burst'], true)
    || ! in_array($selectionMode, ['mixed', 'shop_grouped'], true)
    || ($trafficProfile === 'flash_burst' && ($burstSeconds < 1 || $burstSeconds > $duration))) {
    throw new RuntimeException('Invalid workload dimensions.');
}

function emit(array $data): void
{
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
}

function queues(): array
{
    $result = [];
    foreach (config('horizon.queue_health_supervisors', config('horizon.defaults')) as $definition) {
        $connection = $definition['connection'];
        $redis = Redis::connection(config('queue.connections.'.$connection.'.connection'));
        foreach ((array) $definition['queue'] as $queue) {
            $key = $connection.':'.$queue;
            $head = $redis->lindex('queues:'.$queue, 0);
            $payload = $head ? json_decode($head, true) : [];
            $result[$key] = [
                'ready' => (int) $redis->llen('queues:'.$queue),
                'delayed' => (int) $redis->zcard('queues:'.$queue.':delayed'),
                'reserved' => (int) $redis->zcard('queues:'.$queue.':reserved'),
                'oldest_ready_seconds' => isset($payload['pushedAt']) ? max(0, time() - (float) $payload['pushedAt']) : null,
            ];
        }
    }

    return $result;
}

switch ($mode) {
    case 'init':
        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            throw new RuntimeException(Artisan::output());
        }
        $repository->initialize($shops, $skus);
        emit(['initialized' => true]);
        break;
    case 'config':
        emit(['profiles' => config('horizon.profiles'), 'workers' => config('horizon.queue_health_supervisors'),
            'queue_connections' => config('queue.connections'), 'backpressure' => config('queue.backpressure'),
            'ratelimit' => config('ratelimit.channel_api_per_second_by_channel')]);
        break;
    case 'feed':
        $shard = (int) getenv('JOB_COMPLETION_INDEX');
        $shards = (int) getenv('SIM_PRODUCERS');
        $start = (float) getenv('SIM_START_AT');
        $duplicateEvery = (int) getenv('SIM_WEBHOOK_DUPLICATE_EVERY');
        $window = $trafficProfile === 'flash_burst' ? $burstSeconds : $duration;
        $url = 'http://app:8000/api/v1/shopee/webhook';
        for ($seq = $shard + 1; $seq <= $count; $seq += $shards) {
            $due = $start + ($seq - 1) * $window / $count;
            while (($remaining = $due - microtime(true)) > 0) {
                usleep((int) (min(1, $remaining) * 1000000));
            }

            if (microtime(true) > $start + $duration) {
                emit(['producer_deadline_exceeded' => true, 'next_sequence' => $seq]);
                exit(3);
            }
            $shop = (($seq - 1) % $shops) + 1;
            $sku = (($seq - 1) % $skus) + 1;
            $repository->begin($seq, $due, max(0, microtime(true) - $due));
            try {
                $payload = json_encode(['shop_id' => 900000 + $shop, 'code' => 3, 'timestamp' => time(),
                    'data' => ['ordersn' => sprintf('SIM%012d', $seq), 'status' => 'READY_TO_SHIP', 'update_time' => time()]], JSON_THROW_ON_ERROR);
                $response = Http::timeout(15)->withHeaders(['Authorization' => ShopeeSignature::pushSign($url, $payload, 'simulation-only')])
                    ->withBody($payload, 'application/json')->post($url);
                $repository->recordWebhookAttempt($seq, $response->status());
                if ($duplicateEvery > 0 && $seq % $duplicateEvery === 0) {
                    $duplicate = Http::timeout(15)->withHeaders(['Authorization' => ShopeeSignature::pushSign($url, $payload, 'simulation-only')])
                        ->withBody($payload, 'application/json')->post($url);
                    $repository->recordWebhookAttempt($seq, $duplicate->status());
                }
                $requestedAt = now();
                $outbox = app(ChannelStockSyncOutboxService::class)->request($repository->mapping($shop, $sku), 'sync_stock');
                $repository->update($seq, ['outbox_id' => $outbox->id, 'stock_version' => $outbox->requested_version, 'stock_requested_at' => $requestedAt]);
            } catch (Throwable $error) {
                $repository->update($seq, ['error' => substr($error->getMessage(), 0, 2000)]);
            }
        }
        $repository->markProducerCompleted($shard);
        emit(['producer_completed' => $shard]);
        break;
    case 'coordinate':
        $user = $repository->user();
        $labels = app(BulkShippingLabelService::class);
        $tick = 0;
        while (true) {
            $rows = $repository->unbatched($selectionSize, $selectionMode);

            if ($rows->count() >= $selectionSize || ($rows->isNotEmpty() && $repository->producersCompleted((int) getenv('SIM_PRODUCERS')))) {
                $batch = $labels->createBatch($user, $rows->pluck('id')->all(), ['document_size' => BulkShippingLabelService::DEFAULT_SIZE]);
                $repository->attachBatch($rows->pluck('seq')->all(), (string) $batch->id);
                $labels->queueBatch($batch);
            }
            if (++$tick % 10 === 0) {

                Artisan::call('channel:webhooks-replay', ['--minutes' => 1]);
                Artisan::call('channel:dispatch-stock-outbox');
            }
            usleep(200000);
        }
    case 'snapshot':
    case 'report':
        emit($repository->snapshot($mode === 'report') + ['queues' => queues()]);
        break;
    case 'verify-files':
        emit($repository->verifyFiles());
        break;
    default:
        throw new RuntimeException('Unknown simulation operation.');
}
