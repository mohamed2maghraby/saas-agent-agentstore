<?php

namespace SaasAgent\AgentStore;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use SaasAgent\AgentStore\Contract\ErrorCode;
use SaasAgent\AgentStore\Security\Signature;
use SaasAgent\AgentStore\Store\Capabilities;
use SaasAgent\AgentStore\Store\Console\DeliverCommand;
use SaasAgent\AgentStore\Store\Console\DisconnectCommand;
use SaasAgent\AgentStore\Store\Console\DoctorCommand;
use SaasAgent\AgentStore\Store\Console\InstallCommand;
use SaasAgent\AgentStore\Store\Console\PruneCommand;
use SaasAgent\AgentStore\Store\Http\ApiError;
use SaasAgent\AgentStore\Store\Http\Middleware\RequireCapability;
use SaasAgent\AgentStore\Store\Outbox\Outbox;

/**
 * Registers the store side of the contract.
 *
 * The SaaS installs this package too, for the contract DTOs and the signer, but
 * excludes it from auto-discovery — so none of this runs there.
 */
final class AgentStoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/agentstore.php', 'agentstore');

        $this->app->singleton(Capabilities::class);
        $this->app->singleton(Outbox::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/agentstore.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'agentstore');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'agentstore');

        $this->app['router']->aliasMiddleware('agentstore.capability', RequireCapability::class);

        RateLimiter::for('agentstore', function (Request $request) {
            $key = (string) $request->headers->get(Signature::KEY_HEADER, $request->ip());

            return Limit::perMinute((int) config('agentstore.routes.rate_limit', 600))
                ->by('agentstore:'.$key)
                ->response(fn () => ApiError::response(ErrorCode::RateLimited));
        });

        // Pairing completion is unsigned by necessity; keep guessing expensive.
        RateLimiter::for('agentstore-pairing', fn (Request $request) => Limit::perMinute(10)
            ->by('agentstore-pairing:'.$request->ip())
            ->response(fn () => ApiError::response(ErrorCode::RateLimited)));

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/agentstore.php' => config_path('agentstore.php')], 'agentstore-config');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'agentstore-migrations');

            $this->commands([
                InstallCommand::class,
                DoctorCommand::class,
                DeliverCommand::class,
                PruneCommand::class,
                DisconnectCommand::class,
            ]);
        }

        // The store already runs a scheduler; deliveries ride on it.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('agentstore:deliver')->everyMinute()->withoutOverlapping()->runInBackground();
            $schedule->command('agentstore:prune')->dailyAt('04:10')->withoutOverlapping();
        });
    }
}
