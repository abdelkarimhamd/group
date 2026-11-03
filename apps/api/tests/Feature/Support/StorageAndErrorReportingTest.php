<?php

namespace Tests\Feature\Support;

use App\Exceptions\ApiException;
use GuzzleHttp\Psr7\ServerRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Laravel\Http\LaravelRequestFetcher;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Tests\TestCase;

/**
 * Production plumbing (spec, non-functional): files on S3-compatible
 * storage, and unexpected errors reported to Sentry without customer data.
 */
class StorageAndErrorReportingTest extends TestCase
{
    #[Test]
    public function product_photos_get_public_links_and_quote_files_get_signed_ones(): void
    {
        config([
            'filesystems.disks.s3-media.key' => 'AKIDEXAMPLE',
            'filesystems.disks.s3-media.secret' => 'secret',
            'filesystems.disks.s3-media.region' => 'me-central-1',
            'filesystems.disks.s3-media.bucket' => 'groupbuy-media',
            'filesystems.disks.s3-media.url' => 'https://cdn.example.sa',
            'filesystems.disks.s3-documents.key' => 'AKIDEXAMPLE',
            'filesystems.disks.s3-documents.secret' => 'secret',
            'filesystems.disks.s3-documents.region' => 'me-central-1',
            'filesystems.disks.s3-documents.bucket' => 'groupbuy-documents',
        ]);

        $this->assertSame('https://cdn.example.sa/products/p-1/photo.jpg', Storage::disk('s3-media')->url('products/p-1/photo.jpg'));

        $signed = Storage::disk('s3-documents')->temporaryUrl('quotations/q-1/quote.pdf', now()->addMinutes(30));
        $this->assertStringStartsWith('https://groupbuy-documents.s3.me-central-1.amazonaws.com/quotations/q-1/quote.pdf?', $signed);
        $this->assertStringContainsString('X-Amz-Expires=1800', $signed);
        $this->assertStringContainsString('X-Amz-Signature=', $signed);
    }

    #[Test]
    public function unexpected_errors_reach_sentry_without_the_request_body_and_expected_ones_do_not(): void
    {
        $transport = new class implements TransportInterface
        {
            /** @var list<Event> */
            public array $events = [];

            public function send(Event $event): Result
            {
                $this->events[] = $event;

                return new Result(ResultStatus::success(), $event);
            }

            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::success());
            }
        };
        // The client exactly as the app builds it from config/sentry.php, with
        // a transport that keeps the events instead of sending them.
        config(['sentry.dsn' => 'https://public@sentry.example.com/1']);
        $this->app->extend(ClientBuilder::class, fn (ClientBuilder $builder) => $builder->setTransport($transport));
        $this->app->forgetInstance(HubInterface::class);
        SentrySdk::setCurrentHub(app(HubInterface::class));

        Route::post('/api/_test/boom', fn () => throw new RuntimeException('boom'));
        Route::post('/api/_test/refused', fn () => throw ApiException::unprocessable('validation_failed'));

        $this->postJson('/api/_test/refused', ['phone' => '+966512345678'])->assertStatus(422);
        $this->assertSame([], $transport->events);

        // Under PHP-FPM Sentry reads the body from php://input; the test
        // server has none, so hand it the request as production would see it.
        $body = json_encode(['phone' => '+966512345678', 'code' => '123456']);
        $this->app->instance(LaravelRequestFetcher::CONTAINER_PSR7_INSTANCE_KEY, new ServerRequest('POST', 'https://api.example.sa/api/_test/boom', ['Content-Type' => 'application/json', 'Content-Length' => (string) strlen((string) $body)], $body));
        $this->postJson('/api/_test/boom', json_decode((string) $body, true))->assertStatus(500);

        $this->assertCount(1, $transport->events);
        $event = $transport->events[0];
        $this->assertSame('boom', $event->getExceptions()[0]->getValue());
        $this->assertArrayNotHasKey('data', $event->getRequest());
        $this->assertStringNotContainsString('966512345678', json_encode($event->getRequest()) ?: '');
    }
}
