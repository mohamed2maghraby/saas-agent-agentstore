<?php

namespace SaasAgent\AgentStore\Contract;

/**
 * One purchasable thing — a size, a colour, or the product itself when it has no
 * options. Its `id` is what inventory, pricing and order creation take.
 */
final readonly class Variant
{
    use Reads;

    /** @param array<string, string> $options e.g. ["Colour" => "Red"] */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $sku = null,
        public array $options = [],
        public ?Money $price = null,
        public ?StockStatus $stock = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $path = 'variant'): self
    {
        $stock = isset($data['stock']) ? StockStatus::tryFrom((string) $data['stock']) : null;

        if (isset($data['stock']) && $stock === null) {
            throw ContractException::field("{$path}.stock", 'a stock status');
        }

        return new self(
            self::str($data, 'id', $path, 191),
            self::str($data, 'title', $path, 500),
            self::optStr($data, 'sku', $path, 191),
            self::stringMap($data, 'options', $path),
            isset($data['price']) ? Money::fromArray(self::arr($data, 'price', $path), "{$path}.price") : null,
            $stock,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'sku' => $this->sku,
            'options' => (object) $this->options,
            'price' => $this->price?->toArray(),
            'stock' => $this->stock?->value,
        ];
    }
}
