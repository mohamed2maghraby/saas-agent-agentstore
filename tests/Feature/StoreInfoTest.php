<?php

namespace SaasAgent\AgentStore\Tests\Feature;

use SaasAgent\AgentStore\Contract\Category;
use SaasAgent\AgentStore\Contract\ContractException;
use SaasAgent\AgentStore\Contract\Location;
use SaasAgent\AgentStore\Contract\Money;
use SaasAgent\AgentStore\Contract\Product;
use SaasAgent\AgentStore\Contract\ShippingEstimate;
use SaasAgent\AgentStore\Contract\ShippingQuery;
use SaasAgent\AgentStore\Store\Adapters\CategoryAdapterInterface;
use SaasAgent\AgentStore\Store\Adapters\LocationAdapterInterface;
use SaasAgent\AgentStore\Store\Adapters\ShippingAdapterInterface;
use SaasAgent\AgentStore\Store\StoreRefusal;
use SaasAgent\AgentStore\Tests\TestCase;

class ManyCategories implements CategoryAdapterInterface
{
    public function categories(): array
    {
        return array_map(fn (int $i) => new Category((string) $i, "Shelf {$i}", $i > 1 ? '1' : null), range(1, 250));
    }
}

class ZoneShipping implements ShippingAdapterInterface
{
    public static ?ShippingQuery $asked = null;

    public function estimate(ShippingQuery $query): ShippingEstimate
    {
        self::$asked = $query;

        return match ($query->city) {
            'Atlantis' => throw StoreRefusal::invalid('The city was not recognised.', ['field' => 'city']),
            'Riyadh' => new ShippingEstimate(true, 'Saudi Arabia', note: 'Priced by weight: quote the order.'),
            'Antarctica' => new ShippingEstimate(false, note: 'We do not ship there.'),
            default => new ShippingEstimate(true, 'Cairo', Money::fromDecimal(60, 'EGP'), Money::fromDecimal(1500, 'EGP'), '2-5 working days'),
        };
    }
}

class Branches implements LocationAdapterInterface
{
    public static ?string $city = 'unset';

    public function locations(?string $city): array
    {
        self::$city = $city;

        return [
            new Location('1', 'City Stars', 'Cairo', 'Nasr City', 'Omar Ibn El Khattab St', ['0222222222']),
            new Location('2', 'San Stefano', 'Alexandria'),
        ];
    }
}

/** categories.list, shipping.estimate, locations.list, and a product's pre-order fields. */
class StoreInfoTest extends TestCase
{
    public function test_categories_are_listed_up_to_two_hundred(): void
    {
        $this->useAdapters(['categories' => ManyCategories::class]);

        $items = $this->signed('GET', '/agentstore/v1/categories')->assertOk()->json('data.items');

        $this->assertCount(200, $items);
        $this->assertEquals(new Category('2', 'Shelf 2', '1'), Category::fromArray($items[1]));
        $this->assertContains('categories.list', $this->signed('GET', '/agentstore/v1/capabilities')->json('data.capabilities'));
    }

    public function test_a_shipping_estimate_gives_the_fee_free_threshold_and_time(): void
    {
        $this->useAdapters(['shipping' => ZoneShipping::class]);

        $estimate = ShippingEstimate::fromArray($this->signed('POST', '/agentstore/v1/shipping/estimate', ['city' => 'Cairo', 'area' => 'Maadi', 'order_value' => 80000])
            ->assertOk()->json('data'));

        $this->assertTrue($estimate->available);
        $this->assertSame(6000, $estimate->fee->amount);
        $this->assertSame(150000, $estimate->freeFrom->amount);
        $this->assertSame('2-5 working days', $estimate->deliveryTime);
        $this->assertEquals(new ShippingQuery('Cairo', 'Maadi', null, 80000), ZoneShipping::$asked);
    }

    public function test_a_place_the_store_cannot_price_or_does_not_reach_says_so(): void
    {
        $this->useAdapters(['shipping' => ZoneShipping::class]);

        $abroad = ShippingEstimate::fromArray($this->signed('POST', '/agentstore/v1/shipping/estimate', ['city' => 'Riyadh', 'country' => 'SA'])->json('data'));
        $this->assertTrue($abroad->available);
        $this->assertNull($abroad->fee);

        $this->assertFalse(ShippingEstimate::fromArray($this->signed('POST', '/agentstore/v1/shipping/estimate', ['city' => 'Antarctica'])->json('data'))->available);

        $this->signed('POST', '/agentstore/v1/shipping/estimate', ['city' => 'Atlantis'])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonPath('error.details.field', 'city');
        $this->signed('POST', '/agentstore/v1/shipping/estimate', ['area' => 'Maadi'])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_locations_are_narrowed_by_city_when_asked(): void
    {
        $this->useAdapters(['locations' => Branches::class]);

        $items = $this->signed('GET', '/agentstore/v1/locations?city=Cairo')->assertOk()->json('data.items');
        $this->assertSame('Cairo', Branches::$city);
        $this->assertEquals(new Location('1', 'City Stars', 'Cairo', 'Nasr City', 'Omar Ibn El Khattab St', ['0222222222']), Location::fromArray($items[0]));

        $this->signed('GET', '/agentstore/v1/locations')->assertOk();
        $this->assertNull(Branches::$city);

        $this->signed('GET', '/agentstore/v1/locations?city='.str_repeat('x', 121))->assertStatus(422);
    }

    public function test_a_store_without_these_adapters_reports_them_unsupported(): void
    {
        $this->useAdapters([]);

        $this->signed('GET', '/agentstore/v1/categories')->assertStatus(501)->assertJsonPath('error.code', 'unsupported_capability');
        $this->signed('POST', '/agentstore/v1/shipping/estimate', ['city' => 'Cairo'])->assertStatus(501);
        $this->signed('GET', '/agentstore/v1/locations')->assertStatus(501);
    }

    public function test_a_pre_order_carries_its_ship_date_and_older_stores_need_not_say(): void
    {
        $variant = [['id' => 'v1', 'title' => 'Lamp']];

        $preorder = Product::fromArray(['id' => 'p1', 'title' => 'Lamp', 'variants' => $variant, 'preorder' => true, 'ships_on' => '2026-12-15']);
        $this->assertTrue($preorder->preorder);
        $this->assertSame('2026-12-15', $preorder->toArray()['ships_on']);
        $this->assertEquals($preorder, Product::fromArray(json_decode(json_encode($preorder->toArray()), true)));

        $older = Product::fromArray(['id' => 'p2', 'title' => 'Mug', 'variants' => $variant]);
        $this->assertFalse($older->preorder);
        $this->assertNull($older->shipsOn);

        foreach (['2026-13-01', '15/12/2026', '2026-12-15T00:00:00+02:00'] as $bad) {
            try {
                Product::fromArray(['id' => 'p3', 'title' => 'x', 'variants' => $variant, 'ships_on' => $bad]);
                $this->fail("{$bad} was accepted");
            } catch (ContractException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
