<?php

namespace SaasAgent\AgentStore\Store\Models;

use Illuminate\Database\Eloquent\Model;
use SaasAgent\AgentStore\Security\Credentials;

/**
 * This store's pairing with the SaaS. Secrets are encrypted with the store's
 * APP_KEY and hidden from serialisation.
 *
 * @property string $key_id
 * @property string $request_secret
 * @property string $webhook_secret
 * @property string|null $previous_key_id
 * @property string|null $previous_request_secret
 */
class Connection extends Model
{
    protected $table = 'agentstore_connections';

    protected $guarded = ['*'];

    protected $hidden = ['request_secret', 'webhook_secret', 'previous_request_secret'];

    protected function casts(): array
    {
        return [
            'request_secret' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'previous_request_secret' => 'encrypted',
            'previous_expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'rotated_at' => 'datetime',
        ];
    }

    public static function active(): ?self
    {
        return static::query()->latest('id')->first();
    }

    /**
     * The request secret for a key id: the current one, or the previous one
     * while its rotation grace window lasts.
     */
    public function requestSecretFor(string $keyId): ?string
    {
        if (hash_equals($this->key_id, $keyId)) {
            return $this->request_secret;
        }

        if ($this->previous_key_id !== null
            && hash_equals($this->previous_key_id, $keyId)
            && $this->previous_expires_at?->isFuture()) {
            return $this->previous_request_secret;
        }

        return null;
    }

    public static function findForKey(string $keyId): ?self
    {
        return static::query()
            ->where('key_id', $keyId)
            ->orWhere(fn ($q) => $q->where('previous_key_id', $keyId)->where('previous_expires_at', '>', now()))
            ->first();
    }

    /** Replace every connection with this one: a store pairs with one SaaS at a time. */
    public static function establish(Credentials $credentials, string $saasUrl, ?string $tenantLabel, ?int $approvedBy): self
    {
        return static::getConnectionResolver()->connection()->transaction(function () use ($credentials, $saasUrl, $tenantLabel, $approvedBy) {
            static::query()->delete();

            $connection = new static;
            $connection->forceFill([
                'key_id' => $credentials->keyId,
                'request_secret' => $credentials->requestSecret,
                'webhook_secret' => $credentials->webhookSecret,
                'saas_url' => $saasUrl,
                'tenant_label' => $tenantLabel,
                'approved_by' => $approvedBy,
                'connected_at' => now(),
            ])->save();

            return $connection;
        });
    }

    /** Switch to new credentials, keeping the old request secret for the grace window. */
    public function rotate(Credentials $credentials): void
    {
        $this->forceFill([
            'previous_key_id' => $this->key_id,
            'previous_request_secret' => $this->request_secret,
            'previous_expires_at' => now()->addMinutes((int) config('agentstore.rotation_grace', 10)),
            'key_id' => $credentials->keyId,
            'request_secret' => $credentials->requestSecret,
            'webhook_secret' => $credentials->webhookSecret,
            'rotated_at' => now(),
        ])->save();
    }
}
