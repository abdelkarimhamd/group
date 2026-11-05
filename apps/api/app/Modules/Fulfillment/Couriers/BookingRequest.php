<?php

namespace App\Modules\Fulfillment\Couriers;

/**
 * One parcel from the warehouse to a customer, as the courier needs it.
 */
final readonly class BookingRequest
{
    /**
     * @param  array{name: string, phone: string, city: string, district: string|null, street: string|null, building_no: string|null, postal_code: string|null, short_address: string|null}  $consignee
     */
    public function __construct(
        public string $reference,
        public string $orderNumber,
        public array $consignee,
        public int $pieces,
        public int $weightGrams,
        public string $description,
    ) {}
}
