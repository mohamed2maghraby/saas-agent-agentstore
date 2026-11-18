<?php

namespace SaasAgent\AgentStore\Store\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SaasAgent\AgentStore\Contract\Category;
use SaasAgent\AgentStore\Contract\ContractException;
use SaasAgent\AgentStore\Contract\Location;
use SaasAgent\AgentStore\Contract\ShippingQuery;
use SaasAgent\AgentStore\Store\Adapters\CategoryAdapterInterface;
use SaasAgent\AgentStore\Store\Adapters\LocationAdapterInterface;
use SaasAgent\AgentStore\Store\Adapters\ShippingAdapterInterface;
use SaasAgent\AgentStore\Store\Capabilities;

/** The store's categories, delivery and places to buy — read live, never cached here. */
final class StoreInfoController extends ApiController
{
    public function __construct(private readonly Capabilities $capabilities) {}

    public function categories(): JsonResponse
    {
        return $this->respond(function () {
            $categories = $this->capabilities->adapter('categories', CategoryAdapterInterface::class)->categories();

            return ['items' => array_map(fn (Category $category) => $category->toArray(), array_slice($categories, 0, 200))];
        });
    }

    public function shipping(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $query = ShippingQuery::fromArray($this->body($request));

            return $this->capabilities->adapter('shipping', ShippingAdapterInterface::class)->estimate($query)->toArray();
        });
    }

    public function locations(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $city = $request->query('city');

            if ($city !== null && (! is_string($city) || mb_strlen($city) > 120)) {
                throw ContractException::field('city', 'a string of at most 120 characters');
            }

            $city = is_string($city) && trim($city) !== '' ? trim($city) : null;
            $locations = $this->capabilities->adapter('locations', LocationAdapterInterface::class)->locations($city);

            return ['items' => array_map(fn (Location $location) => $location->toArray(), array_slice($locations, 0, 100))];
        });
    }
}
