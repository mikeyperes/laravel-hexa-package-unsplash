<?php

namespace hexa_package_unsplash\Providers;

use Illuminate\Support\ServiceProvider;
use hexa_package_unsplash\Services\UnsplashService;
use hexa_core\Services\PackageRegistryService;

/**
 * UnsplashServiceProvider — registers Unsplash package routes, views, config,
 * and sidebar menu for the Hexa Core framework.
 */
class UnsplashServiceProvider extends ServiceProvider
{
    /**
     * Register bindings in the container.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/unsplash.php', 'unsplash');
        $this->app->singleton(UnsplashService::class);
    }

    /**
     * Bootstrap package services.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../../routes/unsplash.php');
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'unsplash');

        // Sidebar links — registered via PackageRegistryService with auto permission checks
        if (!config('hexa.app_controls_sidebar', false)) {
            $registry = app(PackageRegistryService::class);
            $registry->registerSidebarLink('unsplash.index', 'Unsplash', 'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z', 'Sandbox', 'unsplash', 86);
        }
    }
}
