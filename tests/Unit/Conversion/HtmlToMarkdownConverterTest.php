<?php

declare(strict_types=1);

namespace Markout\Tests\Unit\Conversion;

use Brain\Monkey\Functions;
use League\HTMLToMarkdown\HtmlConverter;
use Markout\Conversion\HtmlToMarkdownConverter;
use Markout\Tests\TestCase;

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

    public function test_convert_fallback_prefers_wp_strip_all_tags_when_available(): void
    {
        // When wp_strip_all_tags() exists, the fallback must use it rather than
        // the plain strip_tags() branch.
        Functions\when('wp_strip_all_tags')->alias(
            static fn (string $html): string => 'WP-STRIPPED'
        );

        $converter = new HtmlToMarkdownConverter($this->throwingConverter());

        self::assertSame('WP-STRIPPED', $converter->convert('<p>anything</p>'));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_convert_logs_conversion_failure_when_wp_debug_enabled(): void
    {
        // Separate process so defining WP_DEBUG does not leak. With WP_DEBUG on,
        // the caught-exception path emits an error_log line, which we capture.
        define('WP_DEBUG', true);

        $logFile = sys_get_temp_dir() . '/markout-conv-log-' . uniqid('', true) . '.log';
        $previous = ini_set('error_log', $logFile);

        $converter = new HtmlToMarkdownConverter($this->throwingConverter());

        try {
            self::assertSame('Hello World', trim($converter->convert('<p>Hello <strong>World</strong></p>')));

            self::assertFileExists($logFile);
            self::assertStringContainsString(
                'Markout: HTML to Markdown conversion failed: boom',
                (string) file_get_contents($logFile)
            );
        } finally {
            if ($previous !== false) {
                ini_set('error_log', $previous);
            }
            @unlink($logFile);
        }
    }

    private function throwingConverter(): HtmlConverter
    {
        return new class (['strip_tags' => true]) extends HtmlConverter {
            public function convert(string $html): string
            {
                throw new \RuntimeException('boom');
            }
        };
    }
}
