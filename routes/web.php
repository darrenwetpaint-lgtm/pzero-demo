<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ServiceRequestController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

// Email verification is intentionally not part of this demo (see
// DECISIONS.md): `App\Models\User` does not implement `MustVerifyEmail`,
// which makes Laravel's `verified` middleware a permissive no-op for every
// user regardless of `email_verified_at` — leaving it here would misleadingly
// imply an enforced security control that doesn't actually exist. `auth` is
// the real, enforced access control for these routes.
Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('service-requests', [ServiceRequestController::class, 'index'])->name('service-requests.index');
    Route::get('service-requests/{serviceRequest}', [ServiceRequestController::class, 'show'])
        ->whereNumber('serviceRequest')
        ->name('service-requests.show');
    Route::post('service-requests/{serviceRequest}/retry', [ServiceRequestController::class, 'retry'])
        ->whereNumber('serviceRequest')
        ->name('service-requests.retry');
});

require __DIR__.'/settings.php';
