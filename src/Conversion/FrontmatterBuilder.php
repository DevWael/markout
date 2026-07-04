<?php

declare(strict_types=1);

namespace Markout\Conversion;

final class FrontmatterBuilder {

	/**
	 * @param array{title:string,date:string,author:string,permalink:string} $meta
	 */
	public function build( array $meta ): string {
		$lines = array( '---' );
		foreach ( array( 'title', 'date', 'author', 'permalink' ) as $key ) {
			$lines[] = sprintf( '%s: %s', $key, $this->quote( $meta[ $key ] ) );
		}
		$lines[] = '---';

		return implode( "\n", $lines ) . "\n\n";
	}

	private function quote( string $value ): string {
		// Order matters: backslashes must be escaped before quotes. Escaping
		// quotes first would double-escape the backslash that step just
		// introduced, producing a malformed sequence instead of valid
		// double-quoted YAML.
		$escaped = str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value );

		return '"' . $escaped . '"';
	}
}
