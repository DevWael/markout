<?php

declare(strict_types=1);

namespace Markout\Http;

final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly string $contentType,
        public readonly string $body
    ) {
    }
}
