<?php

namespace Tests\Feature\Orders;

use App\Models\StatusTransition;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Orders\Documents\InvoicePdf;
use App\Modules\Orders\Jobs\RenderInvoicePdf;
use App\Modules\Orders\Jobs\SubmitInvoiceToZatca;
use App\Modules\Orders\Models\CustomerOrder;
use App\Modules\Orders\Models\Invoice;
use App\Modules\Orders\Models\ZatcaDevice;
use App\Modules\Orders\Models\ZatcaInvoiceStamp;
use App\Modules\Orders\Services\InvoiceDocuments;
use App\Modules\Orders\Services\Invoices;
use App\Modules\Orders\Services\ZatcaReporting;
use App\Modules\Orders\Support\ZatcaQr;
use App\Modules\Orders\Zatca\Certificate;
use App\Modules\Orders\Zatca\UblInvoice;
use App\Modules\Orders\Zatca\ZatcaCsr;
use App\Modules\Orders\Zatca\ZatcaSigner;
use App\Support\Actor;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\ClosesCampaigns;
use Tests\Fakes\ZatcaTestCa;
use Tests\TestCase;

/**
 * ZATCA e-invoicing phase 2: each invoice and credit note issued while it
 * is on is signed by the active EGS unit, chained to the one before, and
 * reported to Fatoora; its PDF carries the signed QR code.
 */
class ZatcaReportingTest extends TestCase
{
    use ClosesCampaigns, RefreshDatabase;

