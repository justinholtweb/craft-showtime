<?php

namespace justinholtweb\stub\enums;

/**
 * How a service is paid for. Set per service, and copied onto each booking when it's made,
 * so changing a service's mode later never changes what an existing booking owes.
 */
enum PaymentMode: string
{
    /** The whole price online, at booking time. What Stub has always done. */
    case Full = 'full';

    /** Part of the price online at booking time; the balance is collected later, by staff. */
    case Deposit = 'deposit';

    /** Nothing online. The booking is confirmed straight away and stays unpaid until staff mark it paid. */
    case InPerson = 'inPerson';

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full payment at booking',
            self::Deposit => 'Deposit at booking',
            self::InPerson => 'Pay in person',
        };
    }
}
