<?php

namespace SaasAgent\AgentStore\Contract;

/**
 * A category a shopper can browse, as the store's menu shows it. Its `id` is
 * what a product search takes as `category`.
 */
final readonly class Category
{
    use Reads;

    public function __construct(
        public string $id,
        public string $name,
        public ?string $parentId = null,
        public ?string $url = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $path = 'category'): self
    {
        return new self(
            self::str($data, 'id', $path, 191),
            self::str($data, 'name', $path, 200),
            self::optStr($data, 'parent_id', $path, 191),
            self::optStr($data, 'url', $path, 2048),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'parent_id' => $this->parentId,
            'url' => $this->url,
        ];
    }
}
