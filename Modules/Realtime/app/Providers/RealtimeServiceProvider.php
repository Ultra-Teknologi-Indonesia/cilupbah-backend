<?php

namespace Modules\Realtime\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class RealtimeServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Realtime';

    protected string $nameLower = 'realtime';

    protected array $providers = [
        RouteServiceProvider::class,
    ];

    protected function registerViews(): void
    {
        $sourcePath = module_path(
            $this->name,
            config('modules.paths.generator.views.path'),
        );

        if (! is_dir($sourcePath)) {
            return;
        }

        parent::registerViews();
    }
}
