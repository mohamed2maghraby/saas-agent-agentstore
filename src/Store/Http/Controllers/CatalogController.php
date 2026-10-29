<?php

namespace SaasAgent\AgentStore\Store\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SaasAgent\AgentStore\Contract\ErrorCode;
use SaasAgent\AgentStore\Contract\Offer;
use SaasAgent\AgentStore\Contract\Price;
use SaasAgent\AgentStore\Contract\ProductQuery;
use SaasAgent\AgentStore\Contract\StockLevel;
use SaasAgent\AgentStore\Store\Adapters\InventoryAdapterInterface;
use SaasAgent\AgentStore\Store\Adapters\OfferAdapterInterface;
use SaasAgent\AgentStore\Store\Adapters\PricingAdapterInterface;
use SaasAgent\AgentStore\Store\Adapters\ProductAdapterInterface;
use SaasAgent\AgentStore\Store\Capabilities;
use SaasAgent\AgentStore\Store\Http\ApiError;

/** Products, stock, prices and offers — read straight from the store, never cached here. */
final class CatalogController extends ApiController
{
    public function __construct(private readonly Capabilities $capabilities) {}

    public function search(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $query = $request->query->all();
            foreach (['max_price', 'limit'] as $int) {
                if (isset($query[$int]) && ctype_digit((string) $query[$int])) {
                    $query[$int] = (int) $query[$int];
                }
            }
            $query['in_stock_only'] = filter_var($query['in_stock_only'] ?? false, FILTER_VALIDATE_BOOL);

            return $this->products()->search(ProductQuery::fromArray($query))->toArray();
        });
    }

    public function show(string $product): JsonResponse
    {
        return $this->respond(function () use ($product) {
            if (strlen($product) > 191) {
                return ApiError::response(ErrorCode::NotFound);
            }

            $found = $this->products()->find($product);

            return $found ? $found->toArray() : ApiError::response(ErrorCode::NotFound);
        });
    }

    public function stock(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $levels = $this->capabilities->adapter('inventory', InventoryAdapterInterface::class)
                ->stock($this->ids($this->body($request)));

            return ['items' => array_map(fn (StockLevel $level) => $level->toArray(), $levels)];
        });
    }

    public function prices(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $prices = $this->capabilities->adapter('pricing', PricingAdapterInterface::class)
                ->prices($this->ids($this->body($request)));

            return ['items' => array_map(fn (Price $price) => $price->toArray(), $prices)];
        });
    }

    public function offers(): JsonResponse
    {
        return $this->respond(function () {
            $offers = $this->capabilities->adapter('offers', OfferAdapterInterface::class)->running();

            return ['items' => array_map(fn (Offer $offer) => $offer->toArray(), array_slice($offers, 0, 50))];
        });
    }

    private function products(): ProductAdapterInterface
    {
        return $this->capabilities->adapter('products', ProductAdapterInterface::class);
    }
}
