<?php

namespace Tests\Feature\Support;

use App\Exceptions\ApiException;
use App\Modules\Identity\Models\User;
use App\Support\SentryScrubber;
use GuzzleHttp\Psr7\ServerRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Laravel\Http\LaravelRequestFetcher;
use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\Serializer\PayloadSerializer;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
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
    use RefreshDatabase;

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
    public function empty_cdn_and_endpoint_settings_fall_back_to_the_bucket(): void
    {
        $keys = ['AWS_MEDIA_URL', 'AWS_ENDPOINT', 'AWS_MEDIA_BUCKET', 'AWS_DOCUMENTS_BUCKET', 'AWS_DEFAULT_REGION', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY'];
        $saved = array_map(fn (string $key) => [$_ENV[$key] ?? null, $_SERVER[$key] ?? null], array_combine($keys, $keys));
        $set = fn (string $key, ?string $value) => [$_ENV[$key], $_SERVER[$key]] = [$value, $value];

        try {
            // As .env.example leaves them: blank.
            $set('AWS_MEDIA_URL', '');
            $set('AWS_ENDPOINT', '');
            $set('AWS_MEDIA_BUCKET', 'groupbuy-media');
            $set('AWS_DOCUMENTS_BUCKET', 'groupbuy-documents');
            $set('AWS_DEFAULT_REGION', 'me-central-1');
            $set('AWS_ACCESS_KEY_ID', 'AKIDEXAMPLE');
            $set('AWS_SECRET_ACCESS_KEY', 'secret');
            $disks = (require config_path('filesystems.php'))['disks'];
        } finally {
            foreach ($saved as $key => [$env, $server]) {
                unset($_ENV[$key], $_SERVER[$key]);
                if ($env !== null) {
                    $_ENV[$key] = $env;
                }
                if ($server !== null) {
                    $_SERVER[$key] = $server;
                }
            }
        }

        config(['filesystems.disks.s3-media' => $disks['s3-media'], 'filesystems.disks.s3-documents' => $disks['s3-documents']]);

        $this->assertSame('https://groupbuy-media.s3.me-central-1.amazonaws.com/products/p-1/photo.jpg', Storage::disk('s3-media')->url('products/p-1/photo.jpg'));
        $this->assertStringStartsWith('https://groupbuy-documents.s3.me-central-1.amazonaws.com/quotations/q-1/quote.pdf?', Storage::disk('s3-documents')->temporaryUrl('quotations/q-1/quote.pdf', now()->addMinutes(30)));
    }

    #[Test]
    public function unexpected_errors_reach_sentry_without_the_request_body_and_expected_ones_do_not(): void
    {
        $transport = $this->captureSentryEvents();

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

    #[Test]
    public function a_database_error_reaches_sentry_without_the_row_the_query_string_or_phone_numbers(): void
    {
        // Built at run time, so the number is not in the source lines Sentry
        // sends with each stack frame.
        $mobile = '5'.str_repeat('7', 8);
        $transport = $this->captureSentryEvents();
        Route::get('/api/_test/duplicate', function () use ($mobile) {
            Log::warning('admin.lookup', ['phone' => '+966'.$mobile]);

            DB::transaction(function () use ($mobile) {
                User::factory()->create(['phone_e164' => '+966'.$mobile]);
                User::factory()->create(['phone_e164' => '+966'.$mobile]);
            });
        });

        $this->getJson('/api/_test/duplicate?search=0'.$mobile)->assertStatus(500);

        $this->assertCount(1, $transport->events);
        $event = $transport->events[0];
        $value = $event->getExceptions()[0]->getValue();
        $this->assertStringContainsString('users_phone_e164_unique', $value);
        $this->assertStringContainsString('DETAIL: [removed]', $value);
        $this->assertStringContainsString('values (?, ?', $value);

        $payload = (new PayloadSerializer(new Options))->serialize($event);
        $this->assertStringNotContainsString($mobile, $payload);
        $this->assertDoesNotMatchRegularExpression('/search=0\d/', $payload);
    }

    #[Test]
    public function phone_numbers_are_masked_however_they_are_written(): void
    {
        $this->assertSame(
            'from [phone], [phone], [phone] and [phone]; order 1205123456789 kept',
            SentryScrubber::text('from +966512345678, 00966512345678, 966512345678 and 0512345678; order 1205123456789 kept'),
        );
    }

    #[Test]
    public function a_failing_row_is_removed_whatever_it_holds(): void
    {
        // 0x85 (the second byte of م) and a new line inside a value must not
        // end the removal early.
        $message = "SQLSTATE[23514]: Check violation: 7 ERROR:  new row for relation \"addresses\" violates check constraint \"addresses_coordinates_check\"\n"
            ."DETAIL:  Failing row contains (12, 7, محمد العتيبي, مكة المكرمة, شارع\nالملك فهد, 24.1, null).\n"
            .'CONTEXT:  SQL function "x" (Connection: pgsql, SQL: insert into "addresses" values (?, ?))';

        $this->assertSame(
            'SQLSTATE[23514]: Check violation: 7 ERROR:  new row for relation "addresses" violates check constraint "addresses_coordinates_check" DETAIL: [removed] (Connection: pgsql, SQL: insert into "addresses" values (?, ?))',
            SentryScrubber::text($message),
        );
        $this->assertSame('ERROR: x DETAIL: [removed]', SentryScrubber::text("ERROR: x\nDETAIL:  Key (name)=(محمد) already exists."));
    }

    #[Test]
    public function outgoing_calls_in_traces_keep_no_query_string(): void
    {
        $span = new Span;
        $span->setDescription('GET https://api.example.sa/v1/lookup?mobile=0512345678');
        $span->setData(['url' => 'https://api.example.sa/v1/lookup?mobile=0512345678', 'http.query' => 'mobile=0512345678', 'http.request.method' => 'GET']);
        $event = Event::createTransaction()->setSpans([$span]);
        $event->setRequest(['url' => 'https://api.example.sa/api/v1/admin/orders?search=0512345678', 'query_string' => 'search=0512345678']);

        SentryScrubber::transaction($event);

        $this->assertSame('GET https://api.example.sa/v1/lookup', $span->getDescription());
        $this->assertSame(['url' => 'https://api.example.sa/v1/lookup', 'http.query' => '', 'http.request.method' => 'GET'], $span->getData());
        $this->assertSame(['url' => 'https://api.example.sa/api/v1/admin/orders'], $event->getRequest());
    }

    /**
     * The client exactly as the app builds it from config/sentry.php, with a
     * transport that keeps the events instead of sending them.
     */
    private function captureSentryEvents(): object
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
        config(['sentry.dsn' => 'https://public@sentry.example.com/1']);
        $this->app->extend(ClientBuilder::class, fn (ClientBuilder $builder) => $builder->setTransport($transport));
        $this->app->forgetInstance(HubInterface::class);
        SentrySdk::setCurrentHub(app(HubInterface::class));

        return $transport;
    }
}
