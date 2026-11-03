<?php

namespace App\Support\Http;

use GuzzleHttp\Exception\ConnectException;
use Throwable;

/**
 * Tells a call that certainly never reached the provider (no DNS, no
 * connection, no TLS handshake) from one whose request may have been
 * received: a read timeout or a dropped connection after sending. Only the
 * first is safe to send again to a provider that cannot drop repeats.
 */
final class Failures
{
    /** cURL errors raised before the request is sent: resolve, connect, TLS handshake. */
    private const BEFORE_SENDING = [5, 6, 7, 35, 60];

    public static function neverSent(Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof ConnectException) {
                $errno = $cause->getHandlerContext()['errno'] ?? null;

                if (is_int($errno) && in_array($errno, self::BEFORE_SENDING, true)) {
                    return true;
                }
            }

            // cURL 28 is any timeout: only one hit while connecting is before sending.
            if (preg_match('/cURL error (?:5|6|7|35|60)\b|cURL error 28: (?:Connection|Resolving) timed out/i', $cause->getMessage()) === 1) {
                return true;
            }
        }

        return false;
    }
}
