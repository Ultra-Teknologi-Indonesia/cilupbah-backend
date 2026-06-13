<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{

    public function boot(): void
    {
        parent::boot();

    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {

            if (app()->environment(['local', 'staging'])) {
                return true;
            }

            return $user !== null && method_exists($user, 'hasRole') && $user->hasRole('owner');
        });
    }

}
