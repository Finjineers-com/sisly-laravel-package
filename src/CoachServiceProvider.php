<?php

namespace Sisly\Coach;

use Illuminate\Support\ServiceProvider;
use Sisly\Coach\Services\AnthropicService;
use Sisly\Coach\Services\CoachService;
use Sisly\Coach\Services\SafetyService;
use Sisly\Coach\Services\ContentLibraryService;
use Sisly\Coach\Services\CoachStateService;

class CoachServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/sisly-coach.php',
            'sisly-coach'
        );

        $this->app->singleton(AnthropicService::class, function ($app) {
            return new AnthropicService(
                config('sisly-coach.anthropic.api_key'),
                config('sisly-coach.anthropic.coach_model'),
                config('sisly-coach.anthropic.safety_model'),
                config('sisly-coach.anthropic.max_tokens_coach'),
                config('sisly-coach.anthropic.max_tokens_safety')
            );
        });

        $this->app->singleton(ContentLibraryService::class, function ($app) {
            return new ContentLibraryService(
                config('sisly-coach.content_api.base_url'),
                config('sisly-coach.content_api.endpoint'),
                config('sisly-coach.content_api.timeout')
            );
        });

        $this->app->singleton(CoachStateService::class, function ($app) {
            return new CoachStateService(
                config('sisly-coach.state.driver'),
                config('sisly-coach.state.ttl_seconds')
            );
        });

        $this->app->singleton(SafetyService::class, function ($app) {
            return new SafetyService(
                $app->make(AnthropicService::class)
            );
        });

        $this->app->singleton(CoachService::class, function ($app) {
            return new CoachService(
                $app->make(AnthropicService::class),
                $app->make(SafetyService::class),
                $app->make(ContentLibraryService::class),
                $app->make(CoachStateService::class)
            );
        });
    }

    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/../config/sisly-coach.php' => config_path('sisly-coach.php'),
        ], 'sisly-coach-config');

        // Publish migrations
        $this->publishes([
            __DIR__ . '/../database/migrations/' => database_path('migrations'),
        ], 'sisly-coach-migrations');

        // Publish lang files
        $this->publishes([
            __DIR__ . '/../resources/lang' => $this->app->langPath('vendor/sisly-coach'),
        ], 'sisly-coach-lang');

        // Load migrations automatically if host app hasn't published them
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Load translations
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'sisly-coach');

        // Load routes
        if (config('sisly-coach.routing.enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        }
    }
}
