<?php

declare(strict_types=1);

namespace Workspace\Voter;

use Waffle\Commons\Contracts\Security\VoterInterface;

class RestrictedAccess implements VoterInterface
{
    public function decide(): bool
    {
        return true;
    }
}
