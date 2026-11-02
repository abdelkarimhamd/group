<?php

use App\Modules\Campaign\Console\CampaignTickCommand;
use App\Modules\Campaign\Console\SweepClosingCommand;
use App\Modules\Identity\Models\OtpChallenge;
use App\Modules\Payments\Console\ExpireHoldsCommand;
use App\Modules\Payments\Console\RequeueWebhooksCommand;
use App\Modules\Supplier\Console\ExpireQuotationsCommand;
use Illuminate\Support\Facades\Schedule;

// Expired sign-in tokens, a day after they expire.
Schedule::command('sanctum:prune-expired', ['--hours' => 24])->daily();

// Old sign-in code records (they hold IP addresses); see groupbuy.auth.otp.prune_after_days.
Schedule::command('model:prune', ['--model' => [OtpChallenge::class]])->daily();

// Supplier quotes past their validity date, just after midnight in Riyadh.
Schedule::command(ExpireQuotationsCommand::class)->dailyAt('00:05')->timezone('Asia/Riyadh');

// Campaigns going live, closing at their deadline, and unpaid reservations
// expiring. One server at a time, never overlapping itself.
Schedule::command(CampaignTickCommand::class)->everyMinute()->withoutOverlapping()->onOneServer();

// Closing campaigns: a crashed or waiting closing job is woken, and one past
// its supplier decision deadline fails (spec, closing job risks).
Schedule::command(SweepClosingCommand::class)->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// Card holds past their 14-day expiry become expired; Moyasar never reports it.
Schedule::command(ExpireHoldsCommand::class)->hourly()->withoutOverlapping()->onOneServer();

// Verified Moyasar webhooks that were stored but never processed are queued again.
Schedule::command(RequeueWebhooksCommand::class)->everyFiveMinutes()->withoutOverlapping()->onOneServer();
