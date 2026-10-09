<?php

namespace justinholtweb\stub\enums;

enum PaymentStatus: string
{
    case Unpaid = 'unpaid';

    /** Some of the price has been received — usually a deposit — and a balance is still due. */
    case PartiallyPaid = 'partiallyPaid';
    case Paid = 'paid';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::PartiallyPaid => 'Partially Paid',
            self::Paid => 'Paid',
            self::Refunded => 'Refunded',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Unpaid => 'orange',
            self::PartiallyPaid => 'blue',
            self::Paid => 'green',
            self::Refunded => 'grey',
        };
    }
}
