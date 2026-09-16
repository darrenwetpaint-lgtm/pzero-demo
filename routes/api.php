<?php

use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\ServiceRequestController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('portal.token')->group(function () {
    Route::get('customers/{customerNumber}', [CustomerController::class, 'show'])
        ->whereNumber('customerNumber');

    Route::post('service-requests', [ServiceRequestController::class, 'store']);
    Route::get('service-requests', [ServiceRequestController::class, 'index']);
    Route::get('service-requests/{serviceRequest}', [ServiceRequestController::class, 'show'])
        ->whereNumber('serviceRequest');
});
