<?php

namespace App\Providers;

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
        // Re-running a triage spends tokens. Cheap ones, but a held-down button
        // is still a bill, so the write routes are capped per address.
        RateLimiter::for('demo-triage', fn (Request $request) => Limit::perHour(
            config('demo.triage_per_hour')
        )->by($request->ip()));
    }
}
