<?php

namespace App\Modules\Fulfillment\Couriers;

use Carbon\CarbonImmutable;

/**
 * One tracking update as the courier reports it. `code` is the courier's
 * own; `config('groupbuy.couriers.{driver}.codes')` turns it into one of our
 * shipment statuses.
 */
final readonly class TrackingUpdate
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $trackingNumber,
        public string $code,
        public ?string $description,
        public ?string $location,
        public CarbonImmutable $occurredAt,
        public array $raw = [],
    ) {}

    /**
     * The same update read twice has the same key.
     */
    public function key(): string
    {
        return hash('sha256', implode('|', [$this->code, $this->occurredAt->getTimestamp(), $this->location ?? '', $this->description ?? '']));
    }
}
