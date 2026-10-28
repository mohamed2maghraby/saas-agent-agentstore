<?php

namespace SaasAgent\AgentStore\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Testbench;
use SaasAgent\AgentStore\AgentStoreServiceProvider;
use SaasAgent\AgentStore\Security\Credentials;
use SaasAgent\AgentStore\Security\Signature;
use SaasAgent\AgentStore\Store\Models\Connection;

abstract class TestCase extends Testbench
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [AgentStoreServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('app.url', 'https://shop.test');
        $app['config']->set('app.name', 'Test Shop');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('agentstore.saas_url', 'https://saas.test');
    }

    /** Pair this store with fresh credentials, as a completed pairing would. */
    protected function pair(): Credentials
    {
        $credentials = Credentials::generate();
        Connection::establish($credentials, 'https://saas.test', 'Test Tenant', null);

        return $credentials;
    }

    /**
     * A request signed exactly as the SaaS signs it.
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $override  headers to replace after signing (for tamper tests)
     */
    protected function signed(string $method, string $uri, ?array $body = null, ?Credentials $credentials = null, array $override = [], ?int $timestamp = null): TestResponse
    {
        $credentials ??= $this->pair();
        $content = $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR);

        $path = (string) parse_url($uri, PHP_URL_PATH);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        $headers = Signature::headers($credentials->keyId, $credentials->requestSecret, $method, $path, $query, $content, $timestamp);

        // call() ignores withHeaders(), so headers go in as server variables.
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach (array_merge($headers, $override) as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call($method, $uri, [], [], [], $server, $content);
    }

    /** @param list<string>|null $only adapter groups to keep; null keeps all */
    protected function useAdapters(array $adapters): void
    {
        config(['agentstore.adapters' => array_merge(array_fill_keys(array_keys(config('agentstore.adapters')), null), $adapters)]);
    }
}
