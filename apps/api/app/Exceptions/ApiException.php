<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * An expected API failure with a stable `code` for clients to branch on and
 * a localized `message` for people. Rendered as
 * {"message": "...", "code": "..."} plus `errors` when a field is at fault.
 *
 * Messages come from lang/{ar,en}/messages.php under errors.<code>. Some
 * errors carry more for the client to act on, such as the new price after a
 * price change; that goes in `extra`, merged into the body.
 * These are expected outcomes, so they are not reported as errors; log
 * anything worth knowing where it is thrown.
 */
class ApiException extends RuntimeException implements ShouldntReport
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $extra  Merged into the body, after message, code and errors.
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        public readonly ?string $field = null,
        public readonly array $headers = [],
        public readonly array $extra = [],
    ) {
        parent::__construct($errorCode);
    }

    public static function unprocessable(string $code, ?string $field = null): self
    {
        return new self(422, $code, $field);
    }

    public static function unavailable(string $code): self
    {
        return new self(503, $code);
    }

    public function render(): JsonResponse
    {
        $message = __('messages.errors.'.$this->errorCode);
        $body = ['message' => $message, 'code' => $this->errorCode];

        if ($this->field !== null) {
            $body['errors'] = [$this->field => [$message]];
        }

        return response()->json($body + $this->extra, $this->status, $this->headers);
    }
}
