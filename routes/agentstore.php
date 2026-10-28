<?php

use Illuminate\Support\Facades\Route;
use SaasAgent\AgentStore\Store\Http\Controllers\CatalogController;
use SaasAgent\AgentStore\Store\Http\Controllers\ConnectionController;
use SaasAgent\AgentStore\Store\Http\Controllers\OrderController;
use SaasAgent\AgentStore\Store\Http\Controllers\StatusController;
use SaasAgent\AgentStore\Store\Http\Middleware\RecordRequest;
use SaasAgent\AgentStore\Store\Http\Middleware\VerifySignature;
use SaasAgent\AgentStore\Store\Pairing\PairingController;
use SaasAgent\AgentStore\Store\Pairing\VerifyPairingController;

/*
 | The contract's routes, under `agentstore.routes.prefix` (default /agentstore).
 |
 |   /connect            browser, the store's own admin, behind its login
 |   /v1/pairing/verify  the SaaS completing a pairing, proven by the approval token
 |   /v1/...             everything else, signed by the SaaS (ADR 0010)
 |
 | There is no generic endpoint onto the store's database: each route calls one
 | adapter method the store wrote itself.
 */

Route::prefix(config('agentstore.routes.prefix', 'agentstore'))->name('agentstore.')->group(function () {
    Route::middleware(config('agentstore.approval.middleware', ['web', 'auth']))->group(function () {
        Route::get('connect', [PairingController::class, 'show'])->name('connect');
        Route::post('connect', [PairingController::class, 'approve'])->name('connect.approve');
    });

    Route::post('v1/pairing/verify', VerifyPairingController::class)
        ->middleware('throttle:agentstore-pairing')
        ->name('pairing.verify');

    Route::prefix('v1')->middleware([VerifySignature::class, 'throttle:agentstore', RecordRequest::class])->group(function () {
        Route::get('health', [StatusController::class, 'health'])->name('health');
        Route::get('capabilities', [StatusController::class, 'capabilities'])->name('capabilities');

        Route::get('products', [CatalogController::class, 'search'])->middleware('agentstore.capability:products.search')->name('products.search');
        Route::get('products/{product}', [CatalogController::class, 'show'])->middleware('agentstore.capability:products.read')->name('products.show');
        Route::post('inventory', [CatalogController::class, 'stock'])->middleware('agentstore.capability:inventory.read')->name('inventory');
        Route::post('prices', [CatalogController::class, 'prices'])->middleware('agentstore.capability:prices.read')->name('prices');

        Route::post('customers/verify', [OrderController::class, 'verify'])->middleware('agentstore.capability:customers.verify')->name('customers.verify');
        Route::post('orders/lookup', [OrderController::class, 'lookup'])->middleware('agentstore.capability:orders.read')->name('orders.lookup');
        Route::post('orders/list', [OrderController::class, 'list'])->middleware('agentstore.capability:orders.list')->name('orders.list');
        Route::post('orders', [OrderController::class, 'place'])->middleware('agentstore.capability:orders.create')->name('orders.place');
        Route::post('orders/cancel', [OrderController::class, 'cancel'])->middleware('agentstore.capability:orders.cancel')->name('orders.cancel');
        Route::post('idempotency/lookup', [OrderController::class, 'idempotency'])->name('idempotency.lookup');

        Route::post('credentials/rotate', [ConnectionController::class, 'rotate'])->name('credentials.rotate');
        Route::delete('connection', [ConnectionController::class, 'revoke'])->name('connection.revoke');
    });
});
