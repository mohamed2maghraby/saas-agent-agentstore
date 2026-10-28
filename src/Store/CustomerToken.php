<?php

namespace SaasAgent\AgentStore\Store;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use SaasAgent\AgentStore\Contract\VerifiedCustomer;

/**
 * Seals a verified customer into an opaque, short-lived token.
 *
 * Order reads and cancellations take this token, never a customer id. The store
 * encrypts it with its own key and binds it to the pairing that asked, so the
 * only way to get one is a verification the store itself performed — not a
 * customer id an AI model or a bug on the SaaS side made up.
 */
final class CustomerToken
{
    public function __construct(private readonly Encrypter $encrypter) {}

    public function seal(VerifiedCustomer $customer, string $keyId): string
    {
        return $this->encrypter->encryptString(json_encode([
            'r' => $customer->ref,
            'n' => $customer->displayName,
            'k' => $keyId,
            'e' => time() + 60 * (int) config('agentstore.customer_token_ttl', 30),
        ], JSON_THROW_ON_ERROR));
    }

    /** Null when the token is forged, expired, or was issued to another pairing. */
    public function open(string $token, string $keyId): ?VerifiedCustomer
    {
        if (strlen($token) > 4096) {
            return null;
        }

        try {
            $data = json_decode($this->encrypter->decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        if (! is_array($data) || ($data['e'] ?? 0) < time() || ! hash_equals((string) ($data['k'] ?? ''), $keyId)) {
            return null;
        }

        return new VerifiedCustomer((string) $data['r'], $data['n'] ?? null);
    }
}
