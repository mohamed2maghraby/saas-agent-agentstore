<?php

namespace SaasAgent\AgentStore\Tests\Fixtures\StoreB;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Example store B — "Craft".
 *
 * Deliberately unlike store A:
 * - `items` with options as variants, uuid keys, prices in piastres;
 * - stock is not a column but a ledger, read through InventoryService, which
 *   holds back a safety stock;
 * - orders are `sales` with numeric state codes, owned by a `customers` row;
 * - placing an order goes through the store's Checkout service, which applies a
 *   bundle discount and a delivery fee the adapter knows nothing about.
 */
final class StoreB
{
    public static function migrate(): void
    {
        Schema::create('b_customers', function (Blueprint $t) {
            $t->id();
            $t->string('full_name');
            $t->string('mobile')->unique();
            $t->string('email')->nullable();
        });

        Schema::create('b_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title');
            $t->string('collection');
            $t->boolean('listed')->default(true);
            $t->timestamps();
        });

        Schema::create('b_item_options', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('item_id');
            $t->string('sku')->unique();
            $t->string('label');
            $t->integer('price_piastres');
        });

        Schema::create('b_stock_moves', function (Blueprint $t) {
            $t->id();
            $t->uuid('option_id');
            $t->integer('delta');
            $t->string('reason');
            $t->timestamps();
        });

        Schema::create('b_sales', function (Blueprint $t) {
            $t->id();
            $t->string('ref')->unique();
            $t->foreignId('customer_id');
            $t->unsignedTinyInteger('state');
            $t->integer('grand_total_piastres');
            $t->timestamps();
        });

        Schema::create('b_sale_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_id');
            $t->uuid('option_id');
            $t->integer('quantity');
            $t->integer('unit_piastres');
        });
    }
}

class BCustomer extends Model
{
    public $timestamps = false;

    protected $table = 'b_customers';

    protected $guarded = [];
}

class BItem extends Model
{
    use HasUuids;

    protected $table = 'b_items';

    protected $guarded = [];

    protected $casts = ['listed' => 'boolean'];

    public function options(): HasMany
    {
        return $this->hasMany(BItemOption::class, 'item_id');
    }
}

class BItemOption extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'b_item_options';

    protected $guarded = [];

    public function item(): BelongsTo
    {
        return $this->belongsTo(BItem::class, 'item_id');
    }
}

class BSale extends Model
{
    protected $table = 'b_sales';

    protected $guarded = [];

    public function lines(): HasMany
    {
        return $this->hasMany(BSaleLine::class, 'sale_id');
    }
}

class BSaleLine extends Model
{
    public $timestamps = false;

    protected $table = 'b_sale_lines';

    protected $guarded = [];
}

/** Store B's own stock service: on hand from the ledger, minus a safety stock. */
class InventoryService
{
    public const SAFETY_STOCK = 2;

    /** @return array<string, int> option id => sellable quantity */
    public function sellable(array $optionIds): array
    {
        return DB::table('b_stock_moves')
            ->whereIn('option_id', $optionIds)
            ->groupBy('option_id')
            ->selectRaw('option_id, sum(delta) as on_hand')
            ->pluck('on_hand', 'option_id')
            ->map(fn ($onHand) => max(0, (int) $onHand - self::SAFETY_STOCK))
            ->all();
    }

    public function move(string $optionId, int $delta, string $reason): void
    {
        DB::table('b_stock_moves')->insert(['option_id' => $optionId, 'delta' => $delta, 'reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
    }
}

/** Store B's checkout: 10% off three or more pieces, 50 EGP delivery outside Cairo. */
class Checkout
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * @param  list<array{option_id: string, quantity: int}>  $lines
     * @return array{sale: BSale, total: int}
     */
    public function placeSale(BCustomer $customer, array $lines, string $city): array
    {
        return DB::transaction(function () use ($customer, $lines, $city) {
            $options = BItemOption::query()->whereKey(array_column($lines, 'option_id'))->lockForUpdate()->get()->keyBy('id');
            $available = $this->inventory->sellable(array_column($lines, 'option_id'));

            $subtotal = 0;
            $pieces = 0;
            foreach ($lines as $line) {
                $option = $options[$line['option_id']] ?? throw new \DomainException('unknown_option:'.$line['option_id']);
                if (($available[$option->id] ?? 0) < $line['quantity']) {
                    throw new \DomainException('insufficient_stock:'.$option->id);
                }
                $subtotal += $option->price_piastres * $line['quantity'];
                $pieces += $line['quantity'];
            }

            $discount = $pieces >= 3 ? intdiv($subtotal, 10) : 0;
            $delivery = strtolower($city) === 'cairo' ? 0 : 5000;
            $total = $subtotal - $discount + $delivery;

            $sale = BSale::query()->create([
                'ref' => 'CR-'.strtoupper(bin2hex(random_bytes(3))),
                'customer_id' => $customer->id,
                'state' => 1,
                'grand_total_piastres' => $total,
            ]);

            foreach ($lines as $line) {
                $option = $options[$line['option_id']];
                $sale->lines()->create(['option_id' => $option->id, 'quantity' => $line['quantity'], 'unit_piastres' => $option->price_piastres]);
                $this->inventory->move($option->id, -$line['quantity'], 'sale:'.$sale->ref);
            }

            return ['sale' => $sale, 'total' => $total];
        });
    }
}
