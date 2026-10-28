<?php

namespace SaasAgent\AgentStore\Contract;

/**
 * One page of search results. `nextCursor` is null on the last page.
 */
final readonly class ProductPage
{
    use Reads;

    /** @param list<Product> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor = null,
    ) {
        if (count($items) > ProductQuery::MAX_LIMIT) {
            throw ContractException::field('page.items', 'at most '.ProductQuery::MAX_LIMIT.' items');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $items = self::arr($data, 'items', 'page');

        return new self(
            array_values(array_map(
                fn ($item, $i) => Product::fromArray(is_array($item) ? $item : [], "page.items.{$i}"),
                $items,
                array_keys($items),
            )),
            self::optStr($data, 'next_cursor', 'page', 500),
        );
    }

    public function toArray(): array
    {
        return [
            'items' => array_map(fn (Product $p) => $p->toArray(), $this->items),
            'next_cursor' => $this->nextCursor,
        ];
    }
}
