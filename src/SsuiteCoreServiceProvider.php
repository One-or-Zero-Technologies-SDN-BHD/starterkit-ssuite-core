<?php

namespace Ssuite\Core;

use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Ssuite\Core\Database\CentralMigrationLock;

/**
 * Service provider for the shared backend shell package.
 *
 * Auto-discovered (see composer.json extra.laravel.providers). Graduated shell
 * classes are stateless and need no container wiring; the provider registers
 * the package config, the outbound HTTP macro and the cross-app central
 * migration lock.
 */
class SsuiteCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ssuite-core.php', 'ssuite-core');

        $this->app->singleton(CentralMigrationLock::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/ssuite-core.php' => config_path('ssuite-core.php'),
        ], 'ssuite-core-config');

        // Payment gateways call Http::outbound(): every third-party request carries
        // a request and a connect timeout. An app that defines its own macro wins.
        if (! Http::hasMacro('outbound')) {
            Http::macro('outbound', fn (): PendingRequest => Http::timeout((int) config('services.outbound.timeout', 10))
                ->connectTimeout((int) config('services.outbound.connect_timeout', 5)));
        }

        Event::listen(MigrationsStarted::class, fn () => $this->app->make(CentralMigrationLock::class)->acquire());
        Event::listen(MigrationsEnded::class, fn () => $this->app->make(CentralMigrationLock::class)->release());
    }
}
