<?php

namespace SaasAgent\AgentStore\Tests\Feature;

use SaasAgent\AgentStore\Contract\Money;
use SaasAgent\AgentStore\Contract\Offer;
use SaasAgent\AgentStore\Contract\OrderQuote;
use SaasAgent\AgentStore\Contract\OrderQuoteRequest;
use SaasAgent\AgentStore\Contract\QuoteLine;
use SaasAgent\AgentStore\Store\Adapters\OfferAdapterInterface;
use SaasAgent\AgentStore\Store\Adapters\OrderQuoteAdapterInterface;
use SaasAgent\AgentStore\Store\StoreRefusal;
use SaasAgent\AgentStore\Tests\TestCase;

class FixedQuote implements OrderQuoteAdapterInterface
{
    public static ?OrderQuoteRequest $asked = null;

    public function quote(OrderQuoteRequest $request): OrderQuote
    {
        self::$asked = $request;

        if (($request->address['city'] ?? '') === 'Atlantis') {
            throw StoreRefusal::invalid('We do not deliver there.', ['field' => 'address.city']);
        }

        return new OrderQuote(
            lines: [
                new QuoteLine('v1', 'Mug', 2, Money::fromDecimal(500, 'EGP'), Money::fromDecimal(590, 'EGP')),
                new QuoteLine('v9', 'Gift card', 1, new Money(0, 'EGP'), gift: true),
            ],
            subtotal: Money::fromDecimal(500, 'EGP'),
            total: Money::fromDecimal(560, 'EGP'),
            delivery: Money::fromDecimal(50, 'EGP'),
            fees: Money::fromDecimal(10, 'EGP'),
            deliveryEstimate: '2-5 working days',
            offers: ['Two mugs for 500'],
            requiredFields: ['customer_name', 'customer_phone', 'customer_email'],
        );
    }
}

class ManyOffers implements OfferAdapterInterface
{
    public function running(): array
    {
        return array_map(fn (int $i) => new Offer((string) $i, "Offer {$i}"), range(1, 60));
    }
}

class QuoteAndOffersTest extends TestCase
{
    public function test_a_quote_is_priced_by_the_store_and_needs_no_idempotency_key(): void
    {
        $this->useAdapters(['order_quote' => FixedQuote::class]);

        $response = $this->signed('POST', '/agentstore/v1/orders/quote', [
            'items' => [['variant_id' => 'v1', 'quantity' => 2]],
            'address' => ['city' => 'Cairo'],
            'payment_method' => 'cod',
        ])->assertOk();

        $quote = OrderQuote::fromArray($response->json('data'));
        $this->assertSame(56000, $quote->total->amount);
        $this->assertTrue($quote->lines[1]->gift);
        $this->assertSame(['Two mugs for 500'], $quote->offers);
        $this->assertSame('Cairo', FixedQuote::$asked->address['city']);
        $this->assertContains('orders.quote', $this->signed('GET', '/agentstore/v1/capabilities')->json('data.capabilities'));
    }

    public function test_a_quote_refusal_reaches_the_saas_with_its_reason(): void
    {
        $this->useAdapters(['order_quote' => FixedQuote::class]);

        $this->signed('POST', '/agentstore/v1/orders/quote', [
            'items' => [['variant_id' => 'v1', 'quantity' => 1]],
            'address' => ['city' => 'Atlantis'],
            'payment_method' => 'cod',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.field', 'address.city');
    }

    public function test_quote_and_offers_are_unsupported_without_their_adapters(): void
    {
        $this->useAdapters([]);

        $this->signed('POST', '/agentstore/v1/orders/quote', ['items' => [['variant_id' => 'v1', 'quantity' => 1]], 'payment_method' => 'cod'])->assertStatus(501);
        $this->signed('GET', '/agentstore/v1/offers')->assertStatus(501);
    }

    public function test_offers_are_listed_and_capped(): void
    {
        $this->useAdapters(['offers' => ManyOffers::class]);

        $items = $this->signed('GET', '/agentstore/v1/offers')->assertOk()->json('data.items');

        $this->assertCount(50, $items);
        $this->assertSame('Offer 1', Offer::fromArray($items[0])->title);
    }
}
