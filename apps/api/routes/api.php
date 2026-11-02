<?php

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| The customer website, the admin portal and the mobile app all use this one
| API. Every route lives under /api/v1. Customer and admin routes are added
| by their epics (E5 onward) in route groups of their own.
|
*/

Route::prefix('v1')->name('v1.')->group(function () {
    Route::get('health', HealthController::class)->name('health');
});
