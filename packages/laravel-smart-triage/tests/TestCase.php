<?php

namespace Solarise\SmartTriage\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Solarise\SmartTriage\SmartTriageServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SmartTriageServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('smart-triage.api_key', 'test-key');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->app['db']->connection()->getSchemaBuilder()->create('tickets', function ($table) {
            $table->id();
            $table->string('subject');
            $table->text('body');
            $table->string('channel')->default('email');
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });

        $this->app['db']->connection()->getSchemaBuilder()->create('teams', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('active')->default(true);
        });
    }
}
