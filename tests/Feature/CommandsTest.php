<?php

namespace SaasAgent\AgentStore\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use SaasAgent\AgentStore\Tests\Fixtures\StoreA\CatalogAdapter;
use SaasAgent\AgentStore\Tests\TestCase;

class CommandsTest extends TestCase
{
    public function test_doctor_fails_until_the_store_is_set_up(): void
    {
        $this->artisan('agentstore:doctor', ['--offline' => true])->assertFailed();
    }

    public function test_doctor_passes_for_a_configured_store(): void
    {
        $this->useAdapters(['products' => CatalogAdapter::class]);
        Gate::define('agentstore-approve', fn () => true);

        $this->artisan('agentstore:doctor', ['--offline' => true])
            ->expectsOutputToContain('products.search')
            ->assertSuccessful();
    }

    public function test_doctor_catches_an_adapter_that_does_not_implement_its_interface(): void
    {
        $this->useAdapters(['inventory' => \stdClass::class]);
        Gate::define('agentstore-approve', fn () => true);

        $this->artisan('agentstore:doctor', ['--offline' => true])->assertFailed();
    }

    public function test_delivery_and_pruning_are_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())->pluck('command')->implode(' ');

        $this->assertStringContainsString('agentstore:deliver', $commands);
        $this->assertStringContainsString('agentstore:prune', $commands);
    }
}
