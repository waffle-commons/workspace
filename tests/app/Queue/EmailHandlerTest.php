<?php

declare(strict_types=1);

namespace WorkspaceTests\Queue;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Waffle\Commons\Contracts\Mailer\Email;
use Waffle\Commons\Contracts\Queue\Envelope;
use Waffle\Commons\Contracts\Queue\MessageInterface;
use Waffle\Commons\Testing\Mailer\MailerSpy;
use Workspace\Mailer\LogMailer;
use Workspace\Queue\EmailHandler;

/**
 * La démo QUEUE-03 : dans le worker, un courriel mis en file est confié au
 * transport de l'application.
 */
final class EmailHandlerTest extends TestCase
{
    public function testUnCourrielEnFileEstConfieAuTransport(): void
    {
        $mailer = new MailerSpy();
        $handler = new EmailHandler($mailer);
        $email = $this->email();

        $handler->handle($email, new Envelope('id-1', $email, 'default', new DateTimeImmutable()));

        static::assertSame(Email::class, $handler->handles());
        static::assertSame([$email], $mailer->sent());
    }

    public function testUnAutreMessageEstRefuse(): void
    {
        $message = new class implements MessageInterface {};

        $this->expectException(InvalidArgumentException::class);

        new EmailHandler(new MailerSpy())->handle(
            $message,
            new Envelope('id-2', $message, 'default', new DateTimeImmutable()),
        );
    }

    public function testLeTransportDuWorkspaceJournaliseLeCourriel(): void
    {
        $journal = new JournalEspion();

        new LogMailer($journal)->send($this->email());

        static::assertSame(
            [[
                'info',
                'Courriel « {subject} » envoyé à {to}.',
                ['subject' => 'Bienvenue', 'to' => 'ada@example.com, alan@example.com'],
            ]],
            $journal->entries,
        );
    }

    private function email(): Email
    {
        return new Email(
            from: 'noreply@example.com',
            to: ['ada@example.com', 'alan@example.com'],
            subject: 'Bienvenue',
            text: 'Bonjour',
        );
    }
}
