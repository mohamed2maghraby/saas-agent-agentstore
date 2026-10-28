<?php

namespace SaasAgent\AgentStore\Contract;

use DateTimeInterface;

/**
 * Where an order stands. Deliberately free of the address, the phone number and
 * the payment details: an order lookup answers "where is it", nothing more.
 */
final readonly class OrderStatus
{
    use Reads;

    /** @param list<OrderLine> $lines */
    public function __construct(
        public string $id,
        public string $number,
        public OrderState $state,
        public string $storeStatus,
        public Money $total,
        public array $lines,
        public ?DateTimeInterface $placedAt = null,
        public ?string $trackingUrl = null,
        public bool $cancellable = false,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $path = 'order'): self
    {
        $state = OrderState::tryFrom((string) ($data['state'] ?? ''))
            ?? throw ContractException::field("{$path}.state", 'an order state');
        $lines = self::arr($data, 'lines', $path);

        if (count($lines) > 500) {
            throw ContractException::field("{$path}.lines", 'at most 500 lines');
        }

        return new self(
            self::str($data, 'id', $path, 191),
            self::str($data, 'number', $path, 64),
            $state,
            self::str($data, 'store_status', $path, 100),
            Money::fromArray(self::arr($data, 'total', $path), "{$path}.total"),
            array_values(array_map(
                fn ($line, $i) => OrderLine::fromArray(is_array($line) ? $line : [], "{$path}.lines.{$i}"),
                $lines,
                array_keys($lines),
            )),
            self::optTime($data, 'placed_at', $path),
            self::optStr($data, 'tracking_url', $path, 2048),
            (bool) ($data['cancellable'] ?? false),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'state' => $this->state->value,
            'store_status' => $this->storeStatus,
            'total' => $this->total->toArray(),
            'lines' => array_map(fn (OrderLine $l) => $l->toArray(), $this->lines),
            'placed_at' => self::time($this->placedAt),
            'tracking_url' => $this->trackingUrl,
            'cancellable' => $this->cancellable,
        ];
    }
}
