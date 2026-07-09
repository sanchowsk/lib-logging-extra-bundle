<?php

declare(strict_types=1);

namespace Paysera\LoggingExtraBundle\Tests\Unit\Service\Formatter;

use DateTimeImmutable;
use InvalidArgumentException;
use Monolog\Logger;
use Paysera\LoggingExtraBundle\Service\ExceptionMessageParser;
use Paysera\LoggingExtraBundle\Service\Formatter\StdoutJsonFormatter;
use Paysera\LoggingExtraBundle\Service\Formatter\StdoutRecordEncoder;
use PHPUnit\Framework\TestCase;

class StdoutJsonFormatterTest extends TestCase
{
    private const APPLICATION_NAME = 'app-target2-integration';

    public function testFormatsRecordAsSingleCompactJsonLine(): void
    {
        $output = $this->format();

        $this->assertStringEndsWith("\n", $output);
        $this->assertSame(1, substr_count($output, "\n"), 'Record must be a single line');

        $line = rtrim($output, "\n");
        $this->assertStringNotContainsString("\n", $line, 'JSON must not contain embedded newlines');

        $decoded = json_decode($line, true);
        $this->assertIsArray($decoded);
        $this->assertSame(self::APPLICATION_NAME, $decoded['application_name']);
        $this->assertSame('app', $decoded['channel']);
        $this->assertSame('INFO', $decoded['level_name']);
        $this->assertSame('2026-06-10T16:03:21.123456+03:00', $decoded['timestamp']);
    }

    public function testEmitsCanonicalFieldOrder(): void
    {
        $line = rtrim($this->format([
            'context' => ['client_id' => 7],
            'extra' => ['correlation_id' => 'corr-1', 'memory_peak' => '2 MB'],
        ]), "\n");

        $decoded = json_decode($line, true);
        $this->assertIsArray($decoded);
        $this->assertSame(
            [
                'timestamp',
                'application_name',
                'channel',
                'level',
                'level_name',
                'message',
                'context',
                'extra',
                'correlation_id',
            ],
            array_keys($decoded),
            'Field order must match the canonical evp StdoutJsonFormatter'
        );
    }

    /**
     * @dataProvider syslogLevelProvider
     */
    public function testMapsMonologLevelToSyslogSeverity(
        int $monologLevel,
        string $levelName,
        int $expectedSyslogLevel
    ): void {
        $decoded = $this->decode(['level' => $monologLevel, 'level_name' => $levelName]);

        $this->assertSame($expectedSyslogLevel, $decoded['level']);
    }

    /**
     * @return array<string, array{int, string, int}>
     */
    public function syslogLevelProvider(): array
    {
        return [
            'debug' => [Logger::DEBUG, 'DEBUG', 7],
            'info' => [Logger::INFO, 'INFO', 6],
            'notice' => [Logger::NOTICE, 'NOTICE', 5],
            'warning' => [Logger::WARNING, 'WARNING', 4],
            'error' => [Logger::ERROR, 'ERROR', 3],
            'critical' => [Logger::CRITICAL, 'CRITICAL', 2],
            'alert' => [Logger::ALERT, 'ALERT', 1],
            'emergency' => [Logger::EMERGENCY, 'EMERGENCY', 0],
        ];
    }

    public function testHoistsCorrelationIdFromExtraToTopLevel(): void
    {
        $decoded = $this->decode([
            'extra' => ['correlation_id' => 'app-target2-integration6a171447acf112.97444679', 'memory_peak' => '2 MB'],
        ]);

        $this->assertSame('app-target2-integration6a171447acf112.97444679', $decoded['correlation_id']);

        $extra = $decoded['extra'];
        $this->assertIsArray($extra);
        $this->assertArrayNotHasKey('correlation_id', $extra);
        $this->assertSame('2 MB', $extra['memory_peak']);
    }

    /**
     * @dataProvider messageProvider
     */
    public function testRendersMessageAndFullMessage(
        string $rawMessage,
        string $expectedMessage,
        ?string $expectedFullMessage
    ): void {
        $decoded = $this->decode(['message' => $rawMessage]);

        $this->assertSame($expectedMessage, $decoded['message']);

        if ($expectedFullMessage === null) {
            $this->assertArrayNotHasKey('full_message', $decoded);

            return;
        }

        $this->assertSame($expectedFullMessage, $decoded['full_message']);
    }

    /**
     * @return array<string, array{string, string, string|null}>
     */
    public function messageProvider(): array
    {
        return [
            'plain message is emitted unchanged' => [
                'Example message',
                'Example message',
                null,
            ],
            'lowercase exception message is split' => [
                'Some exception in /app/src/Foo.php:42',
                'Some exception',
                'Some exception in /app/src/Foo.php:42',
            ],
            'standard php exception class is split' => [
                'RuntimeException: boom in /app/src/Foo.php:42',
                'RuntimeException: boom',
                'RuntimeException: boom in /app/src/Foo.php:42',
            ],
        ];
    }

