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

        if ($slackWebhook = config('horizon.notifications.slack_webhook')) {
            Horizon::routeSlackNotificationsTo(
                $slackWebhook,
                config('horizon.notifications.slack_channel'),
            );
        }

        if ($mailTo = config('horizon.notifications.mail')) {
            Horizon::routeMailNotificationsTo($mailTo);
        }
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {

            if (app()->environment(['local', 'staging'])) {
                return true;
            }

            $allowed = (array) config('horizon.allowed_emails', []);

            if ($user !== null && in_array($user->email, $allowed, true)) {
                return true;
            }

            return $user !== null && method_exists($user, 'hasRole') && $user->hasRole('owner');
        });
    }

}
