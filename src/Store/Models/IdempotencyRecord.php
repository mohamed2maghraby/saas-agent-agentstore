<?php

namespace SaasAgent\AgentStore\Store\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyRecord extends Model
{
    public const PROCESSING = 'processing';

    public const COMPLETED = 'completed';

    protected $table = 'agentstore_idempotency';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }
}
