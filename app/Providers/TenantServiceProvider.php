<?php

namespace App\Providers;

use App\Helper;
use Illuminate\Support\ServiceProvider;

class TenantServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind('tenant', function () {
            return Helper::resolveTenant();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
