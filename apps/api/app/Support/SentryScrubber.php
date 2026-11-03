<?php

namespace App\Support;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Stacktrace;

/**
 * Removes customer data from what goes to Sentry (`before_send` and
 * `before_send_transaction` in config/sentry.php), on top of what the SDK
 * already leaves out (request bodies, cookies, IPs, SQL bindings):
 *
 * - PostgreSQL's DETAIL and CONTEXT text, which repeats the row's values (a
 *   duplicate phone number, a failing check), from every exception message;
 * - Saudi mobile numbers anywhere in messages, breadcrumbs and extra data;
 * - query strings (admin searches can hold a phone number) from the request
 *   and from HTTP breadcrumbs and spans;
 * - function arguments in stack frames, whatever zend.exception_ignore_args is.
 */
final class SentryScrubber
{
    private const PHONE = '/(?<!\d)(?:(?:\+|00)?9665\d{8}|05\d{8})(?!\d)/';

    /**
     * PostgreSQL's DETAIL and CONTEXT text, which quotes the data, up to
     * Laravel's "(Connection: ...)" suffix or the end. Never stops at a line
     * break: a quoted value can hold one, and in byte mode \R would also stop
     * at 0x85, the second byte of the Arabic letter meem.
     */
    private const DETAIL = '/\s*\b(DETAIL|CONTEXT):\s.*?(?=\s\(Connection:|\z)/s';

    public static function event(Event $event, ?EventHint $hint = null): ?Event
    {
        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(self::text($exception->getValue()));
            self::dropArguments($exception->getStacktrace());
        }

        self::dropArguments($event->getStacktrace());

        if ($event->getMessage() !== null) {
            $formatted = $event->getMessageFormatted();
            $event->setMessage(self::text($event->getMessage()), self::scrub($event->getMessageParams()), $formatted === null ? null : self::text($formatted));
        }

        $event->setExtra(self::scrub($event->getExtra()));

        return self::common($event);
    }

    public static function transaction(Event $event, ?EventHint $hint = null): ?Event
    {
        foreach ($event->getSpans() as $span) {
            if ($span->getDescription() !== null) {
                $span->setDescription(self::text(self::withoutQuery($span->getDescription())));
            }

            // Span::setData() merges, so a key is blanked, never unset.
            $data = $span->getData();

            if (array_key_exists('http.query', $data)) {
                $data['http.query'] = '';
            }

            foreach (['url', 'http.url'] as $key) {
                if (is_string($data[$key] ?? null)) {
                    $data[$key] = explode('?', $data[$key], 2)[0];
                }
            }

            $span->setData(self::scrub($data));
        }

        return self::common($event);
    }

    /** The masking used everywhere: no DETAIL or CONTEXT text, no mobile numbers. */
    public static function text(string $text): string
    {
        // If PCRE gives up on a very long message, drop everything after the
        // first DETAIL or CONTEXT rather than send it as it is.
        $text = preg_replace(self::DETAIL, ' $1: [removed]', $text)
            ?? preg_replace('/\b(DETAIL|CONTEXT):.*/s', '$1: [removed]', $text)
            ?? '[removed]';

        return preg_replace(self::PHONE, '[phone]', $text) ?? $text;
    }

    private static function common(Event $event): Event
    {
        $request = $event->getRequest();
        unset($request['query_string']);

        if (is_string($request['url'] ?? null)) {
            $request['url'] = explode('?', $request['url'], 2)[0];
        }

        $event->setRequest(self::scrub($request));
        $event->setBreadcrumb(array_map(self::breadcrumb(...), $event->getBreadcrumbs()));

        return $event;
    }

    private static function breadcrumb(Breadcrumb $crumb): Breadcrumb
    {
        if ($crumb->getMessage() !== null) {
            $crumb = $crumb->withMessage(self::text(self::withoutQuery($crumb->getMessage())));
        }

        $crumb = $crumb->withoutMetadata('http.query');

        foreach ($crumb->getMetadata() as $name => $value) {
            $value = $name === 'url' && is_string($value) ? explode('?', $value, 2)[0] : $value;
            $crumb = $crumb->withMetadata((string) $name, self::scrub($value));
        }

        return $crumb;
    }

    private static function dropArguments(?Stacktrace $stacktrace): void
    {
        foreach ($stacktrace?->getFrames() ?? [] as $frame) {
            $frame->setVars([]);
        }
    }

    private static function withoutQuery(string $text): string
    {
        return preg_replace('/(https?:\/\/[^\s?#]+)\?[^\s#]*/i', '$1', $text) ?? $text;
    }

    private static function scrub(mixed $value): mixed
    {
        return match (true) {
            is_string($value) => self::text($value),
            is_array($value) => array_map(self::scrub(...), $value),
            default => $value,
        };
    }
}
