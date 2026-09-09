<?php

namespace Modules\Sales\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\FinanceSyncControlService;

class DispatchDueFinanceSync extends Command
{
    protected $signature = 'orders:dispatch-due-finance
        {--limit= : Maksimum order yang dijadwalkan pada satu putaran}';

    protected $description = 'Jadwalkan ulang finance sync yang memang sudah jatuh tempo secara idempotent dan terkontrol';

    public function handle(FinanceSyncControlService $dispatcher): int
    {
        if (! Schema::hasTable('finance_sync_states')) {
            $this->warn('finance_sync_states belum tersedia; dispatch due dilewati.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) ($this->option('limit') ?: config('finance_sync.dispatch_batch_size', 100)));
        $health = $dispatcher->health();
        $staleAt = now()->subMinutes((int) config('finance_sync.stale_processing_minutes', 20));

        if (! ($health['allowed'] ?? false)) {
            $this->warn('Finance dispatch ditahan oleh backpressure: '.json_encode($health, JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $query = SalesOrder::query()
            ->excludeShadow()
            ->whereIn('source', ['shopee', 'tiktok', 'lazada'])
            ->whereNotNull('channel_shop_id')
            ->whereNotNull('channel_order_no')
            ->where('is_settled', false)
            ->whereExists(function ($builder) use ($staleAt): void {
                $builder->selectRaw('1')
                    ->from('finance_sync_states')
                    ->whereColumn('finance_sync_states.order_id', 'sales_orders.id')
                    ->where(function ($eligible) use ($staleAt): void {
                        $eligible->where(function ($due): void {
                            $due->whereIn('finance_sync_states.status', ['waiting', 'failed', 'pending'])
                                ->where(function ($next): void {
                                    $next->whereNull('finance_sync_states.next_attempt_at')
                                        ->orWhere('finance_sync_states.next_attempt_at', '<=', now());
                                });
                        })
                            ->orWhere(function ($stale) use ($staleAt): void {
                                $stale->where('finance_sync_states.status', 'queued')
                                    ->where('finance_sync_states.updated_at', '<', $staleAt);
                            })
                            ->orWhere(function ($stale) use ($staleAt): void {
                                $stale->where('finance_sync_states.status', 'processing')
                                    ->where('finance_sync_states.locked_at', '<', $staleAt);
                            });
                    });
            })
            ->orderBy('id');

        $dispatched = 0;
        $query->limit($limit)->get()->each(function (SalesOrder $order) use ($dispatcher, &$dispatched): void {
            if ($dispatcher->dispatch($order)) {
                $dispatched++;
            }
        });

        $this->info("Finance sync due dijadwalkan: {$dispatched} order.");

        return self::SUCCESS;
    }
}
