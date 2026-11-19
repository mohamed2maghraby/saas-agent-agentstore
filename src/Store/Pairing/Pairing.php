<?php

namespace SaasAgent\AgentStore\Store\Pairing;

use Carbon\CarbonImmutable;

/**
 * An approval given by a store admin and waiting for the SaaS to complete it.
 * Holds only the hash of the approval token. Kept in the cache until it expires
 * or is used (PendingPairings): it lives for minutes, so it needs no table.
 */
final class Pairing
{
    /** @param  list<string>  $capabilities */
    public function __construct(
        public readonly string $pairingId,
        public readonly string $tokenHash,
        public readonly array $capabilities,
        public readonly ?string $tenantLabel,
        public readonly int|string|null $approvedBy,
        public readonly CarbonImmutable $expiresAt,
    ) {}

    public function isOpen(): bool
    {
        return $this->expiresAt->isFuture();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'pairing_id' => $this->pairingId,
            'token_hash' => $this->tokenHash,
            'capabilities' => $this->capabilities,
            'tenant_label' => $this->tenantLabel,
            'approved_by' => $this->approvedBy,
            'expires_at' => $this->expiresAt->getTimestamp(),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['pairing_id'],
            (string) $data['token_hash'],
            array_values(array_map('strval', (array) $data['capabilities'])),
            isset($data['tenant_label']) ? (string) $data['tenant_label'] : null,
            $data['approved_by'] ?? null,
            CarbonImmutable::createFromTimestamp((int) $data['expires_at'], date_default_timezone_get()),
        );
    }
}
