<?php

namespace App\Providers;

use App\Support\Audit\AuditLogger;
use App\Support\Audit\StatusHistory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuditLogger::class);
        $this->app->singleton(StatusHistory::class);
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
    }
}
