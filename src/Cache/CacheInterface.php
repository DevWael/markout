<?php

declare(strict_types=1);

namespace Markout\Cache;

interface CacheInterface
{
    public function get(int $postId): ?string;

    public function write(int $postId, string $content): bool;

    public function delete(int $postId): bool;
}
