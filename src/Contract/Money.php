<?php

namespace SaasAgent\AgentStore\Contract;

/**
 * An amount in minor units (piastres, cents) and its ISO-4217 currency.
 * Never a float: 570.00 EGP is {"amount": 57000, "currency": "EGP"}.
 */
final readonly class Money
{
    use Reads;

    public function __construct(
        public int $amount,
        public string $currency,
    ) {
        if ($amount < 0) {
            throw ContractException::field('money.amount', 'zero or positive');
        }

        if (! preg_match('/\A[A-Z]{3}\z/', $currency)) {
            throw ContractException::field('money.currency', 'a three-letter ISO-4217 code');
        }
    }

    /** Convenience for adapters holding a decimal price. Rounds half up to the minor unit. */
    public static function fromDecimal(string|int|float $amount, string $currency, int $decimals = 2): self
    {
        return new self((int) round((float) $amount * (10 ** $decimals), 0, PHP_ROUND_HALF_UP), $currency);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $path = 'money'): self
    {
        return new self(self::int($data, 'amount', $path, 0), self::str($data, 'currency', $path, 3));
    }

    /** @param array<string, mixed>|null $data */
    public static function optional(?array $data, string $path = 'money'): ?self
    {
        return $data === null ? null : self::fromArray($data, $path);
    }

    /** @return array{amount: int, currency: string} */
    public function toArray(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency];
    }
}
