<?php

declare(strict_types=1);

namespace Markout\Tests\Unit\Conversion;

use League\HTMLToMarkdown\HtmlConverter;
use Markout\Conversion\HtmlToMarkdownConverter;
use PHPUnit\Framework\TestCase;

final class HtmlToMarkdownConverterTest extends TestCase
{
    public function test_convert_turns_html_into_markdown(): void
    {
        $converter = new HtmlToMarkdownConverter();

        $result = $converter->convert('<p>Hello <strong>World</strong></p>');

        self::assertSame('Hello **World**', trim($result));
    }

    public function test_convert_falls_back_to_stripped_tags_on_failure(): void
    {
        $throwingConverter = new class (['strip_tags' => true]) extends HtmlConverter {
            public function convert(string $html): string
            {
                throw new \RuntimeException('boom');
            }
        };

        $converter = new HtmlToMarkdownConverter($throwingConverter);

        $result = $converter->convert('<p>Hello <strong>World</strong></p>');

        self::assertSame('Hello World', trim($result));
    }
}
