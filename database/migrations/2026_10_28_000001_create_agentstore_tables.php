<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The package's own tables, prefixed `agentstore_`. It never touches the store's
 * product, order or customer tables — adapters do that, through the store's code.
 *
 * A required date is a dateTime, not a timestamp: stores on MySQL 5.7 or
 * MariaDB (explicit_defaults_for_timestamp off) reject a second required
 * timestamp in a table ("Invalid default value").
 */
return new class extends Migration
{
    public function up(): void
    {
        // The pairing with the SaaS. At most one row is active.
        Schema::create('agentstore_connections', function (Blueprint $table) {
            $table->id();
            $table->string('key_id', 40)->unique();
            $table->text('request_secret');           // encrypted
            $table->text('webhook_secret');           // encrypted
            $table->string('previous_key_id', 40)->nullable();
            $table->text('previous_request_secret')->nullable(); // encrypted; accepted until previous_expires_at
            $table->timestamp('previous_expires_at')->nullable();
            $table->string('saas_url');
            $table->string('tenant_label')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('connected_at');
            $table->timestamp('rotated_at')->nullable();
            $table->timestamps();
        });

        // One row per write the SaaS asked for, keyed by its idempotency key.
        Schema::create('agentstore_idempotency', function (Blueprint $table) {
            $table->id();
            $table->string('operation', 40);
            $table->string('idempotency_key', 191);
            $table->string('request_hash', 64);
            $table->string('status', 16);             // processing, completed
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['operation', 'idempotency_key']);
            $table->index('created_at');
        });

        // Events for the SaaS, written in the store's own transaction and delivered after.
        Schema::create('agentstore_outbox', function (Blueprint $table) {
            $table->id();
            $table->ulid('event_id')->unique();
            $table->string('type', 60);
            $table->json('payload');
            $table->dateTime('occurred_at');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('available_at');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('last_error', 500)->nullable();

            $table->index(['delivered_at', 'failed_at', 'available_at']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('agentstore_requests');
        Schema::dropIfExists('agentstore_outbox');
        Schema::dropIfExists('agentstore_idempotency');
        Schema::dropIfExists('agentstore_pairings');
        Schema::dropIfExists('agentstore_connections');
    }
};
