<?php

declare(strict_types=1);

namespace Workspace\Voter;

use Waffle\Commons\Contracts\Auth\SecurityContextInterface;
use Waffle\Commons\Contracts\Security\VoterInterface;

class RestrictedAccess implements VoterInterface
{
    #[\Override]
    public function decide(SecurityContextInterface $ctx, mixed $subject = null): bool
    {
        return true;
    }
}
