<?php

declare(strict_types=1);

namespace Markout\Conversion;

final class FrontmatterBuilder
{
    /**
     * @param array{title:string,date:string,author:string,permalink:string} $meta
     */
    public function build(array $meta): string
    {
        $lines = ['---'];
        foreach (['title', 'date', 'author', 'permalink'] as $key) {
            $lines[] = sprintf('%s: %s', $key, $this->quote($meta[$key]));
        }
        $lines[] = '---';

        return implode("\n", $lines) . "\n\n";
    }

    private function quote(string $value): string
    {
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

        return '"' . $escaped . '"';
    }
}
