<?php

/*
 | SaaS Agent store integration.
 |
 | Publish with `php artisan agentstore:install`, then fill in `adapters` with the
 | classes that map this store's own models and services onto the contract. Run
 | `php artisan agentstore:doctor` to check the result.
 */

return [

    /*
     | The one SaaS this store talks to. Pairing requests are fetched from here
     | and only from here; a link pointing anywhere else is refused. https only,
     | except when the app runs locally.
     */
    'saas_url' => env('AGENTSTORE_SAAS_URL'),

    /*
     | This store's public address, as it was entered in the SaaS when
     | connecting. Pairing requests for any other address are refused. Leave it
     | unset to use APP_URL; set it when APP_URL is not the address the SaaS
     | reaches — e.g. a local copy whose APP_URL still names production.
     */
    'store_url' => env('AGENTSTORE_STORE_URL'),

    /*
     | One class per capability group. Leave a group null and the store reports
     | the matching capabilities as unsupported — the SaaS will not offer them.
     |
     | Each class implements the interface named in the comment and is resolved
     | from the container, so constructor injection of the store's own services
     | works.
     */
    'adapters' => [
        'products' => null,           // Store\Adapters\ProductAdapterInterface        → products.search, products.read
        'inventory' => null,          // Store\Adapters\InventoryAdapterInterface      → inventory.read
        'pricing' => null,            // Store\Adapters\PricingAdapterInterface        → prices.read
        'offers' => null,             // Store\Adapters\OfferAdapterInterface          → offers.list
        'categories' => null,         // Store\Adapters\CategoryAdapterInterface       → categories.list
        'shipping' => null,           // Store\Adapters\ShippingAdapterInterface       → shipping.estimate
        'locations' => null,          // Store\Adapters\LocationAdapterInterface       → locations.list
        'customers' => null,          // Store\Adapters\CustomerAdapterInterface       → customers.verify
        'orders' => null,             // Store\Adapters\OrderAdapterInterface          → orders.read, orders.list
        'order_quote' => null,        // Store\Adapters\OrderQuoteAdapterInterface     → orders.quote
        'order_placement' => null,    // Store\Adapters\OrderPlacementAdapterInterface → orders.create
        'order_cancellation' => null, // Store\Adapters\OrderCancellationAdapterInterface → orders.cancel
    ],

    /*
     | Optional: a Store\Adapters\StoreCapabilityInterface that can switch
     | capabilities off at runtime (outside business hours, during a stock take).
     */
    'capability_policy' => null,

    /*
     | Capabilities this store never offers, whatever adapters exist.
     | Values from SaasAgent\AgentStore\Contract\Capability.
     */
    'disabled_capabilities' => [],

    /*
     | Who may approve a pairing request on this store. The route runs behind
     | `middleware`, then asks `gate`. The package defines the gate as "deny" if
     | the app has not: approving is impossible until the store decides who may.
     */
    'approval' => [
        'middleware' => ['web', 'auth'],
        'gate' => 'agentstore-approve',
    ],

    'routes' => [
        'prefix' => 'agentstore',
        // Requests per minute from the SaaS, per key.
        'rate_limit' => 600,
    ],

    // Seconds either side of now a signed request's timestamp may be.
    'signature_tolerance' => 300,

    // How long a customer verification stays valid for order lookups, minutes.
    'customer_token_ttl' => 30,

    // After rotation, how long the previous secrets are still accepted, minutes.
    'rotation_grace' => 10,

    'idempotency' => [
        /*
         | Run a write and its idempotency record in one database transaction, so a
         | crash leaves either both or neither. Effective only if the adapter writes
         | through the default connection.
         */
        'transactional' => true,
        'retention_days' => 30,
    ],

    'outbox' => [
        'batch_size' => 50,
        'max_attempts' => 20,
        'retention_days' => 14,
    ],

    // Days of request records kept for `agentstore:doctor` and auditing.
    'request_log_retention_days' => 30,

    'http' => [
        'timeout' => 10,
    ],
];
