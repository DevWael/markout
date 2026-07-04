<?php

declare(strict_types=1);

namespace Markout\Conversion;

interface ConverterInterface
{
    public function convert(string $html): string;
}