    private const REPORTING = 'https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation/invoices/reporting/single';

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'));
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
            'groupbuy.zatca.enabled' => true,
            'groupbuy.zatca.environment' => 'simulation',
        ]);
        $this->campaign = $this->closableCampaign();
    }

    #[Test]
    public function invoices_are_signed_chained_and_reported(): void
    {
        $device = $this->activeDevice();
        Http::fake([self::REPORTING => Http::response(['validationResults' => ['status' => 'PASS', 'warningMessages' => [], 'errorMessages' => []], 'reportingStatus' => 'REPORTED'])]);

        $this->paidJoin($this->campaign, 2);
        $this->paidJoin($this->campaign, 3);
        $this->captureAll($this->campaign);

        $invoices = Invoice::query()->orderBy('id')->with('zatcaStamp')->get();
        $this->assertCount(2, $invoices);
        [$first, $second] = $invoices;
        $this->assertSame(['accepted', 'accepted'], $invoices->map(fn (Invoice $i) => $i->zatca_status->value)->all());

        // Chained: counters 1 and 2, each with the hash of the one before.
        $this->assertSame([1, ZatcaSigner::FIRST_PREVIOUS_HASH], [$first->zatcaStamp->icv, $first->zatcaStamp->previous_hash]);
        $this->assertSame([2, $first->zatcaStamp->invoice_hash], [$second->zatcaStamp->icv, $second->zatcaStamp->previous_hash]);
        $this->assertSame([2, $second->zatcaStamp->invoice_hash], [$device->refresh()->icv, $device->last_hash]);
        $this->assertSame($first->zatcaStamp->invoice_hash, UblInvoice::hash((string) $first->zatcaStamp->signed_xml));
        $this->assertTrue(ZatcaSigner::verify((string) $first->zatcaStamp->invoice_hash, (string) ZatcaQr::decode((string) $first->zatcaStamp->qr)[7], $device->productionCertificate()));
        $this->assertSame([200, 1], [$first->zatcaStamp->response_status, $first->zatcaStamp->attempts]);
        $this->assertNotNull($first->zatcaStamp->reported_at);

        // Reported as ZATCA wants it: one call each, the signed XML in base64.
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->url() === self::REPORTING
            && $request->hasHeader('Accept-Version', 'V2')
            && $request->hasHeader('Clearance-Status', '0')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode($device->productionCertificate()->token().':prod-secret'))
            && $request['uuid'] === $first->zatca_uuid
            && $request['invoiceHash'] === $first->zatcaStamp->invoice_hash
            && base64_decode($request['invoice']) === $first->zatcaStamp->signed_xml);

        // The XML is kept beside the PDF, and the PDF shows the signed QR code.
        $disk = Storage::disk((string) config('groupbuy.media.documents_disk'));
        $this->assertSame("invoices/2026/{$first->invoice_number}.xml", $first->xml_s3_key);
        $this->assertSame($first->zatcaStamp->signed_xml, $disk->get((string) $first->xml_s3_key));
        $this->assertNotNull($first->pdf_s3_key);
        $this->assertSame($first->zatcaStamp->qr, app(InvoicePdf::class)->data($first)['qr']);

        // pending, then submitted (signed), then accepted, in the invoice's history.
        $this->assertSame([['pending', 'submitted', 'zatca_signed'], ['submitted', 'accepted', 'zatca_reported']], StatusTransition::query()
            ->where('subject_type', 'invoice')->where('subject_id', $first->id)->where('metadata->field', 'zatca_status')->orderBy('id')->get()
            ->map(fn (StatusTransition $t) => [$t->from_status, $t->to_status, $t->reason])->all());

        // A credit note is the next link in the chain.
        $order = CustomerOrder::query()->whereKey($first->customer_order_id)->sole();
        $note = DB::transaction(fn () => app(Invoices::class)->issueCreditNote($order, 5000, Actor::system()));
        $stamp = $note->zatcaStamp()->sole();
        $this->assertSame([3, $second->zatcaStamp->invoice_hash, 'accepted'], [$stamp->icv, $stamp->previous_hash, $note->refresh()->zatca_status->value]);
        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0200000">381</cbc:InvoiceTypeCode>', (string) $stamp->signed_xml);

        // A signed e-invoice never changes.
        $this->expectException(QueryException::class);
        $stamp->forceFill(['signed_xml' => '<Invoice/>'])->save();
    }

    #[Test]
    public function zatcas_answer_decides_and_an_unreachable_fatoora_is_tried_again(): void
    {
        $this->activeDevice();
        Bus::fake([SubmitInvoiceToZatca::class]);
        foreach (range(1, 4) as $i) {
            $this->paidJoin($this->campaign, 1);
        }
        $this->captureAll($this->campaign);
        $stamps = ZatcaInvoiceStamp::query()->orderBy('id')->get();
        $this->assertCount(4, $stamps);
        Bus::assertDispatchedTimes(SubmitInvoiceToZatca::class, 4);
        $zatca = app(ZatcaReporting::class);

        Http::fakeSequence(self::REPORTING)
            ->push(['validationResults' => ['status' => 'WARNING', 'warningMessages' => [['code' => 'BR-KSA-98', 'message' => 'Late']]], 'reportingStatus' => 'REPORTED'], 202)
            ->push(['validationResults' => ['status' => 'ERROR', 'errorMessages' => [['code' => 'BR-KSA-37', 'message' => 'Building number must be 4 digits']]], 'reportingStatus' => 'NOT_REPORTED'], 400)
            ->push(['message' => 'Duplicate'], 409)
            ->push('Service unavailable', 503)
            ->push(['validationResults' => ['status' => 'PASS'], 'reportingStatus' => 'REPORTED'], 200)
            ->push(['validationResults' => ['status' => 'PASS'], 'reportingStatus' => 'REPORTED'], 200);

        foreach ($stamps as $stamp) {
            $zatca->sign($stamp->id);
        }

        $zatca->report($stamps[0]->id);
        $zatca->report($stamps[1]->id);
        $zatca->report($stamps[2]->id);

        try {
            $zatca->report($stamps[3]->id);
            $this->fail('An unreachable Fatoora is tried again.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('503', $e->getMessage());
        }

        $status = fn (int $i) => Invoice::query()->whereKey($stamps[$i]->invoice_id)->value('zatca_status')->value;
        $this->assertSame(['accepted', 'rejected', 'accepted', 'submitted'], [$status(0), $status(1), $status(2), $status(3)]);
        $this->assertSame([202, 'BR-KSA-98'], [$stamps[0]->refresh()->response_status, $stamps[0]->response['validationResults']['warningMessages'][0]['code']]);
        $this->assertSame('BR-KSA-37: Building number must be 4 digits', $stamps[1]->refresh()->last_error);
        $this->assertNull($stamps[1]->reported_at);
        $this->assertSame([1, null], [$stamps[3]->refresh()->attempts, $stamps[3]->reported_at]);
        $this->assertStringContainsString('503', (string) $stamps[3]->last_error);

        // The sweep: nothing while the first job may still hold its unique
        // lock; then only the open one, never the rejected one.
        Bus::fake([SubmitInvoiceToZatca::class]);
        $this->artisan('zatca:report')->expectsOutput('E-invoices queued: 0, overdue: 0, rejected: 1.')->assertSuccessful();
        $this->travel(2)->hours();
        $this->artisan('zatca:report')->expectsOutput('E-invoices queued: 1, overdue: 0, rejected: 1.')->assertSuccessful();
        Bus::assertDispatched(SubmitInvoiceToZatca::class, fn (SubmitInvoiceToZatca $job) => $job->stampId === $stamps[3]->id);

        // Close to ZATCA's 24 hours and still unreported: an alert, and the run fails.
        $this->travel(19)->hours();
        $this->artisan('zatca:report')->expectsOutput('E-invoices queued: 1, overdue: 1, rejected: 1.')->assertFailed();

        (new SubmitInvoiceToZatca($stamps[3]->id))->handle($zatca);
        $this->assertSame('accepted', $status(3));
        $this->assertSame(2, $stamps[3]->refresh()->attempts);

        // A rejected one is sent again only when asked.
        $number = Invoice::query()->whereKey($stamps[1]->invoice_id)->value('invoice_number');
        $this->artisan('zatca:report', ['--resend' => $number])->expectsOutput("{$number}: accepted.")->assertSuccessful();
        $this->artisan('zatca:report', ['--resend' => $number])->assertFailed();
    }

    #[Test]
    public function an_invoice_waits_for_an_onboarded_unit_and_its_pdf_for_the_signature(): void
    {
        Bus::fake([SubmitInvoiceToZatca::class]);
        $this->assertStringContainsString('zatca:onboard', (string) ZatcaReporting::problem());
        config(['groupbuy.seller.building_number' => '12']);
        $this->assertStringContainsString('GROUPBUY_SELLER_BUILDING_NUMBER', (string) ZatcaReporting::problem());
        config(['groupbuy.seller.building_number' => '1234']);

        $this->paidJoin($this->campaign, 3);
        $this->captureAll($this->campaign);
        $invoice = Invoice::query()->sole();
        $stamp = $invoice->zatcaStamp()->sole();
        $this->assertSame('pending', $invoice->zatca_status->value);

        try {
            app(ZatcaReporting::class)->sign($stamp->id);
            $this->fail('Nothing signs without an active unit.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('zatca:onboard', $e->getMessage());
        }

        // No PDF yet, and the PDF sweep leaves it to the signature.
        $this->assertNull($invoice->pdf_s3_key);
        $this->travel(30)->minutes();
        Bus::fake([RenderInvoicePdf::class]);
        $this->assertSame(0, app(InvoiceDocuments::class)->requeueMissing());
        $this->expectExceptionMessage('waits for its e-invoice signature');
        app(InvoiceDocuments::class)->storePdf($invoice);
    }

    #[Test]
    public function with_phase_two_off_invoices_are_issued_as_before(): void
    {
        config(['groupbuy.zatca.enabled' => false]);
        $this->assertNull(ZatcaReporting::problem());

        $this->paidJoin($this->campaign, 3);
        $this->captureAll($this->campaign);

        $invoice = Invoice::query()->sole();
        $this->assertSame(0, ZatcaInvoiceStamp::count());
        $this->assertSame('pending', $invoice->zatca_status->value);
        $this->assertNotNull($invoice->pdf_s3_key);
        $this->artisan('zatca:report')->expectsOutput('E-invoices queued: 0, overdue: 0, rejected: 0.')->assertSuccessful();
    }

    private function activeDevice(): ZatcaDevice
    {
        $csr = ZatcaCsr::make('simulation', (array) config('groupbuy.zatca.egs'), Invoices::seller());
        $certificate = Certificate::fromBase64(ZatcaTestCa::instance()->issue($csr['csr']));

        return ZatcaDevice::create([
            'environment' => 'simulation',
            'status' => ZatcaDevice::ACTIVE,
            'egs_serial' => $csr['serial'],
            'common_name' => $csr['common_name'],
            'private_key' => $csr['private_key'],
            'csr' => $csr['csr'],
            'certificate' => $certificate->base64,
            'secret' => 'prod-secret',
            'certificate_expires_at' => $certificate->validTo,
            'activated_at' => now(),
        ]);
    }
}
