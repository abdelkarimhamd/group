<?php

use App\Http\Controllers\Api\V1\HealthController;
use App\Modules\Identity\Http\Controllers\AccountController;
use App\Modules\Identity\Http\Controllers\AddressController;
use App\Modules\Identity\Http\Controllers\AdminAuthController;
use App\Modules\Identity\Http\Controllers\CustomerAuthController;
use Illuminate\Support\Facades\Route;

/*
| Every route here must appear in openapi/v1.yaml with x-status: live.
| Rate limiters are defined in AppServiceProvider.
*/

Route::prefix('v1')->name('v1.')->group(function () {
    Route::get('health', HealthController::class)->name('health');

    // ------------------------------------------------------------ Customers (E5)
    Route::post('auth/otp', [CustomerAuthController::class, 'requestOtp'])
        ->middleware('throttle:otp-request')->name('auth.otp');
    Route::post('auth/otp/verify', [CustomerAuthController::class, 'verify'])
        ->middleware('throttle:otp-verify')->name('auth.otp.verify');

    Route::middleware('auth:customer')->group(function () {
        Route::post('auth/logout', [CustomerAuthController::class, 'logout'])->name('auth.logout');

        Route::get('me', [AccountController::class, 'show'])->name('me.show');
        Route::patch('me', [AccountController::class, 'update'])->name('me.update');
        Route::post('me/privacy-consent', [AccountController::class, 'acceptPrivacyPolicy'])->name('me.privacy-consent');

        Route::get('me/addresses', [AddressController::class, 'index'])->name('me.addresses.index');
        Route::post('me/addresses', [AddressController::class, 'store'])->name('me.addresses.store');
        Route::patch('me/addresses/{address}', [AddressController::class, 'update'])->whereUuid('address')->name('me.addresses.update');
        Route::delete('me/addresses/{address}', [AddressController::class, 'destroy'])->whereUuid('address')->name('me.addresses.destroy');
    });

    // ------------------------------------------------------------ Admins (E5)
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::post('auth/login', [AdminAuthController::class, 'login'])
            ->middleware('throttle:admin-login')->name('auth.login');
        Route::post('auth/verify', [AdminAuthController::class, 'verify'])
            ->middleware('throttle:otp-verify')->name('auth.verify');

        Route::middleware('auth:admin')->group(function () {
            Route::post('auth/logout', [AdminAuthController::class, 'logout'])->name('auth.logout');
            Route::get('me', [AdminAuthController::class, 'me'])->name('me');
        });
    });
});
