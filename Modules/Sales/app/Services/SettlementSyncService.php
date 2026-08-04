<?php

namespace Modules\Sales\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\LazadaTransactionMapper;
use Modules\Channel\Services\ShopeeEscrowMapper;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Channel\Services\TikTokStatementMapper;
use Modules\Sales\Jobs\SyncOrderFinanceJob;
use Modules\Sales\Models\ChannelSettlement;
use Modules\Sales\Models\ChannelSettlementAdjustment;
use Modules\Sales\Models\SalesOrder;

class SettlementSyncService
{

    private const TIKTOK_ORDER_TYPES = ['ORDER', 'SETTLE', 'SETTLEMENT'];

    public function __construct(
        protected ChannelShopRepository $shopRepository,
        protected ShopeeOrderService $shopeeService,
        protected ShopeeEscrowMapper $shopeeMapper,
        protected TikTokOrderService $tiktokService,
        protected TikTokStatementMapper $tiktokMapper,
        protected LazadaTransactionMapper $lazadaMapper,
        protected LazadaOrderService $lazadaService,
        protected SalesOrderService $orderService,
    ) {}

    public function sync(?string $channel, int $days): array
    {
        $from = now()->subDays($days);
        $stats = ['tiktok' => 0, 'shopee' => 0, 'lazada' => 0];

        if ($channel === null || $channel === 'tiktok') {
            $stats['tiktok'] = $this->syncChannel('tiktok', fn ($shop) => $this->syncTikTokShop($shop, $from));
        }

        if ($channel === null || $channel === 'shopee') {
            $stats['shopee'] = $this->syncChannel('shopee', fn ($shop) => $this->syncShopeeShop($shop, $from));
        }

        if ($channel === null || $channel === 'lazada') {
            $stats['lazada'] = $this->syncChannel('lazada', fn ($shop) => $this->syncLazadaShop($shop, $from));
        }

        return $stats;
    }

    private function syncChannel(string $code, callable $fn): int
    {
        $count = 0;

        foreach ($this->shopRepository->getShopsByChannelCode($code) as $shop) {
            if (! $shop->access_token) {
                continue;
            }

            try {
                $count += (int) $fn($shop);
            } catch (\Throwable $e) {
                Log::warning("SettlementSync {$code} gagal untuk toko {$shop->shop_id}: " . $e->getMessage());
            }
        }

        return $count;
    }

    private function syncTikTokShop(object $shop, Carbon $from): int
    {
        $shopId = (string) $shop->shop_id;
        $statements = $this->tiktokService->getStatements($shopId, $from->timestamp, now()->timestamp);
        $processed = 0;

        foreach ($statements as $stmt) {
            $externalId = (string) ($stmt['id'] ?? '');
            if ($externalId === '') {
                continue;
            }

            $settledAt = $this->fromTimestamp($stmt['statement_time'] ?? null);
            $paidAt = $this->fromTimestamp($stmt['payment_time'] ?? ($stmt['paid_time'] ?? null));

            $settlement = ChannelSettlement::updateOrCreate(
                ['channel' => 'tiktok', 'shop_id' => $shopId, 'external_id' => $externalId],
                [
                    'type'                => 'STATEMENT',
                    'external_payment_id' => $stmt['payment_id'] ?? null,
                    'settled_at'          => $settledAt,
                    'paid_at'             => $paidAt,
                    'payment_status'      => $stmt['payment_status'] ?? null,
                    'total_settlement'    => $this->toFloat($stmt['settlement_amount'] ?? null),
                    'total_fee'           => $this->toFloat($stmt['fee_amount'] ?? null),
                    'total_adjustment'    => $this->toFloat($stmt['adjustment_amount'] ?? null),
                    'currency'            => $stmt['currency'] ?? 'IDR',
                    'raw'                 => $stmt,
                    'synced_at'           => now(),
                ],
            );

            $transactions = $this->tiktokService->getTransactionsByStatement($shopId, $externalId);

            foreach ($transactions as $tx) {
                $this->handleTikTokTransaction($shopId, $settlement, $tx, $settledAt);
                $processed++;
            }
        }

        return $processed;
    }

