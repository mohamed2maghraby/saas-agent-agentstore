<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * 0.3.0: approvals waiting for the SaaS live in the cache (PendingPairings),
 * and calls from the SaaS are no longer recorded. Stores that ran 0.2 had both
 * tables; new installs never create them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('agentstore_requests');
        Schema::dropIfExists('agentstore_pairings');
    }

    public function down(): void
    {
        // Nothing reads them any more.
    }
};
