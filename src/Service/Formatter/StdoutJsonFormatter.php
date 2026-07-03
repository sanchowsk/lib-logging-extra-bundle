<?php

declare(strict_types=1);

namespace Paysera\LoggingExtraBundle\Service\Formatter;

use DateTimeInterface;
use InvalidArgumentException;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\Logger;
use Paysera\LoggingExtraBundle\Service\ExceptionMessageParser;

/**
 * Formats Monolog records as compact JSON Lines for stdout, collected by VictoriaLogs.
 *
 * Normalizes `context`/`extra` (Doctrine-aware, via {@see FormatterTrait}) and delegates the JSON
 * encoding to a composed {@see StdoutRecordEncoder}.
 */
class StdoutJsonFormatter extends NormalizerFormatter
{
    use FormatterTrait;

    /**
     * @var StdoutRecordEncoder
     */
    private $encoder;

    public function __construct(string $applicationName)
    {
        parent::__construct('Y-m-d\TH:i:s.uP');

        $this->encoder = new StdoutRecordEncoder($applicationName, new ExceptionMessageParser());
    }

    /**
     * @param array<string, mixed> $record
     */
    public function format(array $record): string
    {
        if (!isset($record['datetime']) || !$record['datetime'] instanceof DateTimeInterface) {
            throw new InvalidArgumentException('The record must contain a "datetime" DateTimeInterface value.');
        }

        return $this->encoder->encode(
            $record['datetime'],
            (string) ($record['channel'] ?? ''),
            (int) ($record['level'] ?? Logger::DEBUG),
            (string) ($record['level_name'] ?? ''),
            (string) ($record['message'] ?? ''),
            (array) $this->normalize($record['context'] ?? []),
            (array) $this->normalize($record['extra'] ?? [])
        );
    }

    /**
     * @param array<array-key, mixed> $records
     */
    public function formatBatch(array $records): string
    {
        $formatted = '';
        foreach ($records as $record) {
            if (is_array($record)) {
                $formatted .= $this->format($record);
            }
        }

        return $formatted;
    }
}
