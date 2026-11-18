<?php

namespace SaasAgent\AgentStore\Contract;

/**
 * What a store can do through the contract. A store reports the subset it
 * supports; the SaaS never calls one it did not report.
 */
enum Capability: string
{
    case ProductsSearch = 'products.search';
    case ProductsRead = 'products.read';
    case InventoryRead = 'inventory.read';
    case PricesRead = 'prices.read';
    case OffersList = 'offers.list';
    case CategoriesList = 'categories.list';
    case ShippingEstimate = 'shipping.estimate';
    case LocationsList = 'locations.list';
    case CustomersVerify = 'customers.verify';
    case OrdersRead = 'orders.read';
    case OrdersList = 'orders.list';
    case OrdersQuote = 'orders.quote';
    case OrdersCreate = 'orders.create';
    case OrdersCancel = 'orders.cancel';

    /** Capabilities that change data in the store. Off by default on the SaaS side. */
    public function isWrite(): bool
    {
        return in_array($this, [self::OrdersCreate, self::OrdersCancel], true);
    }

    /**
     * The adapter group (config key) that provides it.
     */
    public function adapter(): string
    {
        return match ($this) {
            self::ProductsSearch, self::ProductsRead => 'products',
            self::InventoryRead => 'inventory',
            self::PricesRead => 'pricing',
            self::OffersList => 'offers',
            self::CategoriesList => 'categories',
            self::ShippingEstimate => 'shipping',
            self::LocationsList => 'locations',
            self::CustomersVerify => 'customers',
            self::OrdersRead, self::OrdersList => 'orders',
            self::OrdersQuote => 'order_quote',
            self::OrdersCreate => 'order_placement',
            self::OrdersCancel => 'order_cancellation',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
