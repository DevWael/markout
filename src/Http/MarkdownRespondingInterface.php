<?php

declare(strict_types=1);

namespace Markout\Http;

interface MarkdownRespondingInterface {

	/**
	 * @param array{title:string,date:string,author:string,permalink:string} $meta
	 */
	public function respond( \WP_Post $post, array $meta ): Response;
}
