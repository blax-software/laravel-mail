<?php

declare(strict_types=1);

namespace Blax\Mail;

use Blax\Mail\Commands\CleanupCommand;
use Blax\Mail\Commands\PollMailboxesCommand;
use Blax\Mail\Contracts\Dispatcher;
use Blax\Mail\Contracts\Poller;
use Blax\Mail\Services\ImapPoller;
use Blax\Mail\Services\MailDispatcher;
use Blax\Mail\Services\MailTracker;
use Blax\Mail\Services\MessageThreader;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Blax Mail package.
 *
 * Responsibilities:
 *
 *  - Merge the default config so consumers can override only the keys
 *    they care about (everything in `config/blax-mail.php` is
 *    environment-overridable).
 *  - Bind the public service contracts (`Dispatcher`, `Poller`) to the
 *    package's concrete implementations as singletons so consumer
 *    resolve calls share one instance per request.
 *  - Load package migrations + publish the config and the migrations
 *    under named tags so the host app can fork the schema if needed.
 *  - Register the artisan commands the host app's scheduler invokes
 *    (`blax-mail:poll`, `blax-mail:cleanup`).
 *  - Conditionally mount the tracking routes when
 *    `tracking.enabled = true`.
 *
 * The provider stays thin — every concern is delegated to a focused
 * service class. Tests can override individual bindings before app
 * resolution.
 */
class MailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/blax-mail.php', 'blax-mail');

        // Tracker is stateless — a single shared instance is fine and
        // saves an object alloc per dispatch.
        $this->app->singleton(MailTracker::class);
        $this->app->singleton(MessageThreader::class);

        // Public contracts → default concrete implementations. Tests
        // (and consumers who want to swap behaviour) override these
        // before `make()` is called on either contract.
        $this->app->singleton(Dispatcher::class, MailDispatcher::class);
        $this->app->singleton(Poller::class, ImapPoller::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/blax-mail.php' => config_path('blax-mail.php'),
            ], 'blax-mail-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'blax-mail-migrations');

            $this->commands([
                PollMailboxesCommand::class,
                CleanupCommand::class,
            ]);
        }

        $this->registerTrackingRoutes();
    }

    /**
     * Mount the open-pixel + click-redirect endpoints when tracking is
     * enabled. The route file owns the URI shape; this method owns the
     * prefix + middleware wrapping so the host app's config controls
     * both.
     */
    protected function registerTrackingRoutes(): void
    {
        if (! config('blax-mail.tracking.enabled', true)) {
            return;
        }

        Route::group([
            'prefix' => config('blax-mail.tracking.route_prefix', 'blax-mail/track'),
            'middleware' => config('blax-mail.tracking.middleware', ['web']),
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/tracking.php');
        });
    }
}
