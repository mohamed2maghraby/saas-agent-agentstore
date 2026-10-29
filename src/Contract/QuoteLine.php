<?php

namespace SaasAgent\AgentStore\Contract;

/**
 * One line of a quote, priced as the checkout prices it: `total` is what the
 * line costs with offers applied, `listTotal` what it would have cost without
 * them (absent when nothing came off). A gift is a line an offer added.
 */
final readonly class QuoteLine
{
    use Reads;

    public function __construct(
        public string $variantId,
        public string $title,
        public int $quantity,
        public Money $total,
        public ?Money $listTotal = null,
        public bool $gift = false,
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
            Money::fromArray(self::arr($data, 'total', $path), "{$path}.total"),
            Money::optional(isset($data['list_total']) ? self::arr($data, 'list_total', $path) : null, "{$path}.list_total"),
            isset($data['gift']) && self::bool($data, 'gift', $path),
        );
    }

    public function toArray(): array
    {
        return [
            'variant_id' => $this->variantId,
            'title' => $this->title,
            'quantity' => $this->quantity,
            'total' => $this->total->toArray(),
            'list_total' => $this->listTotal?->toArray(),
            'gift' => $this->gift,
        ];
    }
}
