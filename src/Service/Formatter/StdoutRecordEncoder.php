<?php

declare(strict_types=1);

namespace Paysera\LoggingExtraBundle\Service\Formatter;

use DateTimeInterface;
use Paysera\LoggingExtraBundle\Service\ExceptionMessageParser;

/**
 * Encodes an already-normalized Monolog record into a single compact JSON line for stdout.
 */
class StdoutRecordEncoder
{
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR;
    public const MAX_JSON_BYTE_COUNT = 32766;

    /**
     * @var array<int, int>
     */
    private const SYSLOG_LEVELS_BY_MONOLOG_LEVEL = [
        100 => 7,
        200 => 6,
        250 => 5,
        300 => 4,
        400 => 3,
        500 => 2,
        550 => 1,
        600 => 0,
    ];

    /**
     * @var string
     */
    private $applicationName;

    /**
     * @var ExceptionMessageParser
     */
    private $exceptionMessageParser;

    public function __construct(string $applicationName, ExceptionMessageParser $exceptionMessageParser)
    {
        $this->applicationName = $applicationName;
        $this->exceptionMessageParser = $exceptionMessageParser;
    }

    /**
     * @param array<array-key, mixed> $context
     * @param array<array-key, mixed> $extra
     */
    public function encode(
        DateTimeInterface $datetime,
        string $channel,
        int $level,
        string $levelName,
        string $message,
        array $context,
        array $extra
    ): string {
        $correlationId = null;
        if (array_key_exists('correlation_id', $extra)) {
            $correlationId = $extra['correlation_id'];
            unset($extra['correlation_id']);
        }

        // When the message is an exception dump, keep the short headline in `message` and the
        // full original in `full_message`, matching the canonical evp formatter.
        $fullMessage = null;
        $parsedMessage = $this->exceptionMessageParser->parse($message);
        if ($parsedMessage !== null) {
            $fullMessage = $message;
            $message = $parsedMessage;
        }

        $fields = [
            'timestamp' => $datetime->format('Y-m-d\TH:i:s.uP'),
            'application_name' => $this->applicationName,
            'channel' => $channel,
            'level' => self::SYSLOG_LEVELS_BY_MONOLOG_LEVEL[$level] ?? $level,
            'level_name' => $levelName,
            'message' => $message,
            'full_message' => $fullMessage,
            'context' => $context,
            'extra' => $extra,
            'correlation_id' => $correlationId,
        ];

        return $this->encodeWithinByteLimit($fields) . "\n";
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function encodeWithinByteLimit(array $fields): string
    {
        $json = $this->toJson($fields);
        if (strlen($json) <= self::MAX_JSON_BYTE_COUNT) {
            return $json;
        }

        $fields['truncated'] = true;
        if (isset($fields['full_message'])) {
            unset($fields['full_message']);
            $json = $this->toJson($fields);
            if (strlen($json) <= self::MAX_JSON_BYTE_COUNT) {
                return $json;
            }
        }

        unset($fields['context'], $fields['extra']);
        $json = $this->toJson($fields);
        if (strlen($json) <= self::MAX_JSON_BYTE_COUNT) {
            return $json;
        }

        // Budget against the ENCODED length: removing N raw bytes of message removes at least N
        // encoded bytes (JSON escaping only inflates), so a single pass never overshoots the cap.
        $overflow = strlen($json) - self::MAX_JSON_BYTE_COUNT;
        $fields['message'] = $this->truncateToByteLength(
            $fields['message'],
            strlen($fields['message']) - $overflow
        );

        return $this->toJson($fields);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function toJson(array $fields): string
    {
        return (string) json_encode($this->filterEmptyFields($fields), self::JSON_FLAGS);
    }

    private function truncateToByteLength(string $string, int $maxByteLength): string
    {
        if ($maxByteLength <= 0) {
            return '';
        }

        if (strlen($string) <= $maxByteLength) {
            return $string;
        }

        $truncated = substr($string, 0, $maxByteLength);

        // Drop a trailing incomplete UTF-8 sequence so the value stays valid for json_encode.
        $index = strlen($truncated);
        while ($index > 0 && (ord($truncated[$index - 1]) & 0xC0) === 0x80) {
            --$index;
        }

        if ($index === 0) {
            return '';
        }

        $leadByte = ord($truncated[$index - 1]);
        if ($leadByte < 0xC0) {
            return $truncated;
        }

        if ($leadByte >= 0xF0) {
            $expectedLength = 4;
        } elseif ($leadByte >= 0xE0) {
            $expectedLength = 3;
        } else {
            $expectedLength = 2;
        }

        if (strlen($truncated) - ($index - 1) < $expectedLength) {
            return substr($truncated, 0, $index - 1);
        }

        return $truncated;
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function filterEmptyFields(array $fields): array
    {
        foreach ($fields as $key => $value) {
            if ($value === null || $value === []) {
                unset($fields[$key]);
            }
        }

        return $fields;
    }
}
