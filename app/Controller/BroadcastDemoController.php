<?php

declare(strict_types=1);

namespace Workspace\Controller;

use Psr\Http\Message\ResponseInterface;
use Waffle\Commons\Contracts\Reactive\BroadcastBufferInterface;
use Waffle\Commons\Contracts\Routing\Attribute\Route;
use Waffle\Commons\Contracts\Routing\Constant as Routing;
use Waffle\Commons\Contracts\Security\Attribute\PublicAccess;
use Waffle\Core\BaseController;
use Waffle\Exception\RenderingException;
use Workspace\Dto\LiveOrder;

/**
 * Vitrine du temps réel par hooks de propriété (AXE 3 / REACTIVE-01).
 *
 * Crée un {@see LiveOrder} relié au tampon de diffusion à portée requête, puis
 * fait avancer son `status` plusieurs fois. Chaque écriture passe par le
 * *write-hook* `#[Broadcast]`, qui enregistre une mutation dans le tampon SANS
 * I/O. Après l'émission de cette réponse, le `BroadcastFlushListener` draine le
 * tampon et pousse chaque mutation sur le transport SSE (`docker logs` du
 * conteneur sert de collecteur pour la démo).
 *
 * La réponse renvoie l'historique des transitions, rendant observable le fait
 * que les mutations ont bien été capturées pendant la requête.
 */
#[Route(path: '/reactive', name: 'reactive_')]
final class BroadcastDemoController extends BaseController
{
    /**
     * GET /reactive/order : fait progresser une commande de démonstration.
     *
     * @throws RenderingException
     */
    #[Route(path: 'order', methods: [Routing::METHOD_GET], name: 'order')]
    #[PublicAccess]
    public function order(BroadcastBufferInterface $buffer): ResponseInterface
    {
        // Le DTO mutable est relié au tampon : chaque transition de statut sera
        // diffusée en fin de requête sur le canal `orders`.
        $order = new LiveOrder(reference: 'ORD-4242', status: 'pending', buffer: $buffer);
        $order->advanceTo('paid');
        $order->advanceTo('shipped');
        $order->advanceTo('delivered');

        return $this->jsonResponse(data: [
            'reference' => $order->reference,
            'final_status' => $order->status,
            'transitions' => ['pending', 'paid', 'shipped', 'delivered'],
            'note' => 'Chaque transition a été mise en tampon ; le flush SSE intervient après cette réponse (TerminateEvent).',
        ]);
    }
}
