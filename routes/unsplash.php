<?php

use Illuminate\Support\Facades\Route;
use hexa_package_unsplash\Http\Controllers\UnsplashController;

/*
|--------------------------------------------------------------------------
| Unsplash Package Routes
|--------------------------------------------------------------------------
| All routes behind core's auth + middleware stack.
| The service provider handles registration.
|--------------------------------------------------------------------------
*/

Route::middleware(['web', 'auth', 'locked', 'system_lock', 'two_factor', 'role'])->group(function () {

    // ── Raw dev page ──
    Route::get('/raw-unsplash', [UnsplashController::class, 'raw'])->name('unsplash.index');

    // ── AJAX endpoints ──
    Route::post('/unsplash/search', [UnsplashController::class, 'search'])->name('unsplash.search');

});
