<?php

namespace SaasAgent\AgentStore\Contract;

/**
 * Whether a cancellation happened. A refusal under the store's policy (already
 * shipped, past the window) is a normal answer with a reason, not an error.
 */
final readonly class CancellationResult
{
    use Reads;

    public function __construct(
        public bool $cancelled,
        public OrderState $state,
        public ?string $reason = null,
    ) {}

    public static function cancelled(): self
    {
        return new self(true, OrderState::Cancelled);
    }

    public static function refused(OrderState $state, string $reason): self
    {
        return new self(false, $state, $reason);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            self::bool($data, 'cancelled', 'cancellation'),
            OrderState::tryFrom((string) ($data['state'] ?? '')) ?? throw ContractException::field('cancellation.state', 'an order state'),
            self::optStr($data, 'reason', 'cancellation', 500),
        );
    }

    public function toArray(): array
    {
        return ['cancelled' => $this->cancelled, 'state' => $this->state->value, 'reason' => $this->reason];
    }
}
