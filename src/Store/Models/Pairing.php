<?php

namespace SaasAgent\AgentStore\Store\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An approval given by a store admin and waiting for the SaaS to complete it.
 * Holds only the hash of the approval token.
 */
class Pairing extends Model
{
    protected $table = 'agentstore_pairings';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->completed_at === null && $this->expires_at->isFuture();
    }
}
