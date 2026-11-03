<?php

namespace Tests\Unit\Support;

use App\Support\Http\Failures;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Http\Client\ConnectionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Whether a failed call can have reached the provider, for the exceptions
 * Guzzle 8 and libcurl 8 really raise, wrapped the way Laravel's HTTP client
 * wraps them (PendingRequest::marshalTransportException).
 */
class FailuresTest extends TestCase
{
    /** @return iterable<string, array{class-string<Throwable>, string, bool}> */
    public static function failures(): iterable
    {
        yield 'no DNS' => [ConnectException::class, 'cURL error 6: Could not resolve host: api.taqnyat.sa', true];
        yield 'connection refused' => [ConnectException::class, 'cURL error 7: Failed to connect to api.taqnyat.sa port 443 after 30 ms: Could not connect to server', true];
        yield 'proxy refused' => [ConnectException::class, 'cURL error 97: Proxy CONNECT aborted', true];
        yield 'bad certificate' => [ConnectException::class, 'cURL error 60: SSL certificate problem: unable to get local issuer certificate', true];
        yield 'connect timeout, curl 7 text' => [ConnectTimeoutException::class, 'cURL error 28: Connection timed out after 10001 milliseconds', true];
        yield 'connect timeout, ms text' => [ConnectTimeoutException::class, 'cURL error 28: Connection timeout after 10001 ms', true];
        yield 'TLS handshake timeout' => [ConnectTimeoutException::class, 'cURL error 28: SSL connection timeout', true];
        yield 'connect timeout, curl 8 text' => [NetworkTimeoutException::class, 'cURL error 28: Failed to connect to api.taqnyat.sa port 443 after 10002 ms: Timeout was reached', true];
        yield 'read timeout' => [NetworkTimeoutException::class, 'cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received', false];
        yield 'connection reset' => [NetworkException::class, 'cURL error 56: Recv failure: Connection reset by peer', false];
        yield 'empty reply' => [NetworkException::class, 'cURL error 52: Empty reply from server', false];
    }

    /** @param class-string<Throwable> $class */
    #[Test]
    #[DataProvider('failures')]
    public function only_a_call_that_never_left_counts_as_never_sent(string $class, string $text, bool $neverSent): void
    {
        $message = $text.' (see https://curl.se/libcurl/c/libcurl-errors.html) for https://api.taqnyat.sa/v1/messages';
        $guzzle = new $class($message, new Request('POST', 'https://api.taqnyat.sa/v1/messages'));

        $this->assertSame($neverSent, Failures::neverSent(new ConnectionException($message, 0, $guzzle)));
        // A faked ConnectionException carries only the message.
        $this->assertSame($neverSent, Failures::neverSent(new ConnectionException($message)));
    }
}
