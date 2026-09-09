<?php

namespace Modules\Sales\Jobs;

use App\Support\DatabaseAvailability;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Channel\Exceptions\ShopeeApiException;
use Modules\Channel\Exceptions\TikTokApiException;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\LazadaTransactionMapper;
use Modules\Channel\Services\ShopeeEscrowMapper;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Channel\Services\TikTokStatementMapper;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\FinanceSyncControlService;
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
        $this->onConnection(config('queue.routing.channel_finance.connection', 'redis-finance'))
            ->onQueue(config('queue.routing.channel_finance.queue', 'channel-finance'));
    }

    public function handle(SalesOrderService $orderService): void
    {

        $order = SalesOrder::find($this->orderId);

        if (! $order || ! $order->source || ! $order->channel_shop_id || ! $order->channel_order_no) {
            return;
        }

        $control = app(FinanceSyncControlService::class);
        if (! $control->claim($order->id)) {
            return;
        }

        if ($order->is_settled && ! $this->force) {
            $control->markSucceeded($order->id);

            return;
        }

        $isEligible = $this->force
            || $order->is_canceled
            || ! in_array(strtoupper((string) $order->channel_status), ['UNPAID', 'UNCONFIRMED'], true);

        if (! $isEligible) {
            $control->markWaiting($order->id, 'Status channel belum eligible untuk finance', 60);

            return;
        }

        if (! $order->itemsFullyDownloaded()) {
            $control->markWaiting(
                $order->id,
                'Item order belum seluruhnya ter-download atau termapping',
                (int) config('finance_sync.incomplete_items_retry_after_minutes', 15),
            );

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
        $control = app(FinanceSyncControlService::class);

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
                $control->markWaiting($order->id, $e->getMessage(), (int) ceil($delay / 60));
                $this->release($delay);

                return;
            }

            $this->failWithoutRetry($e, $order);

            return;
        } catch (ShopeeApiException $e) {
            if ($e->isRetryable()) {
                $control->markRetryable($order->id, $e, $this->backoffSeconds());
                throw $e;
            }

            $this->failWithoutRetry($e, $order, 'fetch');

            return;
        } catch (\Throwable $e) {
            if (DatabaseAvailability::isPermanentDataError($e)) {
                $this->failWithoutRetry($e, $order, 'fetch');

                return;
            }

            if (DatabaseAvailability::isTransient($e)) {
                Log::warning('SyncOrderFinanceJob database temporarily unavailable; allowing delayed retry', [
                    'job' => self::class,
                    'order_id' => $order->id,
                    'source' => $order->source,
                    'channel_shop_id' => $order->channel_shop_id,
                    'attempt' => $this->attempts(),
                    'exception' => $e->getMessage(),
                ]);

                $control->markRetryable($order->id, $e, $this->backoffSeconds());
                throw $e;
            }

            if (str_contains(strtolower($e->getMessage()), 'too many requests') || str_contains($e->getMessage(), '429')) {
                $delay = min(300, (int) pow(2, $this->attempts()) * 10 + rand(3, 10));
                Log::warning("SyncOrderFinanceJob: Rate limit encountered for order {$order->id}, releasing with delay {$delay}s: ".$e->getMessage());
                $control->markWaiting($order->id, $e->getMessage(), (int) ceil($delay / 60));
                $this->release($delay);

                return;
            }

            $this->logUnexpectedFailure($e, $order, 'fetch');
            $control->markRetryable($order->id, $e, $this->backoffSeconds());
            throw $e;
        }

        if ($finance === null) {
            $control->markWaiting(
                $order->id,
                'Channel belum mengembalikan data settlement',
                (int) config('finance_sync.empty_response_retry_after_minutes', 360),
            );

            return;
        }

        try {
            $updatedOrder = $orderService->updateOrderFinance($order->id, $finance);
        } catch (\Throwable $e) {
            if (DatabaseAvailability::isPermanentDataError($e)) {
                $this->failWithoutRetry($e, $order, 'update');

                return;
            }

            if (DatabaseAvailability::isTransient($e)) {
                Log::warning('SyncOrderFinanceJob database temporarily unavailable during update', [
                    'job' => self::class,
                    'order_id' => $order->id,
                    'source' => $order->source,
                    'channel_shop_id' => $order->channel_shop_id,
                    'attempt' => $this->attempts(),
                    'exception' => $e->getMessage(),
                ]);
            }

            $this->logUnexpectedFailure($e, $order, 'update');
            if (! DatabaseAvailability::isPermanentDataError($e)) {
                $control->markRetryable($order->id, $e, $this->backoffSeconds());
            }
            throw $e;
        }

        if ($updatedOrder?->is_settled) {
            $control->markSucceeded($order->id);
        } else {
            $control->markWaiting(
                $order->id,
                'Data finance tersimpan tetapi belum settled',
                (int) config('finance_sync.empty_response_retry_after_minutes', 360),
            );
        }

        Log::info('SyncOrderFinanceJob: finance updated', [
            'job' => self::class,
            'order_id' => $order->id,
            'salesorder_no' => $order->salesorder_no,
            'source' => $order->source,
            'channel_shop_id' => $order->channel_shop_id,
            'is_settled' => $finance['is_settled'] ?? false,
        ]);
    }

    private function failWithoutRetry(\Throwable $exception, SalesOrder $order, string $stage = 'fetch'): void
    {
        Log::error('SyncOrderFinanceJob rejected permanent data error', [
            'job' => self::class,
            'order_id' => $order->id,
            'salesorder_no' => $order->salesorder_no,
            'source' => $order->source,
            'channel_shop_id' => $order->channel_shop_id,
            'attempt' => $this->attempts(),
            'exception' => $exception->getMessage(),
        ]);

        app(FinanceSyncControlService::class)->markDeadLetter(
            $order->id,
            $exception,
            $this->job?->uuid(),
            $this->attempts(),
            ['stage' => $stage, 'force' => $this->force],
        );

        if ($this->job !== null) {
            $this->fail($exception);

            return;
        }

        throw $exception;
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
            'job' => self::class,
            'order_id' => $this->orderId,
            'attempt' => $this->attempts(),
            'exception' => $exception->getMessage(),
        ]);

        app(FinanceSyncControlService::class)->markDeadLetter(
            $this->orderId,
            $exception,
            $this->job?->uuid(),
            $this->attempts(),
            ['stage' => 'job_failed', 'force' => $this->force],
        );
    }

    private function logUnexpectedFailure(\Throwable $exception, SalesOrder $order, string $stage): void
    {
        Log::error('SyncOrderFinanceJob unexpected failure', [
            'job' => self::class,
            'stage' => $stage,
            'order_id' => $order->id,
            'salesorder_no' => $order->salesorder_no,
            'source' => $order->source,
            'channel_shop_id' => $order->channel_shop_id,
            'channel_order_no' => $order->channel_order_no,
            'endpoint' => $this->financeEndpoint($order),
            'attempt' => $this->attempts(),
            'exception_class' => get_class($exception),
            'exception_code' => $exception->getCode(),
            'http_status' => method_exists($exception, 'status') ? $exception->status() : null,
            'channel_error_code' => property_exists($exception, 'errorCode') ? $exception->errorCode : null,
            'channel_error_category' => property_exists($exception, 'category') ? $exception->category : null,
            'exception' => $exception->getMessage(),
        ]);
    }

    private function financeEndpoint(SalesOrder $order): string
    {
        return match ($order->source) {
            'shopee' => '/api/v2/payment/get_escrow_detail',
            'tiktok' => (string) config(
                'services.tiktok.finance_statement_path',
                '/finance/202309/orders/{order_id}/statement_transactions',
            ),
            'lazada' => '/finance/transaction/details/get',
            default => 'unknown',
        };
    }

    private function backoffSeconds(): int
    {
        $index = max(0, min(count($this->backoff) - 1, $this->attempts() - 1));

        return (int) ($this->backoff[$index] ?? 60);
    }
}
