<?php

namespace App\Modules\Finance\Console;

use App\Modules\Finance\Services\Settlements;
use App\Modules\Payments\Gateway\GatewayException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Daily: import Moyasar's new settlements to the bank and post them to the
 * ledger (fees, their VAT, and the payout).
 */
final class ImportSettlementsCommand extends Command
{
    protected $signature = 'payments:settlements';

    protected $description = "Import Moyasar's settlements and post them to the ledger";

    public function handle(Settlements $settlements): int
    {
        try {
            ['imported' => $imported, 'seen' => $seen] = $settlements->import();
        } catch (GatewayException $e) {
            Log::critical('payments.settlements.failed', ['error' => $e->getMessage()]);
            $this->error('Moyasar could not be read: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Settlements read: {$seen}, imported: {$imported}.");

        return self::SUCCESS;
    }
}
