<?php

namespace SaasAgent\AgentStore\Store;

use Illuminate\Contracts\Container\Container;
use LogicException;
use SaasAgent\AgentStore\Contract\Capability;
use SaasAgent\AgentStore\Store\Adapters\CustomerAdapterInterface;
use SaasAgent\AgentStore\Store\Adapters\InventoryAdapterInterface;
use SaasAgent\AgentStore\Store\Adapters\OrderAdapterInterface;
use SaasAgent\AgentStore\Store\Adapters\OrderCancellationAdapterInterface;
use SaasAgent\AgentStore\Store\Adapters\OrderPlacementAdapterInterface;
use SaasAgent\AgentStore\Store\Adapters\PricingAdapterInterface;
use SaasAgent\AgentStore\Store\Adapters\ProductAdapterInterface;
use SaasAgent\AgentStore\Store\Adapters\StoreCapabilityInterface;

/**
 * What this store supports: a capability is on when its adapter is configured,
 * it is not listed in `disabled_capabilities`, and the optional runtime policy
 * allows it. Nothing else turns one on.
 */
final class Capabilities
{
    /** @var array<string, class-string> config key => interface its adapter must implement */
    public const INTERFACES = [
        'products' => ProductAdapterInterface::class,
        'inventory' => InventoryAdapterInterface::class,
        'pricing' => PricingAdapterInterface::class,
        'customers' => CustomerAdapterInterface::class,
        'orders' => OrderAdapterInterface::class,
        'order_placement' => OrderPlacementAdapterInterface::class,
        'order_cancellation' => OrderCancellationAdapterInterface::class,
    ];

    public function __construct(private readonly Container $container) {}

    /** @return list<Capability> */
    public function supported(): array
    {
        return array_values(array_filter(Capability::cases(), fn (Capability $c) => $this->allows($c)));
    }

    public function allows(Capability $capability): bool
    {
        if (! config('agentstore.adapters.'.$capability->adapter())) {
            return false;
        }

        if (in_array($capability->value, (array) config('agentstore.disabled_capabilities', []), true)) {
            return false;
        }

        $policy = config('agentstore.capability_policy');

        if (! $policy) {
            return true;
        }

        $policy = $this->container->make($policy);

        if (! $policy instanceof StoreCapabilityInterface) {
            throw new LogicException('agentstore.capability_policy must implement StoreCapabilityInterface.');
        }

        return $policy->allows($capability);
    }

    /**
     * The configured adapter for a group, checked against its interface.
     *
     * @template T of object
     *
     * @param  class-string<T>  $interface
     * @return T
     */
    public function adapter(string $group, string $interface): object
    {
        $class = config("agentstore.adapters.{$group}");

        if (! $class) {
            throw new LogicException("No agentstore adapter is configured for [{$group}].");
        }

        $adapter = $this->container->make($class);

        if (! $adapter instanceof $interface) {
            throw new LogicException(sprintf('Adapter %s for [%s] must implement %s.', $class, $group, $interface));
        }

        return $adapter;
    }
}
