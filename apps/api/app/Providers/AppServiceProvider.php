<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\StatusTransition;
use App\Modules\Campaign\Events\CampaignClosingStarted;
use App\Modules\Campaign\Events\CampaignProgressChanged;
use App\Modules\Campaign\Listeners\QueueProgressBroadcast;
use App\Modules\Campaign\Listeners\StartClosingJob;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignParticipation;
use App\Modules\Campaign\Services\CampaignQuotationUsage;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Finance\Models\ApprovalRequest;
use App\Modules\Finance\Models\LedgerAdjustment;
use App\Modules\Finance\Models\LedgerTransaction;
use App\Modules\Finance\Models\MoyasarSettlement;
use App\Modules\Finance\Models\ReconciliationItem;
use App\Modules\Finance\Models\ReconciliationRun;
use App\Modules\Fulfillment\Couriers\AramexCourier;
use App\Modules\Fulfillment\Couriers\CourierGateway;
use App\Modules\Fulfillment\Couriers\FakeCourier;
use App\Modules\Fulfillment\Couriers\ManualCourier;
use App\Modules\Fulfillment\Models\InboundReceipt;
use App\Modules\Fulfillment\Models\Shipment;
use App\Modules\Identity\Admins\AdminAccounts;
use App\Modules\Identity\Models\Address;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Models\OtpChallenge;
use App\Modules\Identity\Models\PrivacyConsent;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Otp\OtpService;
use App\Modules\Notifications\Push\ExpoPushSender;
use App\Modules\Notifications\Push\LogPushSender;
use App\Modules\Notifications\Push\PushSender;
use App\Modules\Notifications\Sms\LogSmsSender;
use App\Modules\Notifications\Sms\SmsSender;
use App\Modules\Notifications\Sms\TaqnyatSmsSender;
use App\Modules\Notifications\Sms\UnifonicSmsSender;
use App\Modules\Orders\Models\CustomerOrder;
use App\Modules\Orders\Models\Invoice;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Models\PurchaseOrderDocument;
use App\Modules\Orders\Models\SupplierPurchaseOrder;
use App\Modules\Payments\Gateway\FakeMoyasarGateway;
use App\Modules\Payments\Gateway\MoyasarGateway;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Gateway\PaymentReports;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Models\Refund;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierQuotation;
use App\Modules\Supplier\Services\QuotationUsage;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\StatusHistory;
use App\Support\Phone\SaudiMobile;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuditLogger::class);
        $this->app->singleton(StatusHistory::class);
        $this->app->singleton(OtpService::class);
        $this->app->singleton(AdminAccounts::class);

        $moyasar = fn ($app) => match ($driver = config('services.moyasar.driver')) {
            'fake' => new FakeMoyasarGateway($app->isProduction()),
            'moyasar' => new MoyasarGateway(
                (string) config('services.moyasar.secret_key'),
                (string) config('services.moyasar.publishable_key'),
                (string) config('services.moyasar.base_url'),
                (int) config('services.moyasar.timeout_seconds'),
                (int) config('services.moyasar.connect_timeout_seconds'),
                $app->isProduction(),
            ),
            default => throw new InvalidArgumentException("Unknown Moyasar driver [{$driver}]."),
        };
        $this->app->singleton(PaymentGateway::class, $moyasar);
        // Moyasar's payment and settlement lists, for reconciliation and the ledger.
        $this->app->singleton(PaymentReports::class, $moyasar);

        // The Supplier module asks which campaigns use a quotation; the Campaign module answers.
        $this->app->bind(QuotationUsage::class, CampaignQuotationUsage::class);

        $this->app->singleton(SmsSender::class, fn ($app) => match ($driver = config('services.sms.driver')) {
            'log' => new LogSmsSender($app->isProduction(), config('services.sms.log_channel')),
            'unifonic' => new UnifonicSmsSender(
                (string) config('services.sms.unifonic.app_sid'),
                (string) config('services.sms.sender'),
                (string) config('services.sms.unifonic.base_url'),
                (int) config('services.sms.timeout_seconds'),
            ),
            'taqnyat' => new TaqnyatSmsSender(
                (string) config('services.sms.taqnyat.token'),
                (string) config('services.sms.sender'),
                (string) config('services.sms.taqnyat.base_url'),
                (int) config('services.sms.timeout_seconds'),
            ),
            default => throw new InvalidArgumentException("Unknown SMS driver [{$driver}]."),
        });

        $this->app->singleton(CourierGateway::class, fn ($app) => match ($driver = config('services.courier.driver')) {
            'manual' => new ManualCourier,
            'fake' => new FakeCourier($app->isProduction()),
            'aramex' => new AramexCourier(
                (string) config('services.courier.aramex.base_url'),
                config('services.courier.aramex'),
                config('groupbuy.couriers.shipper'),
                (int) config('services.courier.timeout_seconds'),
            ),
            default => throw new InvalidArgumentException("Unknown courier driver [{$driver}]."),
        });

        $this->app->singleton(PushSender::class, fn ($app) => match ($driver = config('services.push.driver')) {
            'log' => new LogPushSender($app->isProduction(), config('services.push.log_channel')),
            'expo' => new ExpoPushSender(
                (string) config('services.push.expo.url'),
                (string) config('services.push.expo.access_token'),
                (int) config('services.push.expo.timeout_seconds'),
            ),
            default => throw new InvalidArgumentException("Unknown push driver [{$driver}]."),
        });
    }

    public function boot(): void
    {
        // Dates never change under you: every now() and model date is immutable.
        Date::use(CarbonImmutable::class);

        // Outside production, fail loudly on lazy loading, unknown attributes
        // and silently discarded attributes.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Block migrate:fresh, db:wipe and similar commands against production.
        DB::prohibitDestructiveCommands($this->app->isProduction());

        // Polymorphic columns (audit subjects, token owners, role holders) store
        // these short names, never class names, so classes can move freely.
        Relation::enforceMorphMap([
            'user' => User::class,
            'admin_user' => AdminUser::class,
            'address' => Address::class,
            'otp_challenge' => OtpChallenge::class,
            'privacy_consent' => PrivacyConsent::class,
            'audit_log' => AuditLog::class,
            'status_transition' => StatusTransition::class,
            'category' => Category::class,
            'product' => Product::class,
            'product_variant' => ProductVariant::class,
            'product_image' => ProductImage::class,
            'supplier' => Supplier::class,
            'supplier_quotation' => SupplierQuotation::class,
            'campaign' => Campaign::class,
            'campaign_participation' => CampaignParticipation::class,
            'payment_authorization' => PaymentAuthorization::class,
            'approval_request' => ApprovalRequest::class,
            'customer_order' => CustomerOrder::class,
            'invoice' => Invoice::class,
            'refund' => Refund::class,
            'supplier_purchase_order' => SupplierPurchaseOrder::class,
            'purchase_order_document' => PurchaseOrderDocument::class,
            'inbound_receipt' => InboundReceipt::class,
            'shipment' => Shipment::class,
            'order_return' => OrderReturn::class,
            'ledger_transaction' => LedgerTransaction::class,
            'ledger_adjustment' => LedgerAdjustment::class,
            'moyasar_settlement' => MoyasarSettlement::class,
            'reconciliation_run' => ReconciliationRun::class,
            'reconciliation_item' => ReconciliationItem::class,
        ]);

        // A campaign that starts closing gets its closing job.
        Event::listen(CampaignClosingStarted::class, StartClosingJob::class);
        // Customers see progress change live (Reverb).
        Event::listen(CampaignProgressChanged::class, QueueProgressBroadcast::class);

        $this->configureTokens();
        $this->configureRateLimits();
    }

    /**
     * A blocked or anonymized account's tokens stop working at once, before
     * they expire. Which guard accepts which model is set in config/auth.php.
     */
    private function configureTokens(): void
    {
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $isValid): bool => $isValid
                && ($token->tokenable instanceof User || $token->tokenable instanceof AdminUser)
                && $token->tokenable->isActive(),
        );
    }

    /**
     * Limits in front of sign-in. Numbers come from config('groupbuy.auth').
     * A 429 carries Retry-After.
     */
    private function configureRateLimits(): void
    {
        RateLimiter::for('otp-request', function (Request $request): array {
            $otp = config('groupbuy.auth.otp');
            $limits = [Limit::perHour((int) $otp['per_ip_per_hour'])->by('ip:'.$request->ip())];

            // Phone limits only apply to valid numbers, so typos get a 422, not a 429.
            if (($phone = SaudiMobile::normalize($request->input('phone'))) !== null) {
                $limits[] = Limit::perSecond(1, (int) $otp['resend_seconds'])->by('resend:'.$phone);
                $limits[] = Limit::perHour((int) $otp['per_phone_per_hour'])->by('phone:'.$phone);
            }

            return $limits;
        });

        RateLimiter::for('joins', fn (Request $request): Limit => Limit::perMinute(
            (int) config('groupbuy.campaigns.joins_per_minute'),
        )->by('user:'.$request->user()?->getAuthIdentifier()));

        RateLimiter::for('payment-sync', fn (Request $request): Limit => Limit::perMinute(
            (int) config('groupbuy.payments.syncs_per_minute'),
        )->by('user:'.$request->user()?->getAuthIdentifier()));

        RateLimiter::for('webhooks', fn (Request $request): Limit => Limit::perMinute(
            (int) config('groupbuy.payments.webhooks_per_ip_per_minute'),
        )->by('ip:'.$request->ip()));

        RateLimiter::for('otp-verify', fn (Request $request): Limit => Limit::perMinute(
            (int) config('groupbuy.auth.otp.verify_per_ip_per_minute'),
        )->by('ip:'.$request->ip()));

        RateLimiter::for('admin-password', fn (Request $request): Limit => Limit::perMinutes(
            (int) config('groupbuy.auth.admin_login.window_minutes'),
            (int) config('groupbuy.auth.admin_login.per_email_and_ip'),
        )->by('admin:'.$request->user()?->getAuthIdentifier()));

        RateLimiter::for('admin-login', function (Request $request): array {
            $config = config('groupbuy.auth.admin_login');
            $email = is_string($email = $request->input('email')) ? mb_strtolower(trim($email)) : '';

            return [
                Limit::perMinutes((int) $config['window_minutes'], (int) $config['per_email'])->by('email:'.$email),
                Limit::perMinutes((int) $config['window_minutes'], (int) $config['per_email_and_ip'])->by('email-ip:'.$email.'|'.$request->ip()),
                Limit::perMinutes((int) $config['window_minutes'], (int) $config['per_ip'])->by('ip:'.$request->ip()),
            ];
        });
    }
}
