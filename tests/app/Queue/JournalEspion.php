<?php

declare(strict_types=1);

namespace WorkspaceTests\Queue;

use Psr\Log\AbstractLogger;
use Stringable;

use function is_string;

/**
 * Un journal qui garde chaque entrée : son niveau, son message et les valeurs
 * textuelles de son contexte.
 */
final class JournalEspion extends AbstractLogger
{
    /** @var list<array{string, string, array<string, string>}> */
    public private(set) array $entries = [];

    #[\Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $texts = [];
        foreach ($context as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }

            $texts[$key] = $value;
        }

        $this->entries[] = [is_string($level) ? $level : '', (string) $message, $texts];
    }
}
