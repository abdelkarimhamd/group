<?php

use App\Http\Controllers\Api\V1\HealthController;
use App\Modules\Campaign\Http\Controllers\AdminCampaignController;
use App\Modules\Campaign\Http\Controllers\CampaignController;
use App\Modules\Campaign\Http\Controllers\ParticipationController;
use App\Modules\Catalog\Http\Controllers\AdminCategoryController;
use App\Modules\Catalog\Http\Controllers\AdminProductController;
use App\Modules\Catalog\Http\Controllers\AdminProductImageController;
use App\Modules\Catalog\Http\Controllers\AdminVariantController;
use App\Modules\Catalog\Http\Controllers\CategoryController;
use App\Modules\Identity\Http\Controllers\AccountController;
use App\Modules\Identity\Http\Controllers\AddressController;
use App\Modules\Identity\Http\Controllers\AdminAccountsController;
use App\Modules\Identity\Http\Controllers\AdminAuthController;
use App\Modules\Identity\Http\Controllers\CustomerAuthController;
use App\Modules\Payments\Http\Controllers\AdminPaymentController;
use App\Modules\Payments\Http\Controllers\MoyasarWebhookController;
use App\Modules\Payments\Http\Controllers\PaymentController;
use App\Modules\Supplier\Http\Controllers\AdminQuotationController;
use App\Modules\Supplier\Http\Controllers\AdminSupplierController;
use Illuminate\Support\Facades\Route;

/*
| Every route here must appear in openapi/v1.yaml with x-status: live.
| Rate limiters are defined in AppServiceProvider.
*/

