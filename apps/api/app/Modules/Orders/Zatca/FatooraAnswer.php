<?php

namespace App\Modules\Orders\Zatca;

/**
 * Fatoora's answer to a document sent for reporting or a compliance check.
 */
final class FatooraAnswer
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
    ) {}

    /**
     * Reported (200), reported with warnings (202), or already reported (409).
     */
    public function accepted(): bool
    {
        return in_array($this->status, [200, 202, 409], true);
    }

    /**
     * ZATCA found errors in the document (400): sending it again changes nothing.
     */
    public function refused(): bool
    {
        return $this->status === 400;
    }

    /**
     * @return list<string> "CODE: message" for each warning
     */
    public function warnings(): array
    {
        return $this->messages('warningMessages');
    }

    /**
     * @return list<string> "CODE: message" for each error
     */
    public function errors(): array
    {
        return $this->messages('errorMessages');
    }

    /**
     * @return list<string>
     */
    private function messages(string $key): array
    {
        $messages = $this->body['validationResults'][$key] ?? [];

        if (! is_array($messages)) {
            return [];
        }

        return array_values(array_map(
            fn ($m) => is_array($m) ? trim(((string) ($m['code'] ?? '')).': '.((string) ($m['message'] ?? '')), ': ') : (string) $m,
            $messages,
        ));
    }
}
