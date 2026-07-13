<?php

namespace Modules\Bantuan\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Modules\Bantuan\Console\Commands\ExportApiDocsCommand;
use Modules\Bantuan\Console\Commands\AuditApiDocsCommand;

class BantuanServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Bantuan';

    protected string $nameLower = 'bantuan';

    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->commands([
            ExportApiDocsCommand::class,
            AuditApiDocsCommand::class,
        ]);
    }
}
