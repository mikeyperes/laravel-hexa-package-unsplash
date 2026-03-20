<?php

namespace hexa_package_unsplash\Providers;

use Illuminate\Support\ServiceProvider;
use hexa_package_unsplash\Services\UnsplashService;

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

        // Sidebar menu injection (skipped when app controls sidebar)
        $this->registerSidebarMenu();
    }

    /**
     * Register sidebar menu items via view composer.
     *
     * @return void
     */
    private function registerSidebarMenu(): void
    {
        view()->composer('layouts.app', function ($view) {
            if (config('hexa.app_controls_sidebar', false)) return;
            $factory = app('view');
            $factory->startPush('sidebar-menu');
            echo $factory->make('unsplash::partials.sidebar-menu')->render();
            $factory->stopPush();
        });
    }
}
