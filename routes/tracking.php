<?php

declare(strict_types=1);

use Blax\Mail\Http\Controllers\TrackingController;
use Illuminate\Support\Facades\Route;

/**
 * Tracking endpoints — mounted by the service provider under
 * `config('blax-mail.tracking.route_prefix')` with the configured
 * middleware stack. The provider only registers this file when
 * `tracking.enabled = true` so disabling tracking removes the routes
 * entirely (no public surface to probe).
 */
Route::get('open/{token}.gif', [TrackingController::class, 'open'])
    ->where('token', '[A-Za-z0-9]+')
    ->name('blax-mail.tracking.open');

Route::get('click/{token}', [TrackingController::class, 'click'])
    ->where('token', '[A-Za-z0-9]+')
    ->name('blax-mail.tracking.click');
