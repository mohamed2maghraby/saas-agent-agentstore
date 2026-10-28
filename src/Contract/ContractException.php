<?php

namespace SaasAgent\AgentStore\Contract;

use InvalidArgumentException;

/**
 * Data that does not match the contract — a malformed request on the store side,
 * or a malformed store response on the SaaS side. Either way it is not trusted.
 */
final class ContractException extends InvalidArgumentException
{
    public static function field(string $path, string $expected): self
    {
        return new self("Contract field [{$path}] must be {$expected}.");
    }
}
