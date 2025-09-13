<?php

declare(strict_types=1);

namespace NetherByte\NetherPerms\integration;

use NetherByte\PlaceholderAPI\expansion\Expansion;
use NetherByte\PlaceholderAPI\provider\Provider;
use NetherByte\NetherPerms\NetherPerms;

final class NetherPermsProvider implements Provider
{
    public function __construct(private NetherPerms $plugin){}

    public function getName() : string
    {
        return 'NetherPerms';
    }

    public function listExpansions() : array
    {
        return ['netherperms'];
    }

    public function provide(string $identifier) : ?Expansion
    {
        if ($identifier === 'netherperms') {
            return new NetherPermsExpansion($this->plugin);
        }
        return null;
    }
}
