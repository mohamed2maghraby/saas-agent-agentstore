<?php

namespace SaasAgent\AgentStore\Contract;

/**
 * Evidence a shopper offers that they are who they say — what a call-centre
 * agent would ask for. The store decides whether it is enough.
 *
 * `channelIdentity` is the identity the messaging channel itself vouches for
 * (the WhatsApp number a message arrived from), which is stronger than anything
 * typed into a chat.
 */
final readonly class CustomerVerification
{
    use Reads;

    public function __construct(
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $orderNumber = null,
        public ?string $channelIdentity = null,
    ) {
        if ($phone === null && $email === null && $channelIdentity === null) {
            throw ContractException::field('verification', 'a phone, an email or a channel identity');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            self::optStr($data, 'phone', 'verification', 32),
            self::optStr($data, 'email', 'verification', 255),
            self::optStr($data, 'order_number', 'verification', 64),
            self::optStr($data, 'channel_identity', 'verification', 255),
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'phone' => $this->phone,
            'email' => $this->email,
            'order_number' => $this->orderNumber,
            'channel_identity' => $this->channelIdentity,
        ], fn ($v) => $v !== null);
    }
}
