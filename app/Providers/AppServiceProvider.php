<?php

namespace App\Providers;

use App\Models\Report;
use App\Observers\ReportObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Report::observe(ReportObserver::class);

        RateLimiter::for('reports-store', function (Request $request) {
            return Limit::perMinute(20)->by($request->ip());
        });

        RateLimiter::for('reports-confirm', function (Request $request) {
            return Limit::perMinute(15)->by($request->ip());
        });

        RateLimiter::for('fuel-assistant', function (Request $request) {
            $key = $request->input('client_id') ?: $request->ip();

            return Limit::perHour(40)->by($key);
        });

        RateLimiter::for('fuel-assistant-confirm', function (Request $request) {
            $key = $request->input('client_id') ?: $request->ip();

            return Limit::perHour(10)->by($key);
        });
    }
}
