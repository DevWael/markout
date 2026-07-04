<?php

declare(strict_types=1);

namespace Markout\Http;

interface MarkdownRequestHandlerInterface {

	public function handle( \WP_Post $post ): void;
}