Route::prefix('v1')->name('v1.')->group(function () {
    Route::get('health', HealthController::class)->name('health');

    // ------------------------------------------------------------ Catalog (E6)
    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');

    // ------------------------------------------------------------ Campaigns (E7)
    Route::get('campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
    Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])->whereUuid('campaign')->name('campaigns.show');
    Route::get('campaigns/{campaign}/progress', [CampaignController::class, 'progress'])->whereUuid('campaign')->name('campaigns.progress');

    // ------------------------------------------------------------ Moyasar (E9)
    Route::post('webhooks/moyasar', MoyasarWebhookController::class)->middleware('throttle:webhooks')->name('webhooks.moyasar');

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

        Route::post('campaigns/{campaign}/participations', [ParticipationController::class, 'store'])->whereUuid('campaign')
            ->middleware('throttle:joins')->name('campaigns.join');

        // Payments (E9)
        Route::post('payments/{payment}/sync', [PaymentController::class, 'sync'])->whereUuid('payment')
            ->middleware('throttle:payment-sync')->name('payments.sync');
        Route::post('me/participations/{participation}/payments', [PaymentController::class, 'retry'])->whereUuid('participation')
            ->middleware('throttle:joins')->name('me.participations.payments');
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
            Route::post('me/password', [AdminAuthController::class, 'changePassword'])
                ->middleware('throttle:admin-password')->name('me.password');

            // Each route checks the permission its contract operation names in x-permission.
            Route::get('roles', [AdminAccountsController::class, 'roles'])->can('admins.manage')->name('roles.index');
            Route::get('admins', [AdminAccountsController::class, 'index'])->can('admins.manage')->name('admins.index');
            Route::post('admins', [AdminAccountsController::class, 'store'])->can('admins.manage')->name('admins.store');
            Route::patch('admins/{admin}', [AdminAccountsController::class, 'update'])->whereUuid('admin')
                ->can('admins.manage')->name('admins.update');

            // Catalog (E6)
            Route::get('categories', [AdminCategoryController::class, 'index'])->can('catalog.view')->name('categories.index');
            Route::post('categories', [AdminCategoryController::class, 'store'])->can('catalog.edit')->name('categories.store');
            Route::patch('categories/{category}', [AdminCategoryController::class, 'update'])->whereUuid('category')
                ->can('catalog.edit')->name('categories.update');

            Route::get('products', [AdminProductController::class, 'index'])->can('catalog.view')->name('products.index');
            Route::post('products', [AdminProductController::class, 'store'])->can('catalog.edit')->name('products.store');
            Route::get('products/{product}', [AdminProductController::class, 'show'])->whereUuid('product')
                ->can('catalog.view')->name('products.show');
            Route::patch('products/{product}', [AdminProductController::class, 'update'])->whereUuid('product')
                ->can('catalog.edit')->name('products.update');
            Route::post('products/{product}/variants', [AdminVariantController::class, 'store'])->whereUuid('product')
                ->can('catalog.edit')->name('variants.store');
            Route::patch('variants/{variant}', [AdminVariantController::class, 'update'])->whereUuid('variant')
                ->can('catalog.edit')->name('variants.update');
            Route::post('products/{product}/images', [AdminProductImageController::class, 'store'])->whereUuid('product')
                ->can('catalog.edit')->name('product-images.store');
            Route::delete('product-images/{image}', [AdminProductImageController::class, 'destroy'])->whereUuid('image')
                ->can('catalog.edit')->name('product-images.destroy');

            // Suppliers and quotations (E6)
            Route::get('suppliers', [AdminSupplierController::class, 'index'])->can('suppliers.view')->name('suppliers.index');
            Route::post('suppliers', [AdminSupplierController::class, 'store'])->can('suppliers.edit')->name('suppliers.store');
            Route::get('suppliers/{supplier}', [AdminSupplierController::class, 'show'])->whereUuid('supplier')
                ->can('suppliers.view')->name('suppliers.show');
            Route::patch('suppliers/{supplier}', [AdminSupplierController::class, 'update'])->whereUuid('supplier')
                ->can('suppliers.edit')->name('suppliers.update');
            Route::post('suppliers/{supplier}/review', [AdminSupplierController::class, 'review'])->whereUuid('supplier')
                ->can('suppliers.approve')->name('suppliers.review');

            Route::get('quotations', [AdminQuotationController::class, 'index'])->can('suppliers.view')->name('quotations.index');
            Route::post('quotations', [AdminQuotationController::class, 'store'])->can('suppliers.edit')->name('quotations.store');
            Route::get('quotations/{quotation}', [AdminQuotationController::class, 'show'])->whereUuid('quotation')
                ->can('suppliers.view')->name('quotations.show');
            Route::patch('quotations/{quotation}', [AdminQuotationController::class, 'update'])->whereUuid('quotation')
                ->can('suppliers.edit')->name('quotations.update');
            Route::post('quotations/{quotation}/attachment', [AdminQuotationController::class, 'attach'])->whereUuid('quotation')
                ->can('suppliers.edit')->name('quotations.attachment');

            // Campaigns (E7) and pricing (E8)
            Route::get('campaigns', [AdminCampaignController::class, 'index'])->can('campaigns.view')->name('campaigns.index');
            Route::post('campaigns', [AdminCampaignController::class, 'store'])->can('campaigns.edit')->name('campaigns.store');
            Route::prefix('campaigns/{campaign}')->whereUuid('campaign')->name('campaigns.')->group(function () {
                Route::get('/', [AdminCampaignController::class, 'show'])->can('campaigns.view')->name('show');
                Route::patch('/', [AdminCampaignController::class, 'update'])->can('campaigns.edit')->name('update');
                Route::put('tiers', [AdminCampaignController::class, 'replaceTiers'])->can('pricing.edit')->name('tiers');
                Route::get('margin', [AdminCampaignController::class, 'margin'])->can('campaigns.view')->name('margin');
                Route::post('submit', [AdminCampaignController::class, 'submit'])->can('campaigns.edit')->name('submit');
                Route::post('approve', [AdminCampaignController::class, 'approve'])->can('campaigns.approve')->name('approve');
                Route::post('reject', [AdminCampaignController::class, 'reject'])->can('campaigns.approve')->name('reject');
                Route::get('extension-window', [AdminCampaignController::class, 'extensionWindow'])->can('campaigns.view')->name('extension-window');
                Route::post('extend', [AdminCampaignController::class, 'extend'])->can('campaigns.extend')->name('extend');
                Route::post('cancel', [AdminCampaignController::class, 'cancel'])->can('campaigns.cancel')->name('cancel');
                Route::post('supplier-decision', [AdminCampaignController::class, 'supplierDecision'])->can('campaigns.close')->name('supplier-decision');
                Route::post('below-target-decision', [AdminCampaignController::class, 'belowTargetDecision'])->can('campaigns.close')->name('below-target-decision');
                Route::get('participations', [AdminCampaignController::class, 'participations'])->can('campaigns.view')->name('participations');
                Route::get('events', [AdminCampaignController::class, 'events'])->can('campaigns.view')->name('events');
            });

            // Payments (E9)
            Route::get('payments', [AdminPaymentController::class, 'index'])->can('payments.view')->name('payments.index');
            Route::get('payments/{payment}', [AdminPaymentController::class, 'show'])->whereUuid('payment')
                ->can('payments.view')->name('payments.show');
        });
    });
});
