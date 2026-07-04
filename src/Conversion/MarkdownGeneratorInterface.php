<?php

declare(strict_types=1);

namespace Markout\Conversion;

interface MarkdownGeneratorInterface {

	/**
	 * @param array{title:string,date:string,author:string,permalink:string} $meta
	 */
	public function generate( \WP_Post $post, array $meta ): string;
}
