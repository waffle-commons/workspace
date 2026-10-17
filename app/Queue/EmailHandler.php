<?php

declare(strict_types=1);

namespace Workspace\Queue;

use InvalidArgumentException;
use Waffle\Commons\Contracts\Mailer\Email;
use Waffle\Commons\Contracts\Mailer\MailerInterface;
use Waffle\Commons\Contracts\Queue\Envelope;
use Waffle\Commons\Contracts\Queue\MessageHandlerInterface;
use Waffle\Commons\Contracts\Queue\MessageInterface;

use function sprintf;

/**
 * Envoie, dans le worker `bin/waffle queue:work`, les courriels que l'application
 * a mis en file (QUEUE-03) : la requête HTTP rend la main tout de suite, et un
 * envoi qui échoue est retenté puis, en dernier recours, conservé parmi les
 * messages en échec.
 *
 * Un message peut être livré plus d'une fois : un vrai transport dédoublonne sur
 * `$envelope->id`.
 */
final readonly class EmailHandler implements MessageHandlerInterface
{
    public function __construct(
        private MailerInterface $mailer,
    ) {}

    #[\Override]
    public function handles(): string
    {
        return Email::class;
    }

    /**
     * @throws InvalidArgumentException Si le message n'est pas un courriel.
     */
    #[\Override]
    public function handle(MessageInterface $message, Envelope $envelope): void
    {
        if (!$message instanceof Email) {
            throw new InvalidArgumentException(sprintf(
                'Ce gestionnaire n\'envoie que des courriels, pas %s.',
                $message::class,
            ));
        }

        $this->mailer->send($message);
    }
}
