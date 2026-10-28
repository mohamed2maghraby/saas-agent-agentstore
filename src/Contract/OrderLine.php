<?php

namespace SaasAgent\AgentStore\Contract;

final readonly class OrderLine
{
    use Reads;

    public function __construct(
        public string $variantId,
        public string $title,
        public int $quantity,
        public ?Money $unitPrice = null,
    ) {
        if ($quantity < 1) {
            throw ContractException::field('line.quantity', 'at least 1');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $path = 'line'): self
    {
        return new self(
            self::str($data, 'variant_id', $path, 191),
            self::str($data, 'title', $path, 500),
            self::int($data, 'quantity', $path, 1, 10000),
            isset($data['unit_price']) ? Money::fromArray(self::arr($data, 'unit_price', $path), "{$path}.unit_price") : null,
        );
    }

    public function toArray(): array
    {
        return [
            'variant_id' => $this->variantId,
            'title' => $this->title,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice?->toArray(),
        ];
    }
}
