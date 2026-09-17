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

    /**
     * Realtime is an API-only module and intentionally has no Blade views.
     *
     * Nwidart's base provider assumes every module has a resources/views
     * directory. Without this guard, `artisan optimize` and `view:cache`
     * abort the container during startup with DirectoryNotFoundException.
     */
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
