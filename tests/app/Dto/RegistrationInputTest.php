<?php

declare(strict_types=1);

namespace WorkspaceTests\Dto;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface;
use Waffle\Commons\Utils\Exception\ValidationException;
use Workspace\Dto\RegistrationInput;

final class RegistrationInputTest extends TestCase
{
    public function testItValidatesAndCleansesEveryField(): void
    {
        $dto = new RegistrationInput(
            email: '  ADA@Example.COM ',
            username: '  ada_lovelace  ',
            age: 36,
            signupIp: '2001:DB8::1',
        );

        // L'email et l'IP sont trimés + mis en minuscules par Assert…
        static::assertSame('ada@example.com', $dto->email);
        static::assertSame('2001:db8::1', $dto->signupIp);
        // …le username est trimé par Assert::notEmpty avant le contrôle de longueur.
        static::assertSame('ada_lovelace', $dto->username);
        static::assertSame(36, $dto->age);
    }

    #[DataProvider('invalidPayloads')]
    public function testItRejectsInvalidInput(string $email, string $username, int $age, string $signupIp): void
    {
        $this->expectException(ValidationExceptionInterface::class);

        new RegistrationInput(email: $email, username: $username, age: $age, signupIp: $signupIp);
    }

    public function testItRaisesA422ValidationException(): void
    {
        try {
            new RegistrationInput(email: 'not-an-email', username: 'ada', age: 30, signupIp: '10.0.0.1');
            static::fail('Expected a ValidationException for the invalid email.');
        } catch (ValidationException $exception) {
            static::assertSame(422, $exception->getCode());
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: int, 3: string}>
     */
    public static function invalidPayloads(): iterable
    {
        yield 'invalid email' => ['not-an-email', 'ada', 30, '10.0.0.1'];
        yield 'blank username' => ['ada@example.com', '   ', 30, '10.0.0.1'];
        yield 'username too short' => ['ada@example.com', 'ab', 30, '10.0.0.1'];
        yield 'underage' => ['ada@example.com', 'ada', 17, '10.0.0.1'];
        yield 'invalid ip' => ['ada@example.com', 'ada', 30, 'not-an-ip'];
    }
}
