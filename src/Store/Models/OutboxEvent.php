<?php

namespace SaasAgent\AgentStore\Store\Models;

use Illuminate\Database\Eloquent\Model;

class OutboxEvent extends Model
{
    public $timestamps = false;

    protected $table = 'agentstore_outbox';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'available_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
