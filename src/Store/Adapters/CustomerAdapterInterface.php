<?php

namespace SaasAgent\AgentStore\Store\Adapters;

use SaasAgent\AgentStore\Contract\CustomerVerification;
use SaasAgent\AgentStore\Contract\VerifiedCustomer;

/**
 * Decides whether a shopper is who they claim. Provides `customers.verify`.
 *
 * Apply the store's own policy: a phone number alone may be enough for one
 * store and not for another. Return null to refuse — never a guess.
 */
interface CustomerAdapterInterface
{
    public function verify(CustomerVerification $verification): ?VerifiedCustomer;
}
