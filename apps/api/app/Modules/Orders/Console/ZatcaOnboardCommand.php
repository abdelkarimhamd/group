<?php

namespace App\Modules\Orders\Console;

use App\Modules\Orders\Services\Invoices;
use App\Modules\Orders\Services\ZatcaOnboarding;
use App\Modules\Orders\Services\ZatcaReporting;
use App\Modules\Orders\Zatca\FatooraRefused;
use App\Modules\Orders\Zatca\FatooraUnavailable;
use Illuminate\Console\Command;

/**
 * Registers this system as an EGS unit with ZATCA (e-invoicing phase 2),
 * in ZATCA_ENV: CSR, compliance CSID (with the OTP from the Fatoora
 * portal), ZATCA's compliance checks, production CSID. Run it again with
 * a new OTP to replace the unit (before its certificate expires, say).
 */
final class ZatcaOnboardCommand extends Command
{
    protected $signature = 'zatca:onboard {--otp= : One-time password from the Fatoora portal (any 6 digits on the developer portal)}';

    protected $description = 'Onboard this system with ZATCA as an e-invoicing (EGS) unit';

    public function handle(ZatcaOnboarding $onboarding): int
    {
        $environment = (string) config('groupbuy.zatca.environment');

        if (($problem = Invoices::sellerProblem() ?? ZatcaReporting::sellerProblem()) !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $device = $onboarding->device($environment);
        $this->line("EGS unit {$device->egs_serial} ({$environment}).");

        try {
            if ($onboarding->needsOtp($device)) {
                $otp = (string) $this->option('otp');

                if (preg_match('/^\d{6}$/', $otp) !== 1) {
                    $this->error('Give the 6-digit OTP from the Fatoora portal: --otp=123456.');

                    return self::FAILURE;
                }

                $onboarding->requestComplianceCsid($device, $otp);
                $this->info('Compliance CSID received.');
            }

            $results = $onboarding->runComplianceChecks($device);

            foreach ($results as $result) {
                $this->line(sprintf('Compliance check %s: %d%s', $result['type'], $result['status'], $result['errors'] === [] ? '' : ' (errors)'));

                foreach ([...$result['errors'], ...$result['warnings']] as $message) {
                    $this->line('  '.$message);
                }
            }

            if (! ZatcaOnboarding::passed($results)) {
                $this->error('The compliance checks did not pass; fix the errors above and run zatca:onboard again (no new OTP needed).');

                return self::FAILURE;
            }

            $onboarding->activate($device);
        } catch (FatooraRefused|FatooraUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $device->refresh();
        $this->info("EGS unit active; its certificate expires {$device->certificate_expires_at?->toDateString()}.");

        if (! ZatcaReporting::enabled()) {
            $this->line('Set ZATCA_ENABLED=true to sign and report new invoices.');
        }

        return self::SUCCESS;
    }
}
