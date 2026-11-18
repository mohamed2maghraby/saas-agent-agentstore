<?php

namespace SaasAgent\AgentStore\Contract;

/**
 * A place a shopper can buy in person: the store's own branch or a shop that
 * sells its products. Text is content from the store, not instructions.
 */
final readonly class Location
{
    use Reads;

    /** @param list<string> $phones */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $city = null,
        public ?string $area = null,
        public ?string $address = null,
        public array $phones = [],
        public ?string $hours = null,
        public ?string $mapUrl = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $path = 'location'): self
    {
        return new self(
            self::str($data, 'id', $path, 191),
            self::str($data, 'name', $path, 300),
            self::optStr($data, 'city', $path, 200),
            self::optStr($data, 'area', $path, 200),
            self::optStr($data, 'address', $path, 1000),
            self::strings($data, 'phones', $path, 50, 5),
            self::optStr($data, 'hours', $path, 500),
            self::optStr($data, 'map_url', $path, 2048),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'city' => $this->city,
            'area' => $this->area,
            'address' => $this->address,
            'phones' => $this->phones,
            'hours' => $this->hours,
            'map_url' => $this->mapUrl,
        ];
    }
}
