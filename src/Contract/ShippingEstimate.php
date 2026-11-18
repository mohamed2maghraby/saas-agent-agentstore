<?php

namespace SaasAgent\AgentStore\Contract;

/**
 * What delivery to a place costs and takes, before there is an order.
 *
 * - `available` false: the store does not deliver there; `note` may say why.
 * - `fee` null while available: it cannot be priced without the items (by
 *   weight, say); `note` says so. A fee of zero is free delivery.
 * - `free_from`: the order value from which delivery is free, when there is one.
 *
 * An estimate, not a promise: what a particular order pays comes from its
 * quote, which also knows the offers and coupons.
 */
final readonly class ShippingEstimate
{
    use Reads;

    public function __construct(
        public bool $available,
        public ?string $destination = null,
        public ?Money $fee = null,
        public ?Money $freeFrom = null,
        public ?string $deliveryTime = null,
        public ?string $note = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $path = 'shipping'): self
    {
        return new self(
            self::bool($data, 'available', $path),
            self::optStr($data, 'destination', $path, 200),
            isset($data['fee']) ? Money::fromArray(self::arr($data, 'fee', $path), "{$path}.fee") : null,
            isset($data['free_from']) ? Money::fromArray(self::arr($data, 'free_from', $path), "{$path}.free_from") : null,
            self::optStr($data, 'delivery_time', $path, 200),
            self::optStr($data, 'note', $path, 1000),
        );
    }

    public function toArray(): array
    {
        return [
            'available' => $this->available,
            'destination' => $this->destination,
            'fee' => $this->fee?->toArray(),
            'free_from' => $this->freeFrom?->toArray(),
            'delivery_time' => $this->deliveryTime,
            'note' => $this->note,
        ];
    }
}
