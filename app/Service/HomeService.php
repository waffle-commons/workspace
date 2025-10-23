<?php

declare(strict_types=1);

namespace Workspace\Service;

class HomeService 
{
    /**
     * @return string[]
     */
    public function sayHello(null|string $to = null): array
    {
        $name = $to === null ? 'from Waffle' : $to;

        return [
            "message" => "Hello {$name}!",
        ];
    }
}