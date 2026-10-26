<?php

declare(strict_types=1);

namespace Survos\Nara\Exception;

/**
 * The Catalog API answered, but not with usable JSON (bad key, unknown
 * endpoint, rate limit, server error).
 */
final class NaraApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly string $url,
        public readonly string $body = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
