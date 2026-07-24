<?php

namespace Ssuite\Core;

use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the shared backend shell package.
 *
 * Auto-discovered (see composer.json extra.laravel.providers). Today the package
 * ships only stateless service classes (Ssuite\Core\Services\*) that need no
 * container wiring, so register()/boot() are intentionally empty — they are the
 * seam for the next graduates that DO need it (package migrations via
 * loadMigrationsFrom(), publishable config, event listeners). Keep it lean until
 * a graduate requires more.
 */
class SsuiteCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // No bindings yet — Ssuite\Core\Services\PlanResolver is static/stateless.
    }

    public function boot(): void
    {
        // Seam for future graduates:
        //   $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        //   $this->publishes([__DIR__ . '/../config/xyz.php' => config_path('xyz.php')], 'ssuite-core-config');
    }
}
