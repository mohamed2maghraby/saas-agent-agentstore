<?php

namespace SaasAgent\AgentStore\Contract;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * The price a shopper would pay for one unit right now, before cart-level
 * offers, coupons and shipping — those only the store's own checkout can work out.
 */
final readonly class Price
{
    use Reads;

    public DateTimeInterface $asOf;

    public function __construct(
        public string $variantId,
        public Money $price,
        public ?Money $compareAt = null,
        ?DateTimeInterface $asOf = null,
    ) {
        $this->asOf = $asOf ?? new DateTimeImmutable;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $path = 'price'): self
    {
        return new self(
            self::str($data, 'variant_id', $path, 191),
            Money::fromArray(self::arr($data, 'price', $path), "{$path}.price"),
            isset($data['compare_at']) ? Money::fromArray(self::arr($data, 'compare_at', $path), "{$path}.compare_at") : null,
            self::optTime($data, 'as_of', $path),
        );
    }

    public function toArray(): array
    {
        return [
            'variant_id' => $this->variantId,
            'price' => $this->price->toArray(),
            'compare_at' => $this->compareAt?->toArray(),
            'as_of' => self::time($this->asOf),
        ];
    }
}
