<?php

namespace SaasAgent\AgentStore\Contract;

/**
 * An offer running in the store right now, described for a shopper. What it
 * does to a particular order is the quote's business; this says only that it
 * exists, what it is, and until when.
 */
final readonly class Offer
{
    use Reads;

    public function __construct(
        public string $id,
        public string $title,
        public ?string $description = null,
        public ?\DateTimeImmutable $endsAt = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $path = 'offer'): self
    {
        return new self(
            self::str($data, 'id', $path, 191),
            self::str($data, 'title', $path, 300),
            self::optStr($data, 'description', $path, 2000),
            self::optTime($data, 'ends_at', $path),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'ends_at' => self::time($this->endsAt),
        ];
    }
}
