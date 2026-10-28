<?php

namespace SaasAgent\AgentStore\Contract;

/**
 * A customer the store has verified. `ref` is the store's own customer key and
 * never leaves the store in plain form: the package seals it into a short-lived
 * token, so an order lookup can only ever be for someone the store itself verified.
 */
final readonly class VerifiedCustomer
{
    public function __construct(
        public string $ref,
        public ?string $displayName = null,
    ) {
        if ($ref === '' || mb_strlen($ref) > 191) {
            throw ContractException::field('customer.ref', 'a non-empty store reference');
        }
    }
}
