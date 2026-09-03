<?php

declare(strict_types=1);

namespace Modules\Inventory\Console\Commands;

final class CutoverReplayOrdersCommand extends CutoverCommandSupport
{
    protected $signature = 'cutover:replay-orders
        {--run-id= : run_id cutover}
        {--limit=50 : Jumlah webhook per batch}
        {--apply : Dispatch webhook ke queue}
        {--confirm= : Wajib REPLAY-ORDERS saat apply}';

    protected $description = 'Memproses webhook tertahan secara bertahap setelah channel dibuka kembali.';

    public function handle(): int
    {
        return $this->safeHandle(function (): int {
            $apply = (bool) $this->option('apply');
            $this->confirmApply('REPLAY-ORDERS');
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($limit === false) {
                throw new \RuntimeException('--limit harus bilangan bulat positif.');
            }
            $result = $this->cutover()->replayOrders($this->runId(), (int) $limit, ! $apply);
            $this->report($result);

            return self::SUCCESS;
        });
    }
}
