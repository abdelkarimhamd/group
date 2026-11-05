<?php

namespace Tests\Feature\Orders;

use App\Models\AuditLog;
use App\Modules\Orders\Models\ZatcaDevice;
use App\Modules\Orders\Support\ZatcaQr;
use App\Modules\Orders\Zatca\Certificate;
use App\Modules\Orders\Zatca\UblInvoice;
use App\Modules\Orders\Zatca\ZatcaSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fakes\ZatcaTestCa;
use Tests\TestCase;

/**
 * Registering this system as an EGS unit with ZATCA: CSR, compliance
 * CSID, compliance checks, production CSID.
 */
class ZatcaOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal';

    /** @var list<array{type: string, icv: string, pih: string, hash: string, lines: list<array{0: string, 1: string}>}> */
    private array $checked = [];

    private int $csidCalls = 0;

    /** @var array<int, int> the compliance answer by check number (default 200) */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'groupbuy.seller.legal_name_ar' => 'شركة الشراء الجماعي',
            'groupbuy.seller.legal_name_en' => 'Group Buying Co.',
            'groupbuy.seller.vat_number' => '310123456700003',
            'groupbuy.seller.cr_number' => '1010010000',
            'groupbuy.seller.street' => 'King Fahd Road',
            'groupbuy.seller.building_number' => '1234',
            'groupbuy.seller.district' => 'Al Olaya',
            'groupbuy.seller.city' => 'Riyadh',
            'groupbuy.seller.postal_code' => '12214',
            'groupbuy.zatca.environment' => 'developer-portal',
        ]);
        $this->fakeFatoora();
    }

    #[Test]
    public function a_unit_passes_the_compliance_checks_and_becomes_active(): void
    {
        $this->artisan('zatca:onboard')->expectsOutputToContain('Give the 6-digit OTP')->assertFailed();

        $this->artisan('zatca:onboard', ['--otp' => '123345'])
            ->expectsOutputToContain('Compliance CSID received.')
            ->expectsOutputToContain('Compliance check 388: 200')
            ->expectsOutputToContain('Compliance check 383: 200')
            ->expectsOutputToContain('EGS unit active')
            ->expectsOutputToContain('Set ZATCA_ENABLED=true')
            ->assertSuccessful();

        $device = ZatcaDevice::query()->sole();
        $this->assertSame(['active', 'developer-portal', '1234567890123'], [$device->status, $device->environment, $device->compliance_request_id]);
        $this->assertSame([0, ZatcaSigner::FIRST_PREVIOUS_HASH], [$device->icv, $device->last_hash]);
        $this->assertSame('2', $device->productionCertificate()->serial);
        $this->assertSame('prod-secret', $device->secret);
        $this->assertNotNull($device->certificate_expires_at);
        $this->assertStringStartsWith('1-GroupBuy|2-API|3-', $device->egs_serial);
        // The key is kept encrypted.
        $this->assertStringNotContainsString('PRIVATE KEY', (string) $device->getRawOriginal('private_key'));

        // A simplified invoice, credit note and debit note, chained.
        $this->assertSame(['388', '381', '383'], array_column($this->checked, 'type'));
        $this->assertSame(['1', '2', '3'], array_column($this->checked, 'icv'));
        $this->assertSame([ZatcaSigner::FIRST_PREVIOUS_HASH, $this->checked[0]['hash'], $this->checked[1]['hash']], array_column($this->checked, 'pih'));
        $this->assertSame(1, AuditLog::query()->where('action', 'zatca.device.activate')->count());

        // Onboarding again makes a new unit and retires this one.
        $this->checked = [];
        $this->artisan('zatca:onboard', ['--otp' => '123345'])->assertSuccessful();
        $this->assertSame(['retired', 'active'], ZatcaDevice::query()->orderBy('id')->pluck('status')->all());
    }

    #[Test]
    public function a_failed_check_is_fixed_and_run_again_without_a_new_otp(): void
    {
        $this->answers = [2 => 400];

        $this->artisan('zatca:onboard', ['--otp' => '123345'])
            ->expectsOutputToContain('Compliance check 381: 400 (errors)')
            ->expectsOutputToContain('BR-KSA-17: The reason is missing')
            ->expectsOutputToContain('no new OTP needed')
            ->assertFailed();
        $this->assertSame('onboarding', ZatcaDevice::query()->sole()->status);

        $this->answers = [];
        $this->checked = [];
        $this->artisan('zatca:onboard')->expectsOutputToContain('EGS unit active')->assertSuccessful();
        $this->assertSame(1, $this->csidCalls);
        $this->assertSame('active', ZatcaDevice::query()->sole()->status);
    }

    #[Test]
    public function the_seller_must_be_complete_before_onboarding(): void
    {
        config(['groupbuy.seller.postal_code' => '']);

        $this->artisan('zatca:onboard', ['--otp' => '123345'])->expectsOutputToContain('GROUPBUY_SELLER_POSTAL_CODE')->assertFailed();
        $this->assertSame(0, ZatcaDevice::count());
        Http::assertNothingSent();
    }

    #[Test]
    public function a_refused_otp_is_reported(): void
    {
        $this->artisan('zatca:onboard', ['--otp' => '000000'])
            ->expectsOutputToContain('Fatoora refused the compliance CSID (400)')
            ->expectsOutputToContain('zatca:onboard --fresh')
            ->assertFailed();
        $this->assertSame('onboarding', ZatcaDevice::query()->sole()->status);
    }

    #[Test]
    public function onboarding_starts_over_with_a_new_csr_after_the_seller_is_corrected(): void
    {
        // ZATCA issued the compliance CSID, but for a mistyped VAT number.
        $this->answers = [1 => 400, 2 => 400, 3 => 400];
        $this->artisan('zatca:onboard', ['--otp' => '123345'])->assertFailed();
        $first = ZatcaDevice::query()->sole();

        // Resuming would send the same CSR again; --fresh makes a new one.
        config(['groupbuy.seller.vat_number' => '310123456700013']);
        $this->answers = [];
        $this->checked = [];
        $this->artisan('zatca:onboard', ['--otp' => '123345', '--fresh' => true])
            ->expectsOutputToContain('Dropped 1 unit(s) still being onboarded.')
            ->expectsOutputToContain('EGS unit active')
            ->assertSuccessful();

        $this->assertSame(['retired', 'active'], ZatcaDevice::query()->orderBy('id')->pluck('status')->all());
        $this->assertNotSame($first->csr, ZatcaDevice::query()->where('status', 'active')->sole()->csr);
        $this->assertSame(2, $this->csidCalls);
        $this->assertSame(1, AuditLog::query()->where('action', 'zatca.device.abandon')->count());
    }

    #[Test]
    public function the_samples_include_a_total_whose_vat_is_a_halala_off(): void
    {
        $this->artisan('zatca:onboard', ['--otp' => '123345'])->assertSuccessful();

        // 115.04 splits into 100.03 and 15.01, where 100.03 x 15% is 15.00.
        $this->assertSame([['100.00', '15.00'], ['100.03', '15.01']], $this->checked[0]['lines']);
    }

    private function fakeFatoora(): void
    {
        $csr = null;
        $issued = null;

        Http::fake([
            self::BASE.'/compliance' => function (Request $request) use (&$csr, &$issued) {
                if (($request->header('OTP')[0] ?? null) !== '123345') {
                    return Http::response(['errors' => ['Invalid OTP']], 400);
                }

                $this->assertSame('V2', $request->header('Accept-Version')[0] ?? null);
                $csr = (string) base64_decode((string) $request['csr']);
                $this->assertStringContainsString('BEGIN CERTIFICATE REQUEST', $csr);
                $this->csidCalls++;

                $issued = ZatcaTestCa::instance()->issue($csr, 1);

                return Http::response(['requestID' => 1234567890123, 'dispositionMessage' => 'ISSUED', 'binarySecurityToken' => base64_encode($issued), 'secret' => 'compliance-secret']);
            },
            self::BASE.'/compliance/invoices' => function (Request $request) use (&$issued) {
                $certificate = Certificate::fromBase64((string) $issued);
                $this->assertSame('Basic '.base64_encode($certificate->token().':compliance-secret'), $request->header('Authorization')[0] ?? null);
                $xml = (string) base64_decode((string) $request['invoice']);
                $x = UblInvoice::xpath(UblInvoice::load($xml));
                $this->assertSame($request['invoiceHash'], UblInvoice::hash($xml));
                $this->assertSame($request['uuid'], $x->evaluate('string(/i:Invoice/cbc:UUID)'));
                $this->assertTrue(ZatcaSigner::verify((string) $request['invoiceHash'], ZatcaQr::decode($x->evaluate("string(//cac:AdditionalDocumentReference[cbc:ID='QR']//cbc:EmbeddedDocumentBinaryObject)"))[7], $certificate));
                $this->checked[] = [
                    'type' => $x->evaluate('string(/i:Invoice/cbc:InvoiceTypeCode)'),
                    'icv' => $x->evaluate("string(//cac:AdditionalDocumentReference[cbc:ID='ICV']/cbc:UUID)"),
                    'pih' => $x->evaluate("string(//cac:AdditionalDocumentReference[cbc:ID='PIH']//cbc:EmbeddedDocumentBinaryObject)"),
                    'hash' => (string) $request['invoiceHash'],
                    'lines' => array_map(fn ($line) => [
                        $x->evaluate('string(cbc:LineExtensionAmount)', $line),
                        $x->evaluate('string(cac:TaxTotal/cbc:TaxAmount)', $line),
                    ], iterator_to_array($x->query('//cac:InvoiceLine'))),
                ];
                $status = $this->answers[count($this->checked)] ?? 200;

                return $status === 200
                    ? Http::response(['validationResults' => ['status' => 'PASS', 'warningMessages' => [], 'errorMessages' => []], 'reportingStatus' => 'REPORTED'])
                    : Http::response(['validationResults' => ['status' => 'ERROR', 'errorMessages' => [['code' => 'BR-KSA-17', 'message' => 'The reason is missing']]], 'reportingStatus' => 'NOT_REPORTED'], $status);
            },
            self::BASE.'/production/csids' => function (Request $request) use (&$csr) {
                $this->assertSame('1234567890123', $request['compliance_request_id']);

                return Http::response(['requestID' => 1234567890124, 'binarySecurityToken' => ZatcaTestCa::instance()->token((string) $csr, 2), 'secret' => 'prod-secret']);
            },
        ]);
    }
}
