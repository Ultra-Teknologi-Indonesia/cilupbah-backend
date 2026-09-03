<?php

declare(strict_types=1);

namespace Modules\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Modules\Inventory\Services\StockCutoverService;

abstract class CutoverCommandSupport extends Command
{
    protected function cutover(): StockCutoverService
    {
        return app(StockCutoverService::class);
    }

    protected function runId(): string
    {
        $runId = trim((string) $this->option('run-id'));
        if ($runId === '') {
            throw new \RuntimeException('--run-id wajib diisi. Gunakan run_id dari cutover:preflight.');
        }

        return $runId;
    }

    protected function locationCodes(): array
    {
        return collect(explode(',', (string) $this->option('locations')))
            ->map(fn ($code): string => strtoupper(trim((string) $code)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function confirmApply(string $token): void
    {
        if (! (bool) $this->option('apply')) {
            return;
        }
        if ((string) $this->option('confirm') !== $token) {
            throw new \RuntimeException("apply ditolak, gunakan --confirm={$token}.");
        }
    }

    protected function report(array $report): void
    {
        foreach ($report as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $this->line(sprintf('%s: %s', $key, $value === null ? '-' : (string) $value));
            }
        }
        if (isset($report['blocking'])) {
            $report['blocking'] > 0 ? $this->error('blocking issue ditemukan, apply harus dihentikan.') : $this->info('tidak ada blocking issue.');
        }
    }

    protected function safeHandle(callable $callback): int
    {
        try {
            return (int) $callback();
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
