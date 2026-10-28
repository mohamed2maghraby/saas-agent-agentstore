<?php

namespace SaasAgent\AgentStore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SaasAgent\AgentStore\Contract\ContractException;
use SaasAgent\AgentStore\Contract\Money;
use SaasAgent\AgentStore\Contract\OrderStatus;
use SaasAgent\AgentStore\Contract\Product;
use SaasAgent\AgentStore\Contract\ProductQuery;
use SaasAgent\AgentStore\Security\Credentials;

/** The DTOs are how the SaaS reads untrusted store responses, so they must reject bad data. */
class ContractTest extends TestCase
{
    public function test_money_is_integer_minor_units(): void
    {
        $this->assertSame(57000, Money::fromDecimal('570.00', 'EGP')->amount);
        $this->assertSame(1755, Money::fromDecimal(17.545, 'EGP')->amount, 'rounds half up');
        $this->assertSame(['amount' => 100, 'currency' => 'EGP'], Money::fromArray(['amount' => 100, 'currency' => 'EGP'])->toArray());
    }

    public function test_money_rejects_floats_negatives_and_bad_currencies(): void
    {
        foreach ([['amount' => 1.5, 'currency' => 'EGP'], ['amount' => -1, 'currency' => 'EGP'], ['amount' => 1, 'currency' => 'egp'], ['amount' => 1]] as $bad) {
            try {
                Money::fromArray($bad);
                $this->fail('Accepted '.json_encode($bad));
            } catch (ContractException|\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_product_round_trips_through_json(): void
    {
        $data = [
            'id' => 'p1', 'title' => 'Mug', 'description' => 'A mug', 'url' => 'https://shop.test/p1',
            'images' => ['https://shop.test/p1.jpg'], 'categories' => ['mugs'], 'attributes' => ['Volume' => '375 ml'],
            'variants' => [['id' => 'v1', 'title' => 'Blue', 'sku' => 'M-B', 'options' => ['Colour' => 'Blue'], 'price' => ['amount' => 54000, 'currency' => 'EGP'], 'stock' => 'in_stock']],
        ];

        $json = json_decode(json_encode(Product::fromArray($data)->toArray()), true);

        $this->assertEquals(Product::fromArray($data), Product::fromArray($json));
    }

    public function test_untrusted_responses_are_bounded(): void
    {
        $this->expectException(ContractException::class);

        Product::fromArray(['id' => 'p1', 'title' => str_repeat('x', 501), 'variants' => [['id' => 'v', 'title' => 'v']]]);
    }

    public function test_a_product_needs_a_variant_and_an_order_a_known_state(): void
    {
        try {
            Product::fromArray(['id' => 'p1', 'title' => 'x', 'variants' => []]);
            $this->fail('Accepted a product without variants');
        } catch (ContractException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(ContractException::class);
        OrderStatus::fromArray(['id' => '1', 'number' => '1', 'state' => 'teleported', 'store_status' => 'x', 'total' => ['amount' => 1, 'currency' => 'EGP'], 'lines' => []]);
    }

    public function test_a_query_limit_is_bounded(): void
    {
        $this->expectException(ContractException::class);

        ProductQuery::fromArray(['limit' => 51]);
    }

    public function test_generated_credentials_are_strong_and_never_dumped(): void
    {
        $credentials = Credentials::generate();

        $this->assertMatchesRegularExpression('/\Aak_[a-f0-9]{24}\z/', $credentials->keyId);
        $this->assertGreaterThanOrEqual(43, strlen($credentials->requestSecret));
        $this->assertNotSame($credentials->requestSecret, $credentials->webhookSecret);
        $this->assertStringNotContainsString($credentials->requestSecret, print_r($credentials, true));
    }
}
