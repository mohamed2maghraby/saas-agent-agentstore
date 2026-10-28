<?php

namespace SaasAgent\AgentStore\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use SaasAgent\AgentStore\AgentStore;
use SaasAgent\AgentStore\Security\Signature;
use SaasAgent\AgentStore\Store\Models\OutboxEvent;
use SaasAgent\AgentStore\Store\Outbox\OutboxDelivery;
use SaasAgent\AgentStore\Tests\TestCase;

class OutboxTest extends TestCase
{
    public function test_an_event_exists_only_if_its_transaction_commits(): void
    {
        try {
            DB::transaction(function () {
                AgentStore::publish('order.status_changed', ['order_id' => 'o1', 'state' => 'shipped']);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, OutboxEvent::query()->count());

        DB::transaction(fn () => AgentStore::publish('order.status_changed', ['order_id' => 'o1', 'state' => 'shipped']));
        $this->assertSame(1, OutboxEvent::query()->count());
    }

    public function test_unknown_types_and_oversized_payloads_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AgentStore::publish('orders.deleted_everything', []);
    }

    public function test_payloads_are_bounded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AgentStore::publish('product.updated', ['blob' => str_repeat('x', 70000)]);
    }

    public function test_delivery_is_signed_with_the_webhook_secret(): void
    {
        $credentials = $this->pair();
        $id = AgentStore::publish('stock.changed', ['variant_id' => 'a-1']);
        Http::fake(['https://saas.test/api/agentstore/v1/events' => Http::response(['accepted' => [$id]])]);

        $result = app(OutboxDelivery::class)->deliver();

        $this->assertSame(1, $result['delivered']);
        $this->assertNotNull(OutboxEvent::query()->sole()->delivered_at);

        Http::assertSent(function (Request $request) use ($credentials, $id) {
            $canonical = Signature::canonical('POST', '/api/agentstore/v1/events', [], (int) $request->header(Signature::TIMESTAMP_HEADER)[0], $request->header(Signature::NONCE_HEADER)[0], $request->body());

            return $request->header(Signature::KEY_HEADER)[0] === $credentials->keyId
                && Signature::matches($credentials->webhookSecret, $canonical, $request->header(Signature::SIGNATURE_HEADER)[0])
                // Never signed with the request secret: the two directions use different keys.
                && ! Signature::matches($credentials->requestSecret, $canonical, $request->header(Signature::SIGNATURE_HEADER)[0])
                && $request['events'][0]['id'] === $id;
        });
    }

    public function test_events_the_saas_did_not_accept_are_retried_with_backoff(): void
    {
        $this->pair();
        AgentStore::publish('stock.changed', ['variant_id' => 'a-1']);
        Http::fake(['https://saas.test/*' => Http::response(['message' => 'down'], 503)]);

        app(OutboxDelivery::class)->deliver();

        $event = OutboxEvent::query()->sole();
        $this->assertNull($event->delivered_at);
        $this->assertSame(1, $event->attempts);
        $this->assertTrue($event->available_at->isFuture());

        // Not picked up again before its back-off ends.
        $this->assertSame(0, app(OutboxDelivery::class)->deliver()['failed']);
    }

    public function test_an_event_gives_up_after_the_maximum_attempts(): void
    {
        $this->pair();
        config(['agentstore.outbox.max_attempts' => 2]);
        AgentStore::publish('stock.changed', ['variant_id' => 'a-1']);
        Http::fake(['https://saas.test/*' => Http::response([], 500)]);

        app(OutboxDelivery::class)->deliver();
        $this->travel(2)->minutes();
        app(OutboxDelivery::class)->deliver();

        $this->assertNotNull(OutboxEvent::query()->sole()->failed_at);
    }

    public function test_nothing_is_sent_while_unpaired(): void
    {
        AgentStore::publish('stock.changed', ['variant_id' => 'a-1']);
        Http::fake();

        $this->assertTrue(app(OutboxDelivery::class)->deliver()['skipped']);
        Http::assertNothingSent();
    }
}
