<?php

namespace Modules\IssueTracker\Providers;

use Modules\IssueTracker\Console\MigrateAttachmentsToMediaLibraryCommand;
use Nwidart\Modules\Support\ModuleServiceProvider;

class IssueTrackerServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'IssueTracker';

    protected string $nameLower = 'issue-tracker';

    protected array $providers = [
        RouteServiceProvider::class,
    ];

    protected array $commands = [
        MigrateAttachmentsToMediaLibraryCommand::class,
    ];
}
