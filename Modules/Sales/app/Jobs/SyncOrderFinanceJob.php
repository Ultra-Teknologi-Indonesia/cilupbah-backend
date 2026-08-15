<?php

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesOrderService;

class SyncOrderFinanceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $maxExceptions = 3;
    public array $backoff = [15, 45, 120, 300];

    public function __construct(
        public readonly string $orderId,
        public readonly bool $force = false,
    ) {
        $this->onQueue(config('queue.names.channel_sync'));
    }

    public function handle(SalesOrderService $orderService): void
    {
        /** @var SalesOrder|null $order */
        $order = SalesOrder::find($this->orderId);

        if (! $order || ! $order->source || ! $order->channel_shop_id || ! $order->channel_order_no) {
            return;
        }

        if ($order->is_settled && ! $this->force) {
            return;
        }

        if (! $order->itemsFullyDownloaded()) {
            return;
        }

        $rateLimitKey = "finance_sync:{$order->source}:{$order->channel_shop_id}";
        $executed = \Illuminate\Support\Facades\RateLimiter::attempt(
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
                default  => null,
            };
        } catch (\Modules\Channel\Exceptions\TikTokApiException $e) {
            if ($e->isRetryable() || \in_array((string) $e->errorCode, ['36009002', '12052109', '36009003'], true)) {
                $delay = min(300, (int) pow(2, $this->attempts()) * 10 + rand(3, 10));
                Log::warning("SyncOrderFinanceJob: TikTok rate limit / downstream busy for order {$order->id}, releasing with delay {$delay}s: " . $e->getMessage(), [
                    'order_id' => $order->id,
                    'shop_id'  => $order->channel_shop_id,
                    'attempt'  => $this->attempts(),
                ]);
                $this->release($delay);

                return;
            }

            throw $e;
        } catch (\Throwable $e) {
            if (str_contains(strtolower($e->getMessage()), 'too many requests') || str_contains($e->getMessage(), '429')) {
                $delay = min(300, (int) pow(2, $this->attempts()) * 10 + rand(3, 10));
                Log::warning("SyncOrderFinanceJob: Rate limit encountered for order {$order->id}, releasing with delay {$delay}s: " . $e->getMessage());
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
            'order_id'      => $order->id,
            'salesorder_no' => $order->salesorder_no,
            'source'        => $order->source,
            'is_settled'    => $finance['is_settled'] ?? false,
        ]);
    }

    private function fetchShopee(SalesOrder $order): ?array
    {
        $service = app(\Modules\Channel\Services\ShopeeOrderService::class);
        $mapper = app(\Modules\Channel\Services\ShopeeEscrowMapper::class);

        $escrow = $service->getEscrowDetail($order->channel_shop_id, $order->channel_order_no);

        if (empty($escrow)) {
            return null;
        }

        return $mapper->map($escrow);
    }

    private function fetchTikTok(SalesOrder $order): ?array
    {
        $service = app(\Modules\Channel\Services\TikTokOrderService::class);
        $mapper = app(\Modules\Channel\Services\TikTokStatementMapper::class);

        $statement = $service->getOrderStatement($order->channel_shop_id, $order->channel_order_no);

        if (empty($statement)) {
            return null;
        }

        return $mapper->map($statement);
    }

    private function fetchLazada(SalesOrder $order): ?array
    {
        $service = app(\Modules\Channel\Services\LazadaOrderService::class);
        $mapper = app(\Modules\Channel\Services\LazadaTransactionMapper::class);

        $transactions = $service->getTransactionDetails($order->channel_shop_id, $order->channel_order_no);

        if (empty($transactions)) {
            return null;
        }

        return $mapper->map($transactions);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SyncOrderFinanceJob failed permanently', [
            'order_id'  => $this->orderId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
