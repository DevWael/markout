<?php

declare(strict_types=1);

namespace Markout\Conversion;

use League\HTMLToMarkdown\HtmlConverter;

final class HtmlToMarkdownConverter implements ConverterInterface {

	private HtmlConverter $converter;

	public function __construct( ?HtmlConverter $converter = null ) {
		$this->converter = $converter ?? new HtmlConverter( array( 'strip_tags' => true ) );
	}

	public function convert( string $html ): string {
		try {
			return $this->converter->convert( $html );
		} catch ( \Throwable $exception ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- gated behind WP_DEBUG, not left in a hot path.
				error_log( sprintf( 'Markout: HTML to Markdown conversion failed: %s', $exception->getMessage() ) );
			}

			return $this->fallback( $html );
		}
	}

	private function fallback( string $html ): string {
		if ( function_exists( 'wp_strip_all_tags' ) ) {
			return wp_strip_all_tags( $html );
		}

		return trim( strip_tags( $html ) );
	}
}
