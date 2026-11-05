<?php

namespace Tests\Feature\Orders;

use App\Models\AuditLog;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Orders\Documents\InvoicePdf;
use App\Modules\Orders\Enums\PurchaseOrderStatus;
use App\Modules\Orders\Jobs\RenderInvoicePdf;
use App\Modules\Orders\Models\CustomerOrder;
use App\Modules\Orders\Models\Invoice;
use App\Modules\Orders\Models\SupplierPurchaseOrder;
use App\Modules\Orders\Services\InvoiceDocuments;
use App\Modules\Orders\Services\Invoices;
use App\Modules\Orders\Services\PurchaseOrders;
use App\Support\Actor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ClosesCampaigns;
use Tests\TestCase;

/**
 * Files kept with orders: the Arabic and English PDF of every invoice and
 * credit note (spec, E11), the papers of a supplier purchase order (E12)
 * and photos of goods counted at the warehouse (E13). All private, read
 * through short-lived signed links.
 */
class DocumentsTest extends TestCase
{
    use ClosesCampaigns, RefreshDatabase;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'));
        config([
            'groupbuy.seller.legal_name_ar' => 'شركة الشراء الجماعي',
            'groupbuy.seller.legal_name_en' => 'Group Buying Co.',
            'groupbuy.seller.vat_number' => '310123456700003',
        ]);
        $this->campaign = $this->closableCampaign();
    }

    #[Test]
    public function every_invoice_and_credit_note_gets_its_pdf_once_issued(): void
    {
        [$join, $token] = $this->paidJoin($this->campaign, 3);
        $this->captureAll($this->campaign);
        $order = CustomerOrder::query()->where('participation_id', $join->id)->sole();
        $invoice = $order->taxInvoice()->sole();

        // Rendered after the capture committed (the test queue is sync).
        $path = "invoices/2026/{$invoice->invoice_number}.pdf";
        $this->assertSame($path, $invoice->pdf_s3_key);
        $disk = Storage::disk((string) config('groupbuy.media.documents_disk'));
        $this->assertStringStartsWith('%PDF-', (string) $disk->get($path));

        $this->withToken($token)->getJson("/api/v1/me/orders/{$order->uuid}")
            ->assertOk()
            ->assertJsonPath('data.invoices.0.pdf_url', fn (string $url) => str_contains($url, $path) && str_contains($url, 'expiration='));

        // Running it again changes nothing: same file, same place.
        (new RenderInvoicePdf($invoice->id))->handle(app(InvoiceDocuments::class));
        $this->assertSame([$path], $disk->allFiles('invoices/2026'));

        // A credit note gets its own.
        $note = DB::transaction(fn () => app(Invoices::class)->issueCreditNote($order->refresh(), 5000, Actor::system()));
        $this->assertSame("invoices/2026/{$note->invoice_number}.pdf", $note->refresh()->pdf_s3_key);
        $this->assertTrue($disk->exists($note->pdf_s3_key));
    }

    #[Test]
    public function a_pdf_whose_job_was_lost_is_queued_again_by_the_sweep(): void
    {
        Bus::fake([RenderInvoicePdf::class]);
        [$join, $token] = $this->paidJoin($this->campaign, 3);
        $this->captureAll($this->campaign);
        $order = CustomerOrder::query()->where('participation_id', $join->id)->sole();
        $invoice = $order->taxInvoice()->sole();
        Bus::assertDispatched(RenderInvoicePdf::class, fn (RenderInvoicePdf $job) => $job->invoiceId === $invoice->id);

        // Until it is stored the customer sees no link.
        $this->assertNull($invoice->pdf_s3_key);
        $this->withToken($token)->getJson("/api/v1/me/orders/{$order->uuid}")->assertOk()->assertJsonPath('data.invoices.0.pdf_url', null);

        // Not before the requeue delay; then not while the first job may
        // still be running (its unique lock); then again.
        Bus::fake([RenderInvoicePdf::class]);
        $this->travel(5)->minutes();
        $this->artisan('invoices:render-pdfs')->expectsOutput('Queued 0 invoice PDFs.')->assertSuccessful();
        $this->travel(6)->minutes();
        $this->artisan('invoices:render-pdfs')->assertSuccessful();
        Bus::assertNotDispatched(RenderInvoicePdf::class);
        $this->travel(20)->minutes();
        $this->artisan('invoices:render-pdfs')->expectsOutput('Queued 1 invoice PDFs.')->assertSuccessful();
        Bus::assertDispatched(RenderInvoicePdf::class, fn (RenderInvoicePdf $job) => $job->invoiceId === $invoice->id);
    }

    #[Test]
    public function the_pdf_prints_what_was_issued_in_both_languages(): void
    {
        $this->paidJoin($this->campaign, 3);
        $this->captureAll($this->campaign);
        $invoice = Invoice::query()->sole();

        // Settings changed after issue never reach the printed invoice.
        config(['groupbuy.seller.legal_name_en' => 'Someone else']);
        $data = app(InvoicePdf::class)->data($invoice);

        $this->assertSame(['ar' => 'فاتورة ضريبية مبسطة', 'en' => 'Simplified tax invoice'], $data['title']);
        $this->assertSame(['شركة الشراء الجماعي', 'Group Buying Co.', '310123456700003'], [$data['seller']['name_ar'], $data['seller']['name_en'], $data['seller']['vat_number']]);
        // Riyadh time: the capture ran at the deadline, 2026-11-09 09:00 UTC.
        $this->assertSame('2026-11-09 12:00', $data['issuedAt']);
        $this->assertSame(['360.00', '46.96', '313.04', '15%'], [$data['total'], $data['vat'], $data['net'], $data['vatRate']]);
        $this->assertSame($invoice->qr_payload, $data['qr']);

        $this->assertSame(['1,234,567.89', '0.05', '-1.50', '12.5%', '15%'], [
            InvoicePdf::amount(123456789), InvoicePdf::amount(5), InvoicePdf::amount(-150), InvoicePdf::rate(1250), InvoicePdf::rate(1500),
        ]);
    }

    #[Test]
    public function purchase_order_papers_are_kept_listed_and_removed(): void
    {
        $order = $this->purchaseOrder();
        $disk = Storage::disk((string) config('groupbuy.media.documents_disk'));

        $created = $this->upload($order, 'alibaba_invoice', UploadedFile::fake()->create('Alibaba invoice 7781.pdf', 120, 'application/pdf'))
            ->assertCreated()
            ->assertJsonPath('data.documents.0.kind', 'alibaba_invoice')
            ->assertJsonPath('data.documents.0.file_name', 'Alibaba invoice 7781.pdf')
            ->assertJsonPath('data.documents.0.size_bytes', 120 * 1024)
            ->assertJsonPath('data.documents.0.uploaded_by.type', 'admin');
        $document = $order->documents()->sole();
        $this->assertStringStartsWith("purchase-orders/{$order->uuid}/", $document->path);
        $this->assertTrue($disk->exists($document->path));
        $this->assertStringContainsString($document->path, (string) $created->json('data.documents.0.url'));

        $this->upload($order, 'receipt_of_some_kind', UploadedFile::fake()->create('a.pdf', 1, 'application/pdf'))->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->upload($order, 'other', UploadedFile::fake()->create('notes.txt', 1, 'text/plain'))->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload($order, 'other', UploadedFile::fake()->create('a.pdf', 1, 'application/pdf'), AdminRole::Finance)->assertForbidden();

        // At the limit the new file is refused and not left behind.
        config(['groupbuy.media.documents_per_purchase_order' => 1]);
        $this->upload($order, 'payment_receipt', UploadedFile::fake()->image('receipt.png'))->assertStatus(409)->assertJsonPath('code', 'document_limit_reached');
        $this->assertSame([$document->path], $disk->allFiles("purchase-orders/{$order->uuid}"));

        $this->admin('delete', "/api/v1/admin/purchase-orders/{$order->uuid}/documents/{$document->uuid}")
            ->assertOk()
            ->assertJsonPath('data.documents', []);
        $this->assertFalse($disk->exists($document->path));
        $this->admin('delete', "/api/v1/admin/purchase-orders/{$order->uuid}/documents/{$document->uuid}")->assertNotFound();
        $this->assertSame(
            ['purchase_order.document_attach', 'purchase_order.document_remove'],
            AuditLog::query()->where('action', 'like', 'purchase_order.document_%')->orderBy('id')->pluck('action')->all(),
        );
    }

    #[Test]
    public function warehouse_photos_are_added_as_evidence_and_never_removed(): void
    {
        $order = $this->purchaseOrder();
        [$admin] = $this->adminWithToken(AdminRole::Operations);
        $orders = app(PurchaseOrders::class);
        $orders->update($order->uuid, ['alibaba_order_ref' => 'ALI-1', 'freight_tracking_ref' => 'MAEU-1'], $admin);
        $orders->advance($order->uuid, PurchaseOrderStatus::Placed, null, null, $admin);
        $orders->advance($order->uuid, PurchaseOrderStatus::Shipped, null, null, $admin);
        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00', 'UTC'));
        $variant = $this->campaign->variants()->firstOrFail();
        $receipt = $this->admin('post', "/api/v1/admin/purchase-orders/{$order->uuid}/receipts", [
            'warehouse_name' => 'Riyadh pilot warehouse',
            'received_at' => '2026-11-20T08:00:00Z',
            'lines' => [['product_variant_id' => $variant->uuid, 'qty_received' => 3, 'qty_damaged' => 1]],
        ])->assertCreated()->json('data.id');
        $disk = Storage::disk((string) config('groupbuy.media.documents_disk'));

        $this->photo($receipt, UploadedFile::fake()->image('crushed-box.jpg'))
            ->assertCreated()
            ->assertJsonCount(1, 'data.photo_urls')
            ->assertJsonPath('data.photo_urls.0', fn (string $url) => str_contains($url, "receipts/{$receipt}/"));
        $this->photo($receipt, UploadedFile::fake()->create('notes.pdf', 1, 'application/pdf'))->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->photo($receipt, UploadedFile::fake()->image('x.jpg'), AdminRole::Finance)->assertForbidden();

        config(['groupbuy.media.photos_per_receipt' => 1]);
        $this->photo($receipt, UploadedFile::fake()->image('second.png'))->assertStatus(409)->assertJsonPath('code', 'photo_limit_reached');
        $this->assertCount(1, $disk->allFiles("receipts/{$receipt}"));

        $this->admin('get', "/api/v1/admin/purchase-orders/{$order->uuid}")
            ->assertOk()
            ->assertJsonCount(1, 'data.receipts.0.photo_urls');
        $this->assertSame(1, AuditLog::query()->where('action', 'inbound_receipt.photo_add')->count());
    }

    private function purchaseOrder(): SupplierPurchaseOrder
    {
        $this->paidJoin($this->campaign, 3);
        $this->captureAll($this->campaign);

        return SupplierPurchaseOrder::query()->sole();
    }

    private function upload(SupplierPurchaseOrder $order, string $kind, UploadedFile $file, AdminRole $role = AdminRole::Operations): TestResponse
    {
        return $this->admin('post', "/api/v1/admin/purchase-orders/{$order->uuid}/documents", ['kind' => $kind, 'file' => $file], $role);
    }

    private function photo(string $receipt, UploadedFile $file, AdminRole $role = AdminRole::Operations): TestResponse
    {
        return $this->admin('post', "/api/v1/admin/inbound-receipts/{$receipt}/photos", ['file' => $file], $role);
    }

    /**
     * @param  array<string, mixed>  $body  Files go as multipart.
     */
    private function admin(string $method, string $uri, array $body = [], AdminRole $role = AdminRole::Operations): TestResponse
    {
        [, $token] = $this->adminWithToken($role);
        $this->forgetAuthenticatedUsers();
        $request = $this->withToken($token)->withHeader('Accept', 'application/json');
        $response = $method === 'get' ? $request->get($uri) : $request->{$method}($uri, $body);
        $this->forgetAuthenticatedUsers();

        return $response;
    }
}
