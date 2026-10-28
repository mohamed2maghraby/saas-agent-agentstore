<?php

namespace SaasAgent\AgentStore\Store\Adapters;

use SaasAgent\AgentStore\Contract\Capability;

/**
 * Optional runtime switch for capabilities — stop taking orders outside business
 * hours, or during a stock take — without unpairing. Consulted on every call and
 * by capability discovery.
 */
interface StoreCapabilityInterface
{
    public function allows(Capability $capability): bool;
}
