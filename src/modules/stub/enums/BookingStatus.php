<?php

namespace justinholtweb\stub\enums;

enum BookingStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'noShow';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Confirmed => 'Confirmed',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::NoShow => 'No Show',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'orange',
            self::Confirmed => 'green',
            self::Completed => 'blue',
            self::Cancelled => 'red',
            self::NoShow => 'grey',
        };
    }

    public function isActive(): bool
    {
        return match ($this) {
            self::Pending, self::Confirmed => true,
            default => false,
        };
    }
}
