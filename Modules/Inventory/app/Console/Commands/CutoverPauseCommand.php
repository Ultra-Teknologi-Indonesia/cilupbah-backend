<?php

declare(strict_types=1);

namespace Modules\Inventory\Console\Commands;

final class CutoverPauseCommand extends CutoverCommandSupport
{
    protected $signature = 'cutover:pause
        {--run-id= : run_id cutover}
        {--apply : Matikan intake order, push stok, dan push fulfillment}
        {--confirm= : Wajib PAUSE-CUTOVER saat apply}';

    protected $description = 'Menghentikan pemrosesan channel sementara, webhook tetap tersimpan di inbox.';

    public function handle(): int
    {
        return $this->safeHandle(function (): int {
            $apply = (bool) $this->option('apply');
            $this->confirmApply('PAUSE-CUTOVER');
            $count = $this->cutover()->pause($this->runId(), ! $apply);
            $this->line(($apply ? 'channel dipause: ' : 'DRY-RUN channel yang akan dipause: ').$count);

            return self::SUCCESS;
        });
    }
}
