<?php

namespace Modules\Sales\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Sales\Services\FinanceSyncControlService;

class FinanceQueueHealth extends Command
{
    protected $signature = 'orders:finance-queue-health {--json : Output JSON untuk monitoring}';

    protected $description = 'Periksa kedalaman queue finance, memory Redis, dan state durable tanpa mengubah data';

    public function handle(FinanceSyncControlService $control): int
    {
        $health = $control->health();
        $health['states'] = [];

        if (Schema::hasTable('finance_sync_states')) {
            $health['states'] = DB::table('finance_sync_states')
                ->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status')
                ->map(fn ($value) => (int) $value)
                ->all();
        }

        $health['thresholds'] = [
            'max_queue_depth' => (int) config('finance_sync.max_queue_depth', 5000),
            'max_redis_memory_ratio' => (float) config('finance_sync.max_redis_memory_ratio', 0.80),
        ];

        if (! ($health['allowed'] ?? false)) {
            Log::error('Finance queue backpressure active', $health);
        }

        if ($this->option('json')) {
            $this->line(json_encode($health, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->table(['Metrik', 'Nilai'], collect($health)->map(fn ($value, $key) => [
                $key,
                is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value,
            ])->values()->all());
        }

        return ($health['allowed'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
