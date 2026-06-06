<?php

namespace Modules\Inbound\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class InboundServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Inbound';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'inbound';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    /**
     * Define module schedules.
     * 
     * @param $schedule
     */
    
    
    
    
}
