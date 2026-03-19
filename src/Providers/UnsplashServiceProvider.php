<?php

namespace hexa_package_unsplash\Providers;

use Illuminate\Support\ServiceProvider;
use hexa_package_unsplash\Services\UnsplashService;

class UnsplashServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/unsplash.php', 'unsplash');
        $this->app->singleton(UnsplashService::class);
    }

    public function boot(): void {}
}
