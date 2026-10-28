<?php

namespace SaasAgent\AgentStore\Contract;

/**
 * Normalised order lifecycle. Every store maps its own statuses onto these and
 * also returns its own label, which is what the shopper is told.
 */
enum OrderState: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Returned = 'returned';
    case Unknown = 'unknown';

    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled, self::Returned], true);
    }
}
