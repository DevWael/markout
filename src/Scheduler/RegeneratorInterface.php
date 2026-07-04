<?php

declare(strict_types=1);

namespace Markout\Scheduler;

interface RegeneratorInterface
{
    public function register(): void;
}
