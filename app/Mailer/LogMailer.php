<?php

declare(strict_types=1);

namespace Workspace\Mailer;

use Psr\Log\LoggerInterface;
use Waffle\Commons\Contracts\Mailer\Email;
use Waffle\Commons\Contracts\Mailer\MailerInterface;

use function implode;

/**
 * Le « transport » de courriel du workspace : il journalise le courriel au lieu
 * de l'envoyer. Waffle ne livre que le contrat `MailerInterface` (QUEUE-03) ;
 * les transports SMTP et API viendront après la v1. Une application branche ici
 * le sien.
 */
final readonly class LogMailer implements MailerInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    #[\Override]
    public function send(Email $email): void
    {
        $this->logger->info('Courriel « {subject} » envoyé à {to}.', [
            'subject' => $email->subject,
            'to' => implode(', ', $email->to),
        ]);
    }
}
