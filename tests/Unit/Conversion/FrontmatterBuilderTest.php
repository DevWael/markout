<?php

declare(strict_types=1);

namespace Markout\Tests\Unit\Conversion;

use Markout\Conversion\FrontmatterBuilder;
use PHPUnit\Framework\TestCase;

final class FrontmatterBuilderTest extends TestCase
{
    public function test_build_produces_yaml_block(): void
    {
        $builder = new FrontmatterBuilder();

        $result = $builder->build([
            'title' => 'Hello World',
            'date' => '2026-07-04T00:00:00+00:00',
            'author' => 'Ahmad',
            'permalink' => 'https://example.com/hello-world/',
        ]);

        self::assertSame(
            "---\n"
            . "title: \"Hello World\"\n"
            . "date: \"2026-07-04T00:00:00+00:00\"\n"
            . "author: \"Ahmad\"\n"
            . "permalink: \"https://example.com/hello-world/\"\n"
            . "---\n\n",
            $result
        );
    }

    public function test_build_escapes_double_quotes_in_values(): void
    {
        $builder = new FrontmatterBuilder();

        $result = $builder->build([
            'title' => 'He said "hi"',
            'date' => '2026-07-04T00:00:00+00:00',
            'author' => 'Ahmad',
            'permalink' => 'https://example.com/x/',
        ]);

        self::assertStringContainsString('title: "He said \\"hi\\""', $result);
    }

    public function test_build_escapes_backslashes_in_values(): void
    {
        $builder = new FrontmatterBuilder();

        $result = $builder->build([
            'title' => 'C:\\Users\\test',
            'date' => '2026-07-04T00:00:00+00:00',
            'author' => 'Ahmad',
            'permalink' => 'https://example.com/x/',
        ]);

        self::assertStringContainsString('title: "C:\\\\Users\\\\test"', $result);
    }
}
