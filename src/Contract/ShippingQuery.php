<?php

namespace SaasAgent\AgentStore\Contract;

/**
 * Where a shopper wants delivery, asked before there is an order: the city
 * (not a district), optionally the area and the country, and optionally what
 * the order would be worth — some stores price delivery by it. `order_value`
 * is in minor units of the store's currency, like a product search's
 * `max_price`.
 */
final readonly class ShippingQuery
{
    use Reads;

    public function __construct(
        public string $city,
        public ?string $area = null,
        public ?string $country = null,
        public ?int $orderValue = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            self::str($data, 'city', 'query', 120),
            self::optStr($data, 'area', 'query', 120),
            self::optStr($data, 'country', 'query', 120),
            self::optInt($data, 'order_value', 'query', 0, 100_000_000_000),
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'city' => $this->city,
            'area' => $this->area,
            'country' => $this->country,
            'order_value' => $this->orderValue,
        ], fn ($v) => $v !== null);
    }
}
