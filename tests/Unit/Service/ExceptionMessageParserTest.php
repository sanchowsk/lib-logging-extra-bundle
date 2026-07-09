<?php

declare(strict_types=1);

namespace Paysera\LoggingExtraBundle\Tests\Unit\Service;

use Paysera\LoggingExtraBundle\Service\ExceptionMessageParser;
use PHPUnit\Framework\TestCase;

class ExceptionMessageParserTest extends TestCase
{
    /**
     * @dataProvider parseProvider
     */
    public function testParseReturnsShortHeadlineOrNull(string $message, ?string $expected): void
    {
        $this->assertSame($expected, (new ExceptionMessageParser())->parse($message));
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public function parseProvider(): array
    {
        return [
            'uncaught exception with class name and reason' => [
                'Uncaught RuntimeException: error in /app/file.php:42',
                'Uncaught RuntimeException: error',
            ],
            'namespaced exception class' => [
                'App\Exception\SomeException: error in /app/file.php:42',
                'App\Exception\SomeException: error',
            ],
            'class name directly before the location' => [
                'LogicException in /app/file.php:42',
                'LogicException',
            ],
            'driver exception carrying a bracketed state' => [
                'PDOException: SQLSTATE[23000] in /app/file.php:42',
                'PDOException: SQLSTATE[23000]',
            ],
            'symfony uncaught php exception prefix' => [
                'Uncaught PHP Exception RuntimeException in /app/file.php:42',
                'Uncaught PHP Exception RuntimeException',
            ],
            'lowercase natural language exception' => [
                'An exception occurred while executing a query in SomeClass',
                'An exception occurred while executing a query',
            ],
            'newline is normalized to a space before matching' => [
                "Some exception\n stack in /app/x.php:1",
                'Some exception  stack',
            ],
            'carriage return is stripped before matching' => [
                "Some exception\r\n stack in /app/x.php:1",
                'Some exception  stack',
            ],
            'plain message is not exception-shaped' => [
                'Example message',
                null,
            ],
            'location without an exception is not matched' => [
                'User logged in /app/x.php',
                null,
            ],
            'exception appearing only in the file path is not matched' => [
                'Some error in /app/Exception/Foo.php:42',
                null,
            ],
        ];
    }
}
