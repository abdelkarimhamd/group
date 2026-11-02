<?php

namespace App\Http\Middleware;

use App\Support\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request an ID, echoed in the X-Request-Id response header and
 * attached to logs, audit records and any jobs the request dispatches.
 * A well-formed incoming ID (from a load balancer or client) is kept.
 */
class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(self::HEADER);
        $requestId = is_string($incoming) && preg_match('/^[A-Za-z0-9._-]{8,64}$/', $incoming)
            ? $incoming
            : (string) Str::uuid();

        RequestContext::start($requestId, $request->ip(), $request->userAgent());

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }
}
