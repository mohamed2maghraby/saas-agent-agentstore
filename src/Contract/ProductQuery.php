<?php

namespace SaasAgent\AgentStore\Contract;

/**
 * A product search. Every field is optional; the store decides what "matches"
 * means for its catalog. `cursor` is opaque and comes from a previous page.
 */
final readonly class ProductQuery
{
    use Reads;

    public const MAX_LIMIT = 50;

    public function __construct(
        public ?string $text = null,
        public ?string $category = null,
        public ?int $maxPrice = null,
        public bool $inStockOnly = false,
        public int $limit = 20,
        public ?string $cursor = null,
    ) {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw ContractException::field('query.limit', 'between 1 and '.self::MAX_LIMIT);
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            self::optStr($data, 'text', 'query', 200),
            self::optStr($data, 'category', 'query', 200),
            self::optInt($data, 'max_price', 'query', 0),
            (bool) ($data['in_stock_only'] ?? false),
            self::optInt($data, 'limit', 'query', 1, self::MAX_LIMIT) ?? 20,
            self::optStr($data, 'cursor', 'query', 500),
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'text' => $this->text,
            'category' => $this->category,
            'max_price' => $this->maxPrice,
            'in_stock_only' => $this->inStockOnly ?: null,
            'limit' => $this->limit,
            'cursor' => $this->cursor,
        ], fn ($v) => $v !== null);
    }
}
