<?php

declare(strict_types=1);

namespace Paysera\LoggingExtraBundle\Service;

/**
 * Extracts the short headline of an exception-shaped log message, matching the canonical
 * ExceptionMessageParser from evp/lib-application-logging-bundle.
 */
class ExceptionMessageParser
{
    public function parse(string $message): ?string
    {
        if (preg_match('/^(.*?:?.*?exception.*?) in /', strtr($message, ["\r" => '', "\n" => ' ']), $matches)) {
            return $matches[1];
        }

        return null;
    }
}
