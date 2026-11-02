<?php

namespace App\Support;

use Illuminate\Support\Facades\Context;

/**
 * Request details shared with logs, audit records and queued jobs.
 *
 * Laravel's Context travels with queued jobs, so a capture job started by an
 * admin's request still carries that request's ID. The request ID is logged;
 * the IP address and user agent are kept hidden from logs (personal data).
 */
final class RequestContext
{
    public const REQUEST_ID = 'request_id';

    private const IP = 'ip';

    private const USER_AGENT = 'user_agent';

    public static function start(string $requestId, ?string $ip, ?string $userAgent): void
    {
        Context::add(self::REQUEST_ID, $requestId);
        Context::addHidden(self::IP, $ip);
        Context::addHidden(self::USER_AGENT, $userAgent === null ? null : mb_substr($userAgent, 0, 512));
    }

    public static function requestId(): ?string
    {
        return Context::get(self::REQUEST_ID);
    }

    public static function ip(): ?string
    {
        return Context::getHidden(self::IP);
    }

    public static function userAgent(): ?string
    {
        return Context::getHidden(self::USER_AGENT);
    }
}
