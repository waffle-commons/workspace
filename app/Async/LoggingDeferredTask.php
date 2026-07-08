<?php

declare(strict_types=1);

namespace Workspace\Async;

use Psr\Log\LoggerInterface;
use Waffle\Commons\Contracts\Async\DeferredTaskInterface;

/**
 * Tâche différée de démonstration (AXE 2 / ASYNC-01).
 *
 * Représente le travail court *post-réponse* (envoi de mail, webhook, écriture
 * d'audit) qu'on sort du chemin de latence perçue par l'utilisateur : la réponse
 * part d'abord, PUIS la tâche s'exécute via le {@see \Waffle\Event\Listener\DeferredTaskFlushListener}
 * sur `TerminateEvent`. Ici la « charge » se résume à une trace de journal,
 * preuve observable que l'exécution a bien lieu après l'émission.
 *
 * Autonome et bornée : aucune dépendance à portée requête capturée au-delà du
 * logger et du libellé fournis à la construction.
 */
final readonly class LoggingDeferredTask implements DeferredTaskInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private string $reference,
    ) {}

    #[\Override]
    public function run(): void
    {
        $this->logger->info('Tâche différée exécutée après la réponse pour la référence "{ref}".', [
            'ref' => $this->reference,
        ]);
    }

    #[\Override]
    public function name(): string
    {
        return 'workspace.demo.audit-write';
    }
}
