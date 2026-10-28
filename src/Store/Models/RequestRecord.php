<?php

namespace SaasAgent\AgentStore\Store\Models;

use Illuminate\Database\Eloquent\Model;

class RequestRecord extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'agentstore_requests';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
