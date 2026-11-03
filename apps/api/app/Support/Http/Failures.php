<?php

namespace App\Support\Http;

use GuzzleHttp\Exception\ConnectException;
use Throwable;

/**
 * Tells a call that certainly never reached the provider (no DNS, no
 * connection, no TLS or proxy handshake) from one whose request may have
 * been received: a read timeout or a dropped connection after sending. Only
 * the first is safe to send again to a provider that cannot drop repeats.
 */
final class Failures
{
    /**
     * cURL errors raised before the request is sent, as Guzzle classes them
     * (CurlFactory::CURL_CONNECTION_ERRORS): resolve, connect, TLS, proxy.
     */
    private const CONNECTION_ERRNOS = '5|6|7|35|51|60|83|90|91|96|97|98|101';

    /**
     * cURL 28 is any timeout. These are the texts curl gives one that hit
     * while resolving, connecting or shaking hands, before anything was sent
     * (Guzzle's CURL_CONNECT_TIMEOUT_ERRORS, plus curl 8's "Failed to connect
     * to ... after N ms: Timeout was reached").
     */
    private const CONNECT_TIMEOUT = '/Connection timed out|Connection timeout|Connection time-out|Resolving timed out|name lookup timed out|Proxy CONNECT aborted due to timeout|SSL connection timeout|Failed to connect to |Failed to resolve.*timeout/i';

    public static function neverSent(Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            // Guzzle's own verdict, timeouts while connecting included
            // (ConnectTimeoutException extends ConnectException).
            if ($cause instanceof ConnectException) {
                return true;
            }

            // Laravel's ConnectionException repeats Guzzle's message, and a
            // faked one has nothing else to go on.
            $message = $cause->getMessage();

            if (preg_match('/cURL error (?:'.self::CONNECTION_ERRNOS.'):/i', $message) === 1) {
                return true;
            }

            if (preg_match('/cURL error 28:/i', $message) === 1 && preg_match(self::CONNECT_TIMEOUT, $message) === 1) {
                return true;
            }
        }

        return false;
    }
}
