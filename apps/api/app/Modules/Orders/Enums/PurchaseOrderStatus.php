<?php

namespace App\Modules\Orders\Enums;

/**
 * A supplier purchase order (spec, Data model: supplier_purchase_orders).
 * It only moves forward; `received` comes from the warehouse receipt (E13)
 * and `cancelled` from a campaign cancelled through finance (E15).
 */
enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Placed = 'placed';
    case SupplierConfirmed = 'supplier_confirmed';
    case Paid = 'paid';
    case Shipped = 'shipped';
    case Received = 'received';
    case Cancelled = 'cancelled';

    /**
     * Where this status sits on the way from draft to received.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Draft => 0,
            self::Placed => 1,
            self::SupplierConfirmed => 2,
            self::Paid => 3,
            self::Shipped => 4,
            self::Received => 5,
            self::Cancelled => 6,
        };
    }

    /**
     * The statuses an admin moves the order to by hand.
     *
     * @return list<self>
     */
    public static function advanceable(): array
    {
        return [self::Placed, self::SupplierConfirmed, self::Paid, self::Shipped];
    }

    /**
     * The column holding when the order reached this status, if any.
     */
    public function timestampColumn(): ?string
    {
        return match ($this) {
            self::Placed => 'placed_at',
            self::SupplierConfirmed => 'supplier_confirmed_at',
            self::Paid => 'paid_at',
            self::Shipped => 'shipped_at',
            self::Received => 'received_at',
            self::Cancelled => 'cancelled_at',
            self::Draft => null,
        };
    }

    public function isClosed(): bool
    {
        return $this === self::Received || $this === self::Cancelled;
    }
}
