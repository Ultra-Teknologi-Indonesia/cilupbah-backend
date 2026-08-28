<?php

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Channel\Exceptions\TikTokApiException;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\LazadaTransactionMapper;
use Modules\Channel\Services\ShopeeEscrowMapper;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Channel\Services\TikTokStatementMapper;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesOrderService;

class SyncOrderFinanceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 1800;

    public function uniqueId(): string
    {
        return $this->orderId;
    }

    public int $tries = 3;

    public int $maxExceptions = 3;

    public array $backoff = [15, 60, 180];

    public function __construct(
        public readonly string $orderId,
        public readonly bool $force = false,
    ) {
        $this->onQueue(config('queue.names.channel_finance'));
    }

    public function handle(SalesOrderService $orderService): void
    {

        $order = SalesOrder::find($this->orderId);

        if (! $order || ! $order->source || ! $order->channel_shop_id || ! $order->channel_order_no) {
            return;
        }

        if ($order->is_settled && ! $this->force) {
            return;
        }

        $isEligible = $this->force
            || $order->is_canceled
            || ! in_array(strtoupper((string) $order->channel_status), ['UNPAID', 'UNCONFIRMED'], true);

        if (! $isEligible) {
            return;
        }

        if (! $order->itemsFullyDownloaded()) {
            return;
        }

        $rateLimitKey = "finance_sync:{$order->source}:{$order->channel_shop_id}";
        $executed = RateLimiter::attempt(
            $rateLimitKey,
            1,
            function () use ($order, $orderService) {
                $this->executeSync($order, $orderService);
            },
            2
        );

        if (! $executed) {
            $this->release(rand(2, 5));
        }
    }

    protected function executeSync(SalesOrder $order, SalesOrderService $orderService): void
    {
        try {
            $finance = match ($order->source) {
                'shopee' => $this->fetchShopee($order),
                'tiktok' => $this->fetchTikTok($order),
                'lazada' => $this->fetchLazada($order),
                default => null,
            };
        } catch (TikTokApiException $e) {
            if ($e->isRetryable() || \in_array((string) $e->errorCode, ['36009002', '12052109', '36009003'], true)) {
                $delay = min(300, (int) pow(2, $this->attempts()) * 10 + rand(3, 10));
                Log::warning("SyncOrderFinanceJob: TikTok rate limit / downstream busy for order {$order->id}, releasing with delay {$delay}s: ".$e->getMessage(), [
                    'order_id' => $order->id,
                    'shop_id' => $order->channel_shop_id,
                    'attempt' => $this->attempts(),
                ]);
                $this->release($delay);

                return;
            }

            throw $e;
        } catch (\Throwable $e) {
            if (str_contains(strtolower($e->getMessage()), 'too many requests') || str_contains($e->getMessage(), '429')) {
                $delay = min(300, (int) pow(2, $this->attempts()) * 10 + rand(3, 10));
                Log::warning("SyncOrderFinanceJob: Rate limit encountered for order {$order->id}, releasing with delay {$delay}s: ".$e->getMessage());
                $this->release($delay);

                return;
            }

            throw $e;
        }

        if ($finance === null) {
            return;
        }

        $orderService->updateOrderFinance($order->id, $finance);

        Log::info('SyncOrderFinanceJob: finance updated', [
            'order_id' => $order->id,
            'salesorder_no' => $order->salesorder_no,
            'source' => $order->source,
            'is_settled' => $finance['is_settled'] ?? false,
        ]);
    }

    private function fetchShopee(SalesOrder $order): ?array
    {
        $service = app(ShopeeOrderService::class);
        $mapper = app(ShopeeEscrowMapper::class);

        $escrow = $service->getEscrowDetail($order->channel_shop_id, $order->channel_order_no);

        if (empty($escrow)) {
            return null;
        }

        return $mapper->map($escrow);
    }

    private function fetchTikTok(SalesOrder $order): ?array
    {
        $service = app(TikTokOrderService::class);
        $mapper = app(TikTokStatementMapper::class);

        $statement = $service->getOrderStatement($order->channel_shop_id, $order->channel_order_no);

        if (empty($statement)) {
            return null;
        }

        return $mapper->map($statement);
    }

    private function fetchLazada(SalesOrder $order): ?array
    {
        $service = app(LazadaOrderService::class);
        $mapper = app(LazadaTransactionMapper::class);

        $transactions = $service->getTransactionDetails($order->channel_shop_id, $order->channel_order_no);

        if (empty($transactions)) {
            return null;
        }

        return $mapper->map($transactions);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SyncOrderFinanceJob failed permanently', [
            'order_id' => $this->orderId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
