<?php

declare(strict_types=1);

namespace Survos\QuickbaseBundle\Exception;

final class QuickbaseApiException extends \RuntimeException
{
    /** @param array<string, mixed>|null $response */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly ?string $apiRay = null,
        public readonly ?array $response = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