    private function handleTikTokTransaction(string $shopId, ChannelSettlement $settlement, array $tx, ?Carbon $settledAt): void
    {
        $type = strtoupper((string) ($tx['type'] ?? ''));
        $orderId = $tx['order_id'] ?? ($tx['adjustment_order_id'] ?? null);
        $isOrder = ($type === '' || in_array($type, self::TIKTOK_ORDER_TYPES, true)) && ! empty($tx['order_id']);

        if ($isOrder) {
            $order = SalesOrder::where('source', 'tiktok')
                ->where('channel_order_no', $tx['order_id'])
                ->first();

            if (! $order || ! $order->itemsFullyDownloaded()) {
                return;
            }

            $finance = $this->tiktokMapper->map($tx);
            $finance['settled_at'] = $settledAt;
            $finance['channel_settlement_id'] = $settlement->id;
            $this->orderService->updateOrderFinance($order->id, $finance);

            return;
        }

        $matchedOrder = $orderId
            ? SalesOrder::where('source', 'tiktok')->where('channel_order_no', $orderId)->first()
            : null;

        ChannelSettlementAdjustment::updateOrCreate(
            ['channel' => 'tiktok', 'external_transaction_id' => (string) ($tx['id'] ?? uniqid('tt-', true))],
            [
                'channel_settlement_id' => $settlement->id,
                'shop_id'               => $shopId,
                'type'                  => $type ?: 'OTHER_ADJUSTMENT',
                'order_id'              => $matchedOrder?->id,
                'channel_order_no'      => $orderId,
                'amount'                => $this->toFloat($tx['settlement_amount'] ?? ($tx['adjustment_amount'] ?? 0)) ?? 0,
                'reason'                => $tx['reason'] ?? ($tx['adjustment_reason'] ?? null),
                'occurred_at'           => $this->fromTimestamp($tx['order_create_time'] ?? null) ?? $settledAt,
                'currency'              => $tx['currency'] ?? 'IDR',
                'raw'                   => $tx,
            ],
        );
    }

    private function syncShopeeShop(object $shop, Carbon $from): int
    {
        $shopId = (string) $shop->shop_id;
        $to = now();
        $processed = 0;
        $pageNo = 1;

        $byDate = [];

        do {
            $res = $this->shopeeService->getEscrowList($shopId, $from->timestamp, $to->timestamp, $pageNo, 100);
            $items = $res['escrow_list'] ?? [];

            foreach ($items as $item) {
                $orderSn = $item['order_sn'] ?? null;
                $releaseTime = $item['escrow_release_time'] ?? null;
                if (! $orderSn || ! $releaseTime) {
                    continue;
                }

                $settledAt = $this->fromTimestamp($releaseTime);
                $dateKey = $settledAt?->toDateString() ?? 'unknown';
                $byDate[$dateKey]['settled_at'] = $settledAt;
                $byDate[$dateKey]['total'] = ($byDate[$dateKey]['total'] ?? 0) + ($this->toFloat($item['payout_amount'] ?? 0) ?? 0);

                $this->syncShopeeOrder($shopId, $orderSn, $settledAt);
                $processed++;
            }

            $pageNo++;
            $more = (bool) ($res['more'] ?? false);
        } while ($more && $pageNo <= 50);

        foreach ($byDate as $date => $agg) {
            ChannelSettlement::updateOrCreate(
                ['channel' => 'shopee', 'shop_id' => $shopId, 'external_id' => "escrow-{$date}"],
                [
                    'type'             => 'STATEMENT',
                    'settled_at'       => $agg['settled_at'] ?? null,
                    'period_start'     => $date === 'unknown' ? null : $date,
                    'period_end'       => $date === 'unknown' ? null : $date,
                    'total_settlement' => $agg['total'] ?? 0,
                    'currency'         => 'IDR',
                    'raw'              => ['release_date' => $date, 'payout_total' => $agg['total'] ?? 0],
                    'synced_at'        => now(),
                ],
            );
        }

        return $processed;
    }

