<?php

declare(strict_types=1);

namespace Modules\Inventory\Console\Commands;

final class CutoverPauseCommand extends CutoverCommandSupport
{
    protected $signature = 'cutover:pause
        {--run-id= : run_id cutover}
        {--apply : Pertahankan sync order; matikan push stok dan push fulfillment}
        {--confirm= : Wajib PAUSE-CUTOVER saat apply}';

    protected $description = 'Menjaga sync order tetap hidup sambil memastikan push stok dan fulfillment tetap mati selama cutover.';

    public function handle(): int
    {
        return $this->safeHandle(function (): int {
            $apply = (bool) $this->option('apply');
            $this->confirmApply('PAUSE-CUTOVER');
            $count = $this->cutover()->pause($this->runId(), ! $apply);
            $this->line(($apply ? 'channel diamankan: ' : 'DRY-RUN channel yang akan diamankan: ').$count);

            return self::SUCCESS;
        });
    }
}
