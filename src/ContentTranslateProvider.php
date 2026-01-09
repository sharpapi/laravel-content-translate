<?php

declare(strict_types=1);

namespace SharpAPI\ContentTranslate;

use Illuminate\Support\ServiceProvider;

/**
 * @api
 */
class ContentTranslateProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/sharpapi-content-translate.php' => config_path('sharpapi-content-translate.php'),
            ], 'sharpapi-content-translate');
        }
    }

    /**
     * Register the application services.
     */
    public function register(): void
    {
        // Merge the package configuration with the app configuration.
        $this->mergeConfigFrom(
            __DIR__.'/../config/sharpapi-content-translate.php', 'sharpapi-content-translate'
        );
    }
}