<?php

declare(strict_types=1);

namespace Workspace\Service;

final class HomeService
{
    /**
     * @return string[]
     */
    public function sayHello(?string $to = null): array
    {
        $name = $to ?? 'from Waffle';

        return [
            'message' => "Hello {$name}!",
        ];
    }
}
