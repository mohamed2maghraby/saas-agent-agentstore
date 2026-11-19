<?php

namespace SaasAgent\AgentStore\Contract;

/**
 * The wire contract's version, independent of the package and both apps.
 * Bumped only for a breaking change to a request, a response or the signature.
 */
final class ContractVersion
{
    public const CURRENT = 'v1';

    /** @var list<string> versions this build can speak */
    public const SUPPORTED = ['v1'];

    public const PACKAGE = '0.3.0';

    public static function supports(string $version): bool
    {
        return in_array($version, self::SUPPORTED, true);
    }
}
