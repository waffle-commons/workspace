<?php

declare(strict_types=1);

namespace Workspace\Dto;

use Waffle\Commons\Contracts\Reactive\Attribute\Broadcast;
use Waffle\Commons\Contracts\Reactive\BroadcastBufferInterface;
use Waffle\Commons\Contracts\Reactive\MutationRecord;

/**
 * DTO mutable de démonstration du temps réel (AXE 3 / REACTIVE-01).
 *
 * La propriété {@see self::$status} porte `#[Broadcast(channel: 'orders')]` : son
 * *write-hook* PHP 8.5 enregistre une {@see MutationRecord} dans le tampon de
 * diffusion à portée requête — SANS aucune I/O dans le hook. En fin de requête,
 * le {@see \Waffle\Event\Listener\BroadcastFlushListener} draine le tampon et
 * pousse chaque mutation sur le transport SSE, hors du chemin chaud de
 * l'assignation.
 *
 * Comme une propriété *hookée* ne peut pas être `readonly` en PHP 8.5, la classe
 * est `final` (jamais `final readonly`) et l'immuabilité externe est exprimée par
 * la visibilité asymétrique `public private(set)`. Le tampon est injecté au
 * constructeur (nullable) : le DTO reste utilisable hors contexte requête (tests,
 * hydratation) où aucune diffusion n'est attendue.
 */
final class LiveOrder
{
    /**
     * Statut de la commande ; chaque écriture est diffusée sur le canal `orders`.
     */
    #[Broadcast(channel: 'orders')]
    public private(set) string $status {
        set {
            $this->status = $value;
            $this->buffer?->record(new MutationRecord(
                channel: 'orders',
                entityClass: self::class,
                property: 'status',
                value: $value,
            ));
        }
    }

    public function __construct(
        public readonly string $reference,
        string $status,
        private readonly ?BroadcastBufferInterface $buffer = null,
    ) {
        $this->status = $status;
    }

    /**
     * Fait avancer le statut de la commande, ce qui déclenche la diffusion.
     */
    public function advanceTo(string $status): void
    {
        $this->status = $status;
    }
}
