<?php

declare(strict_types=1);

namespace Paysera\LoggingExtraBundle\Service;

/**
 * Extracts the short headline of an exception-shaped log message.
 *
 * Ported from the canonical ExceptionMessageParser in evp/lib-application-logging-bundle, with one
 * deliberate difference: matching is case-insensitive, so standard PHP class names (RuntimeException,
 * PDOException, ...) are recognised. The canonical parser only matches a lowercase "exception" and
 * therefore leaves those messages unsplit.
 */
class ExceptionMessageParser
{
    private const PATTERN = '/^(.*?:?.*?exception.*?) in /i';

    public function parse(string $message): ?string
    {
        if (preg_match(self::PATTERN, strtr($message, ["\r" => '', "\n" => ' ']), $matches)) {
            return $matches[1];
        }

        return null;
    }
}
