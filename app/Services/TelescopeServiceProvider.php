<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    public function register(): void
    {
        // Only record in local/development — disable on production Hostinger
        // To enable on production temporarily: set TELESCOPE_ENABLED=true in .env
        Telescope::night();

        $this->hideSensitiveRequestDetails();

        $isLocal = $this->app->environment('local');

        Telescope::filter(function (IncomingEntry $entry) use ($isLocal) {
            return $isLocal
                || $entry->isReportableException()
                || $entry->isFailedRequest()
                || $entry->isFailedJob()
                || $entry->isScheduledTask()
                || $entry->hasMonitoredTag();
        });
    }

    protected function hideSensitiveRequestDetails(): void
    {
        if ($this->app->environment('local')) {
            return;
        }
        Telescope::hideRequestParameters(['_token']);
        Telescope::hideRequestHeaders([
            'cookie', 'x-csrf-token', 'x-xsrf-token',
        ]);
    }

    protected function gate(): void
    {
        // Only admin role can access /telescope
        Gate::define('viewTelescope', function (User $user) {
            return $user->role === 'admin';
        });
    }
}
