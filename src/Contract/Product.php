<?php

namespace SaasAgent\AgentStore\Contract;

/**
 * A product as the contract describes it, with its variants.
 *
 * Text fields are content, not instructions: the SaaS treats a description as
 * untrusted data even though it came from a paired store.
 */
final readonly class Product
{
    use Reads;

    /**
     * @param  list<Variant>  $variants
     * @param  list<string>  $images  absolute URLs
     * @param  list<string>  $categories
     * @param  array<string, string>  $attributes
     */
    public function __construct(
        public string $id,
        public string $title,
        public array $variants,
        public ?string $description = null,
        public ?string $url = null,
        public array $images = [],
        public array $categories = [],
        public array $attributes = [],
    ) {
        if ($variants === []) {
            throw ContractException::field('product.variants', 'a list with at least one variant');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $path = 'product'): self
    {
        $variants = self::arr($data, 'variants', $path);

        if (count($variants) > 250) {
            throw ContractException::field("{$path}.variants", 'at most 250 variants');
        }

        return new self(
            self::str($data, 'id', $path, 191),
            self::str($data, 'title', $path, 500),
            array_values(array_map(
                fn ($variant, $i) => Variant::fromArray(is_array($variant) ? $variant : [], "{$path}.variants.{$i}"),
                $variants,
                array_keys($variants),
            )),
            self::optStr($data, 'description', $path, 20000),
            self::optStr($data, 'url', $path, 2048),
            self::strings($data, 'images', $path, 2048, 20),
            self::strings($data, 'categories', $path, 200, 20),
            self::stringMap($data, 'attributes', $path),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'url' => $this->url,
            'images' => $this->images,
            'categories' => $this->categories,
            'attributes' => (object) $this->attributes,
            'variants' => array_map(fn (Variant $v) => $v->toArray(), $this->variants),
        ];
    }
}
