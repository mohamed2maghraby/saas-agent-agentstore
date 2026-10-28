<?php

namespace SaasAgent\AgentStore\Contract;

/**
 * The store's answer to an order request: the order as the store's own checkout
 * created it, with the total the store computed.
 */
final readonly class PlacedOrder
{
    use Reads;

    public function __construct(
        public string $id,
        public string $number,
        public OrderState $state,
        public Money $total,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            self::str($data, 'id', 'placed', 191),
            self::str($data, 'number', 'placed', 64),
            OrderState::tryFrom((string) ($data['state'] ?? '')) ?? throw ContractException::field('placed.state', 'an order state'),
            Money::fromArray(self::arr($data, 'total', 'placed'), 'placed.total'),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'state' => $this->state->value,
            'total' => $this->total->toArray(),
        ];
    }
}
