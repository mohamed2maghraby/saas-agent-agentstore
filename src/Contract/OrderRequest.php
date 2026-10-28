<?php

namespace SaasAgent\AgentStore\Contract;

/**
 * Everything the store's own checkout needs to place an order — and nothing it
 * should take on trust. There are no prices here: the store prices the order
 * itself, with its own offers, shipping and tax, exactly as its checkout would.
 */
final readonly class OrderRequest
{
    use Reads;

    /**
     * @param  list<array{variant_id: string, quantity: int}>  $items
     * @param  array<string, string>  $address  street, city, area, building, notes …
     */
    public function __construct(
        public array $items,
        public string $customerName,
        public string $customerPhone,
        public array $address,
        public string $paymentMethod,
        public ?string $customerEmail = null,
        public ?string $couponCode = null,
        public ?string $notes = null,
    ) {
        if ($items === [] || count($items) > 100) {
            throw ContractException::field('order.items', 'between 1 and 100 lines');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $items = [];
        foreach (self::arr($data, 'items', 'order') as $i => $item) {
            if (! is_array($item)) {
                throw ContractException::field("order.items.{$i}", 'an object');
            }
            $items[] = [
                'variant_id' => self::str($item, 'variant_id', "order.items.{$i}", 191),
                'quantity' => self::int($item, 'quantity', "order.items.{$i}", 1, 1000),
            ];
        }

        return new self(
            $items,
            self::str($data, 'customer_name', 'order', 255),
            self::str($data, 'customer_phone', 'order', 32),
            self::stringMap($data, 'address', 'order', 20),
            self::str($data, 'payment_method', 'order', 32),
            self::optStr($data, 'customer_email', 'order', 255),
            self::optStr($data, 'coupon_code', 'order', 64),
            self::optStr($data, 'notes', 'order', 1000),
        );
    }

    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'customer_name' => $this->customerName,
            'customer_phone' => $this->customerPhone,
            'customer_email' => $this->customerEmail,
            'address' => (object) $this->address,
            'payment_method' => $this->paymentMethod,
            'coupon_code' => $this->couponCode,
            'notes' => $this->notes,
        ];
    }
}
