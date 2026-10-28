<?php

namespace SaasAgent\AgentStore\Contract;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Availability of one variant, as of a moment.
 *
 * `quantity` is optional on purpose: many stores would rather not disclose it,
 * and the AI never repeats it to a shopper. `status` is what decisions use.
 */
final readonly class StockLevel
{
    use Reads;

    public DateTimeInterface $asOf;

    public function __construct(
        public string $variantId,
        public StockStatus $status,
        public ?int $quantity = null,
        ?DateTimeInterface $asOf = null,
    ) {
        $this->asOf = $asOf ?? new DateTimeImmutable;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $path = 'stock'): self
    {
        $status = StockStatus::tryFrom((string) ($data['status'] ?? ''))
            ?? throw ContractException::field("{$path}.status", 'one of '.implode(', ', array_column(StockStatus::cases(), 'value')));

        return new self(
            self::str($data, 'variant_id', $path, 191),
            $status,
            self::optInt($data, 'quantity', $path, 0),
            self::optTime($data, 'as_of', $path),
        );
    }

    public function toArray(): array
    {
        return [
            'variant_id' => $this->variantId,
            'status' => $this->status->value,
            'quantity' => $this->quantity,
            'as_of' => self::time($this->asOf),
        ];
    }
}
