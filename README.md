# saas-agent/agentstore

Connects a Laravel store to SaaS Agent. The store keeps its own database, models
and checkout; this package exposes a small, versioned, signed API that calls
**adapters the store writes itself**, one method per operation. There is no
generic endpoint onto the store's data, and the SaaS cannot run queries or code
on the store.

- PHP 8.2+, Laravel 12 or 13.
- Adds `agentstore_*` tables only. Touches nothing else in the store's schema.

## Install

```bash
composer require saas-agent/agentstore
php artisan agentstore:install    # publishes config/agentstore.php, prints next steps
php artisan migrate
```

In `.env`:

```dotenv
AGENTSTORE_SAAS_URL=https://your-saas-agent-address
```

Pairing requests are fetched from that address and only from it.

The SaaS knows the store by `APP_URL`. If that is not the address the SaaS
reaches the store at — a local copy whose `APP_URL` still names production, or
a store behind a different public host — set it explicitly:

```dotenv
AGENTSTORE_STORE_URL=https://shop.example.com
```

It must be exactly the address entered in SaaS Agent when connecting; a
pairing request for any other address is refused.

Decide who may approve a connection, e.g. in `AppServiceProvider::boot()`:

```php
Gate::define('agentstore-approve', fn ($user) => $user->isAdmin());
```

Until you define it, the package treats the gate as "deny" and nobody can
approve. The approval page runs behind `agentstore.approval.middleware`
(default `['web', 'auth']`); change it if your admin area uses another guard.

The scheduler must run (`php artisan schedule:run` every minute): it delivers
outbox events (`agentstore:deliver`) and prunes old records (`agentstore:prune`).

## Adapters

Each capability group maps to one interface in
`SaasAgent\AgentStore\Store\Adapters`. Implement the ones your store supports
and list them in `config/agentstore.php`:

| Config key | Interface | Capabilities |
|---|---|---|
| `products` | `ProductAdapterInterface` | `products.search`, `products.read` |
| `inventory` | `InventoryAdapterInterface` | `inventory.read` |
| `pricing` | `PricingAdapterInterface` | `prices.read` |
| `offers` | `OfferAdapterInterface` | `offers.list` |
| `customers` | `CustomerAdapterInterface` | `customers.verify` |
| `orders` | `OrderAdapterInterface` | `orders.read`, `orders.list` |
| `order_quote` | `OrderQuoteAdapterInterface` | `orders.quote` — prices an order through your checkout without placing it |
| `order_placement` | `OrderPlacementAdapterInterface` | `orders.create` |
| `order_cancellation` | `OrderCancellationAdapterInterface` | `orders.cancel` |

A group left `null` is reported as unsupported and the SaaS will not offer it.
Write capabilities (`orders.create`, `orders.cancel`) also start disabled on the
SaaS side until the tenant turns them on. `disabled_capabilities` and
`capability_policy` let the store switch capabilities off without removing code.

Adapters are resolved from the container, so inject your own services:

```php
use SaasAgent\AgentStore\Contract\{Money, Product, ProductPage, ProductQuery, StockStatus, Variant};
use SaasAgent\AgentStore\Store\Adapters\ProductAdapterInterface;

final class CatalogAdapter implements ProductAdapterInterface
{
    public function search(ProductQuery $query): ProductPage
    {
        $page = \App\Models\Product::query()
            ->where('is_published', true)              // only what a shopper may see
            ->when($query->text, fn ($q, $text) => $q->where('name', 'like', "%{$text}%"))
            ->cursorPaginate($query->limit, cursor: $query->cursor);

        return new ProductPage(
            $page->getCollection()->map($this->map(...))->all(),
            $page->nextCursor()?->encode(),
        );
    }

    public function find(string $productId): ?Product
    {
        $product = \App\Models\Product::where('is_published', true)->find($productId);

        return $product ? $this->map($product) : null;
    }

    private function map(\App\Models\Product $product): Product
    {
        return new Product(
            id: (string) $product->id,
            title: $product->name,
            variants: [new Variant(
                id: (string) $product->id,
                title: $product->name,
                price: Money::fromDecimal($product->price, 'EGP'),
                stock: $product->stock > 0 ? StockStatus::InStock : StockStatus::OutOfStock,
            )],
            url: route('products.show', $product),
        );
    }
}
```

Rules every adapter follows:

- **Money is integer minor units** (`Money::fromDecimal('149.50', 'EGP')`). Never floats.
- **Live figures.** Inventory and prices come from what the store's own checkout
  would use, including safety stock, discounts and delivery fees.
- **Unknown ids are left out**, not reported as out of stock or zero.
- **Refuse with `StoreRefusal`** (with an `ErrorCode`) when the store says no — out
  of stock, invalid address — so the SaaS can tell the customer why. Any other
  exception becomes a generic `store_error`; its message is not sent.
- **Writes are idempotent by key.** The package records each write's
  idempotency key and replays the stored result on a retry; with
  `idempotency.transactional` on, the write and its record commit together.
- **Text is data.** Product descriptions are shown to customers and read by the
  model as content, never as instructions.

## Events

Tell the SaaS when something changes, from wherever your store already reacts:

```php
use SaasAgent\AgentStore\AgentStore;

DB::transaction(function () use ($variant) {
    $variant->update(['stock' => $variant->stock - 1]);
    AgentStore::publish('stock.changed', ['variant_id' => (string) $variant->id]);
});
```

Events are written to an outbox table — called inside your transaction, as
above, they commit or roll back with your change — and delivered at least once,
signed, with backoff. The SaaS deduplicates by event id. Send ids and states,
not whole documents. Types are listed in `Store\Outbox\Outbox::TYPES`.

## Connecting

1. In SaaS Agent, a tenant admin adds the store's address. Their browser lands
   on `https://your-store/agentstore/connect?pairing=…`.
2. A store admin (per your gate) reviews which tenant is asking and for which
   capabilities, and approves.
3. The two servers finish the exchange directly. Credentials are generated by
   the SaaS, sent only server-to-server, stored encrypted on both sides, and
   never shown in a browser. See `docs/adr/0009-store-pairing.md`.

Every later request is HMAC-signed with a timestamp and a single-use nonce
(`docs/adr/0010-request-signing.md`). Requests and webhooks use separate secrets.

## Commands

| Command | What |
|---|---|
| `agentstore:install` | publish config, print the steps above |
| `agentstore:doctor` | check config, migrations, adapters, gate, connection, outbox, SaaS reachability (`--offline` to skip the last); exits non-zero on failure |
| `agentstore:deliver` | send pending outbox events (scheduled every minute) |
| `agentstore:prune` | delete old idempotency, outbox and request records (scheduled daily) |
| `agentstore:disconnect` | revoke the connection from the store side and notify the SaaS |

Rotating credentials is started from the SaaS dashboard; the previous secrets
stay valid for `rotation_grace` minutes so in-flight requests do not fail.

## Testing your adapters

Extend `SaasAgent\AgentStore\Tests\Conformance\AdapterConformanceTestCase` in
your own test suite and describe your test data (a searchable term, a known
product id, …). Every check runs through the real signed endpoints and parses
responses with the same DTOs the SaaS uses; capabilities you do not offer are
skipped. `tests/Conformance/StoreAConformanceTest.php` and
`StoreBConformanceTest.php` are two worked examples over deliberately different
schemas.

Package development:

```bash
composer install
./vendor/bin/phpunit
```
