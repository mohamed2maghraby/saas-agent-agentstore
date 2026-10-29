<?php

namespace SaasAgent\AgentStore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SaasAgent\AgentStore\Contract\ContractException;
use SaasAgent\AgentStore\Contract\Money;
use SaasAgent\AgentStore\Contract\Offer;
use SaasAgent\AgentStore\Contract\OrderQuote;
use SaasAgent\AgentStore\Contract\OrderQuoteRequest;
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

    public function test_a_quote_and_an_offer_round_trip_through_json(): void
    {
        $quote = [
            'lines' => [
                ['variant_id' => 'v1', 'title' => 'Mug', 'quantity' => 2, 'total' => ['amount' => 50000, 'currency' => 'EGP'], 'list_total' => ['amount' => 59000, 'currency' => 'EGP'], 'gift' => false],
                ['variant_id' => 'v9', 'title' => 'Card', 'quantity' => 1, 'total' => ['amount' => 0, 'currency' => 'EGP'], 'list_total' => null, 'gift' => true],
            ],
            'subtotal' => ['amount' => 50000, 'currency' => 'EGP'],
            'discount' => null,
            'delivery' => null,
            'fees' => ['amount' => 1000, 'currency' => 'EGP'],
            'total' => ['amount' => 51000, 'currency' => 'EGP'],
            'delivery_estimate' => '2-5 days',
            'offers' => ['Free shipping'],
            'required_fields' => ['customer_email', 'address.area'],
            'notes' => [],
        ];
        $offer = ['id' => 'o1', 'title' => 'Free shipping', 'description' => 'Over 500', 'ends_at' => '2026-10-31T23:59:59+02:00'];

        $this->assertSame($quote, json_decode(json_encode(OrderQuote::fromArray($quote)->toArray()), true));
        $this->assertSame($offer, Offer::fromArray($offer)->toArray());
        $this->assertNull(OrderQuote::fromArray($quote)->delivery, 'unknown delivery stays unknown, not free');
    }

    public function test_a_quote_names_only_known_fields_and_has_lines(): void
    {
        $base = ['lines' => [['variant_id' => 'v1', 'title' => 'Mug', 'quantity' => 1, 'total' => ['amount' => 1, 'currency' => 'EGP']]], 'subtotal' => ['amount' => 1, 'currency' => 'EGP'], 'total' => ['amount' => 1, 'currency' => 'EGP']];

        foreach ([['required_fields' => ['password']], ['lines' => []], ['notes' => [str_repeat('x', 501)]]] as $bad) {
            try {
                OrderQuote::fromArray(array_replace($base, $bad));
                $this->fail('Accepted '.json_encode($bad));
            } catch (ContractException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(ContractException::class);
        OrderQuoteRequest::fromArray(['items' => [], 'payment_method' => 'cod']);
    }
}
