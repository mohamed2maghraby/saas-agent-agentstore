<?php

namespace SaasAgent\AgentStore\Contract;

/**
 * What an order would cost, asked before it is placed: the items, where they
 * go and how they are paid for. No customer details — a quote needs none —
 * and no prices: the store prices it with its own checkout.
 */
final readonly class OrderQuoteRequest
{
    use Reads;

    /**
     * @param  list<array{variant_id: string, quantity: int}>  $items
     * @param  array<string, string>  $address  city, area … — whatever the store prices delivery by
     */
    public function __construct(
        public array $items,
        public array $address,
        public string $paymentMethod,
        public ?string $couponCode = null,
    ) {
        if ($items === [] || count($items) > 100) {
            throw ContractException::field('quote.items', 'between 1 and 100 lines');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $items = [];
        foreach (self::arr($data, 'items', 'quote') as $i => $item) {
            if (! is_array($item)) {
                throw ContractException::field("quote.items.{$i}", 'an object');
            }
            $items[] = [
                'variant_id' => self::str($item, 'variant_id', "quote.items.{$i}", 191),
                'quantity' => self::int($item, 'quantity', "quote.items.{$i}", 1, 1000),
            ];
        }

        return new self(
            $items,
            self::stringMap($data, 'address', 'quote', 20),
            self::str($data, 'payment_method', 'quote', 32),
            self::optStr($data, 'coupon_code', 'quote', 64),
        );
    }

    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'address' => (object) $this->address,
            'payment_method' => $this->paymentMethod,
            'coupon_code' => $this->couponCode,
        ];
    }
}
