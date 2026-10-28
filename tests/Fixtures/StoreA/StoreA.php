<?php

namespace SaasAgent\AgentStore\Tests\Fixtures\StoreA;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Example store A — "Classic".
 *
 * The shape many small Laravel shops have: one `products` table with a decimal
 * price, an optional sale price and a `stock_quantity` column; `orders` with
 * free-text statuses; customers identified by the phone on the order. No
 * variants: each product is its own single variant.
 */
final class StoreA
{
    public static function migrate(): void
    {
        Schema::create('a_products', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('category')->nullable();
            $t->decimal('price', 10, 2);
            $t->decimal('sale_price', 10, 2)->nullable();
            $t->integer('stock_quantity')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('a_orders', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->string('customer_name');
            $t->string('customer_phone');
            $t->string('status');
            $t->decimal('total', 10, 2);
            $t->string('city');
            $t->timestamps();
        });

        Schema::create('a_order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id');
            $t->foreignId('product_id');
            $t->integer('qty');
            $t->decimal('unit_price', 10, 2);
        });
    }
}

class AProduct extends Model
{
    protected $table = 'a_products';

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];
}

class AOrder extends Model
{
    protected $table = 'a_orders';

    protected $guarded = [];

    public function items(): HasMany
    {
        return $this->hasMany(AOrderItem::class, 'order_id');
    }
}

class AOrderItem extends Model
{
    public $timestamps = false;

    protected $table = 'a_order_items';

    protected $guarded = [];
}
