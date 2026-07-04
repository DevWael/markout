<?php

declare(strict_types=1);

namespace Markout\Http;

use Markout\Support\PostMetaExtractorInterface;

/**
 * The only class that touches raw HTTP output (header()/exit) for a
 * markdown request; kept separate from MarkdownResponder so the
 * response-building logic stays unit-testable without terminating the
 * process.
 */
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
            // Clear any output buffers upstream code may have started,
            // otherwise buffered output would be flushed before our own
            // headers/body, corrupting the response.
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            // nocache_headers() ensures a page cache or CDN never stores a
            // password-denied (403) response under the /md URL.
            nocache_headers();
            status_header($response->status);
            header('Content-Type: ' . $response->contentType);
        }

        echo $response->body;
        exit;
    }
}