    private function syncShopeeOrder(string $shopId, string $orderSn, ?Carbon $settledAt): void
    {
        $order = SalesOrder::where('source', 'shopee')
            ->where('channel_order_no', $orderSn)
            ->first();

        if (! $order || ! $order->itemsFullyDownloaded()) {
            return;
        }

        try {
            $escrow = $this->shopeeService->getEscrowDetail($shopId, $orderSn);
            if (empty($escrow)) {
                return;
            }

            $finance = $this->shopeeMapper->map($escrow);
            $finance['settled_at'] = $settledAt;
            $this->orderService->updateOrderFinance($order->id, $finance);
        } catch (\Throwable $e) {
            Log::warning("SettlementSync shopee escrow {$orderSn} gagal: " . $e->getMessage());
        }
    }

    private function syncLazadaShop(object $shop, Carbon $from): int
    {
        $shopId = (string) $shop->shop_id;
        $count = 0;

        // 1) Ledger payout level-statement (mirror Shopee getEscrowList / TikTok getStatements).
        try {
            $statements = $this->lazadaService->getPayoutStatus($shopId, $from);

            foreach ($statements as $stmt) {
                $statementNo = $stmt['statement_number'] ?? null;
                if (! $statementNo) {
                    continue;
                }

                [$payoutAmount, $currency] = $this->parseLazadaPayout($stmt['payout'] ?? null);
                $createdAt = $this->parseLazadaDate($stmt['created_at'] ?? null);
                $isPaid = (string) ($stmt['paid'] ?? '0') === '1';

                ChannelSettlement::updateOrCreate(
                    ['channel' => 'lazada', 'shop_id' => $shopId, 'external_id' => (string) $statementNo],
                    [
                        'type'             => 'STATEMENT',
                        'settled_at'       => $createdAt,
                        'paid_at'          => $isPaid ? ($this->parseLazadaDate($stmt['updated_at'] ?? null) ?? $createdAt) : null,
                        'payment_status'   => $isPaid ? 'PAID' : 'UNPAID',
                        'total_settlement' => $payoutAmount,
                        'total_fee'        => $this->toFloat($stmt['fees_total'] ?? null),
                        'total_adjustment' => $this->toFloat($stmt['refunds'] ?? null),
                        'currency'         => $currency ?? 'IDR',
                        'raw'              => $stmt,
                        'synced_at'        => now(),
                    ],
                );
                $count++;
            }
        } catch (\Throwable $e) {
            Log::warning("SettlementSync lazada payout {$shopId} gagal: " . $e->getMessage());
        }

        // 2) Per-order finance (tetap berjalan) — mengisi settled_at & fee level order.
        SalesOrder::query()
            ->where('source', 'lazada')
            ->where('channel_shop_id', $shop->shop_id)
            ->whereIn('channel_status', ['COMPLETED', 'DELIVERED'])
            ->whereNotNull('channel_order_no')
            ->where('updated_at', '>=', $from)
            ->where(fn ($q) => $q->where('is_settled', false)->orWhereNull('settled_at'))
            ->select('id')
            ->chunkById(200, function ($orders) {
                foreach ($orders as $order) {
                    SyncOrderFinanceJob::dispatch($order->id, true);
                }
            });

        return $count;
    }

    /**
     * Parse string payout Lazada mis. "3962.41 EUR" → [3962.41, "EUR"]; "3962.41" → [3962.41, null].
     */
    private function parseLazadaPayout(mixed $payout): array
    {
        if ($payout === null || $payout === '') {
            return [null, null];
        }

        if (preg_match('/([0-9.,]+)\s*([A-Za-z]{3})?/', trim((string) $payout), $m)) {
            $amount = $this->toFloat($m[1] ?? null);
            $currency = ! empty($m[2]) ? strtoupper($m[2]) : null;

            return [$amount, $currency];
        }

        return [$this->toFloat($payout), null];
    }

    private function parseLazadaDate(mixed $value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function fromTimestamp(mixed $ts): ?Carbon
    {
        if ($ts === null || $ts === '' || (int) $ts <= 0) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $ts);
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) str_replace(',', '', (string) $value);
    }
}
