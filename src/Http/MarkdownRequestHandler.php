<?php

declare(strict_types=1);

namespace Markout\Http;

use Markout\Support\PostMetaExtractorInterface;

final class MarkdownRequestHandler implements MarkdownRequestHandlerInterface
{
    public function __construct(
        private readonly MarkdownRespondingInterface $responder,
        private readonly PostMetaExtractorInterface $metaExtractor
    ) {
    }

    public function handle(\WP_Post $post): void
    {
        $response = $this->responder->respond($post, $this->metaExtractor->extract($post));

        $this->emit($response);
    }

    private function emit(Response $response): void
    {
        if (!headers_sent()) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            nocache_headers();
            status_header($response->status);
            header('Content-Type: ' . $response->contentType);
        }

        echo $response->body;
        exit;
    }
}
