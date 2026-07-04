<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

if (!class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID = 0;
        public string $post_content = '';
        public string $post_type = 'post';
        public string $post_status = 'publish';
        public int $post_author = 0;
        public string $post_password = '';
    }
}
