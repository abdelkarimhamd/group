<?php

use App\Modules\Identity\Models\OtpChallenge;
use App\Modules\Supplier\Console\ExpireQuotationsCommand;
use Illuminate\Support\Facades\Schedule;

// Expired sign-in tokens, a day after they expire.
Schedule::command('sanctum:prune-expired', ['--hours' => 24])->daily();

// Old sign-in code records (they hold IP addresses); see groupbuy.auth.otp.prune_after_days.
Schedule::command('model:prune', ['--model' => [OtpChallenge::class]])->daily();

// Supplier quotes past their validity date, just after midnight in Riyadh.
Schedule::command(ExpireQuotationsCommand::class)->dailyAt('00:05')->timezone('Asia/Riyadh');
