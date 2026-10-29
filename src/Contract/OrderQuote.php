<?php

namespace SaasAgent\AgentStore\Contract;

/**
 * What an order would cost if placed now, worked out by the store's own
 * checkout: the lines after offers, the coupon, delivery, fees and the total.
 *
 * `delivery` null means the store cannot price delivery to this address yet —
 * not that it is free. `requiredFields` names what placing the order will ask
 * for, from: customer_name, customer_phone, customer_email, address.city,
 * address.area, address.street, address.building. `notes` are short facts from
 * the store for the shopper (a coupon dropped, a minimum not reached).
 */
final readonly class OrderQuote
{
    use Reads;

    public const FIELDS = ['customer_name', 'customer_phone', 'customer_email', 'address.city', 'address.area', 'address.street', 'address.building'];

    /**
     * @param  list<QuoteLine>  $lines
     * @param  list<string>  $offers  titles of the offers that priced this order
     * @param  list<string>  $requiredFields
     * @param  list<string>  $notes
     */
    public function __construct(
        public array $lines,
        public Money $subtotal,
        public Money $total,
        public ?Money $discount = null,
        public ?Money $delivery = null,
        public ?Money $fees = null,
        public ?string $deliveryEstimate = null,
        public array $offers = [],
        public array $requiredFields = [],
        public array $notes = [],
    ) {
        if ($lines === [] || count($lines) > 200) {
            throw ContractException::field('quote.lines', 'between 1 and 200 lines');
        }

        if (array_diff($requiredFields, self::FIELDS) !== []) {
            throw ContractException::field('quote.required_fields', 'names from '.implode(', ', self::FIELDS));
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $lines = [];
        foreach (self::arr($data, 'lines', 'quote') as $i => $line) {
            if (! is_array($line)) {
                throw ContractException::field("quote.lines.{$i}", 'an object');
            }
            $lines[] = QuoteLine::fromArray($line, "quote.lines.{$i}");
        }

        $money = fn (string $key) => Money::optional(isset($data[$key]) ? self::arr($data, $key, 'quote') : null, "quote.{$key}");

        return new self(
            $lines,
            Money::fromArray(self::arr($data, 'subtotal', 'quote'), 'quote.subtotal'),
            Money::fromArray(self::arr($data, 'total', 'quote'), 'quote.total'),
            $money('discount'),
            $money('delivery'),
            $money('fees'),
            self::optStr($data, 'delivery_estimate', 'quote', 200),
            self::strings($data, 'offers', 'quote', 300, 20),
            self::strings($data, 'required_fields', 'quote', 40, 10),
            self::strings($data, 'notes', 'quote', 500, 10),
        );
    }

    public function toArray(): array
    {
        return [
            'lines' => array_map(fn (QuoteLine $line) => $line->toArray(), $this->lines),
            'subtotal' => $this->subtotal->toArray(),
            'discount' => $this->discount?->toArray(),
            'delivery' => $this->delivery?->toArray(),
            'fees' => $this->fees?->toArray(),
            'total' => $this->total->toArray(),
            'delivery_estimate' => $this->deliveryEstimate,
            'offers' => $this->offers,
            'required_fields' => $this->requiredFields,
            'notes' => $this->notes,
        ];
    }
}