    public function testPreservesFalseyContextValues(): void
    {
        $decoded = $this->decode([
            'context' => ['client_id' => 0, 'enabled' => false, 'note' => ''],
        ]);

        $context = $decoded['context'];
        $this->assertIsArray($context);
        $this->assertSame(0, $context['client_id']);
        $this->assertFalse($context['enabled']);
        $this->assertSame('', $context['note']);
    }

    public function testOmitsEmptyContextExtraAndCorrelationId(): void
    {
        $decoded = $this->decode();

        $this->assertArrayNotHasKey('context', $decoded);
        $this->assertArrayNotHasKey('extra', $decoded);
        $this->assertArrayNotHasKey('correlation_id', $decoded);
    }

    /**
     * @dataProvider oversizeMessageProvider
     */
    public function testTruncatesOversizeRecordsWithinByteCap(string $message): void
    {
        $line = rtrim($this->format(['message' => $message]), "\n");

        $this->assertLessThanOrEqual(StdoutRecordEncoder::MAX_JSON_BYTE_COUNT, strlen($line));
        $decoded = json_decode($line, true);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['truncated']);
    }

    /**
     * @return array<string, array{string}>
     */
    public function oversizeMessageProvider(): array
    {
        return [
            'plain' => [str_repeat('x', 40000)],
            // Chars that JSON-escapes to multiple bytes (" -> \", \n -> \\n, \x01 -> ):
            // the cap must be enforced against the ENCODED line, not the raw byte count.
            'escape-heavy' => [str_repeat("\"\n\x01", 20000)],
        ];
    }

    public function testDropsContextAndExtraBeforeTruncatingMessage(): void
    {
        $decoded = $this->decode([
            'message' => str_repeat('x', 40000),
            'context' => ['client_id' => 7],
            'extra' => ['correlation_id' => 'corr-1'],
        ]);

        $this->assertTrue($decoded['truncated']);
        $this->assertArrayNotHasKey('context', $decoded);
        $this->assertArrayNotHasKey('extra', $decoded);
    }

    public function testDropsCorrelationIdWhenItAloneExceedsTheByteCap(): void
    {
        // correlation_id is hoisted verbatim and is not truncatable, so it is the last field dropped
        // to keep the cap; every other remaining field is fixed-size.
        $line = rtrim($this->format([
            'message' => str_repeat('x', 1000),
            'extra' => ['correlation_id' => str_repeat('c', 40000)],
        ]), "\n");

        $this->assertLessThanOrEqual(StdoutRecordEncoder::MAX_JSON_BYTE_COUNT, strlen($line));

        $decoded = json_decode($line, true);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['truncated']);
        $this->assertArrayNotHasKey('correlation_id', $decoded);
    }

    public function testFormatBatchEmitsOneLinePerRecord(): void
    {
        $formatter = $this->createFormatter();

        $batch = $formatter->formatBatch([
            $this->record(['message' => 'first']),
            $this->record(['message' => 'second']),
        ]);

        $lines = array_values(array_filter(explode("\n", $batch), static function (string $line): bool {
            return $line !== '';
        }));
        $this->assertCount(2, $lines);

        $first = json_decode($lines[0], true);
        $second = json_decode($lines[1], true);
        $this->assertIsArray($first);
        $this->assertIsArray($second);
        $this->assertSame('first', $first['message']);
        $this->assertSame('second', $second['message']);
    }

    public function testPassesThroughUnmappedLevelUnchanged(): void
    {
        // Matches the canonical evp formatter: an unmapped Monolog level is emitted as-is.
        $decoded = $this->decode(['level' => 999, 'level_name' => 'CUSTOM']);

        $this->assertSame(999, $decoded['level']);
        $this->assertSame('CUSTOM', $decoded['level_name']);
    }

    public function testThrowsWhenRecordHasNoDatetime(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->createFormatter()->format([
            'message' => 'no datetime',
            'level' => Logger::INFO,
            'level_name' => 'INFO',
            'channel' => 'app',
            'context' => [],
            'extra' => [],
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function format(array $overrides = []): string
    {
        return $this->createFormatter()->format($this->record($overrides));
    }

    private function createFormatter(): StdoutJsonFormatter
    {
        return new StdoutJsonFormatter(
            new StdoutRecordEncoder(self::APPLICATION_NAME, new ExceptionMessageParser())
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function decode(array $overrides = []): array
    {
        $decoded = json_decode(rtrim($this->format($overrides), "\n"), true);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function record(array $overrides = []): array
    {
        return array_merge([
            'message' => 'Example message',
            'context' => [],
            'level' => Logger::INFO,
            'level_name' => 'INFO',
            'channel' => 'app',
            'datetime' => new DateTimeImmutable('2026-06-10T16:03:21.123456+03:00'),
            'extra' => [],
        ], $overrides);
    }
}
