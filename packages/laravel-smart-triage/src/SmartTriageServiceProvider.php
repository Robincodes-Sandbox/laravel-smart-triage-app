<?php

namespace Solarise\SmartTriage;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Solarise\SmartTriage\Console\TriageCommand;
use Solarise\SmartTriage\Contracts\Judge;
use Solarise\SmartTriage\Exceptions\TriageException;

class SmartTriageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/smart-triage.php', 'smart-triage');

        $this->app->singleton(Judge::class, function ($app) {
            $config = $app['config']['smart-triage'];
            $name = $config['driver'];
            $driver = $config['drivers'][$name] ?? null;

            if ($driver === null) {
                throw new InvalidArgumentException(
                    "No smart-triage driver configured under [{$name}]."
                );
            }

            if (blank($driver['api_key'] ?? null)) {
                throw new TriageException(
                    "No API key configured for the [{$name}] triage driver. Set it in .env — server-side only."
                );
            }

            return new $driver['client'](
                http: $app->make(HttpFactory::class),
                apiKey: $driver['api_key'],
                baseUrl: rtrim($driver['base_url'], '/'),
                model: $driver['model'],
                timeout: $config['timeout'],
                retries: $config['retries'],
                concurrency: $config['concurrency'],
                requestsPerMinute: $config['requests_per_minute'],
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/smart-triage.php' => config_path('smart-triage.php'),
        ], 'smart-triage-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'smart-triage-migrations');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([TriageCommand::class]);
        }
    }
}
