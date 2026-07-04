<?php

declare(strict_types=1);

namespace Markout\Support;

final class PostVisibility
{
    // Session-aware: accounts for the visitor's wp-postpass cookie, so it
    // returns false once the correct password has been entered. Used for
    // live-request gating (MarkdownResponder), where a visitor session
    // exists.
    public function requiresPassword(\WP_Post $post): bool
    {
        return (bool) post_password_required($post);
    }

    // Stateless: true whenever the post has a password set, regardless of
    // any visitor's session. Used to decide cache eligibility (PostCacher),
    // where there is no visitor session — a background job cannot know
    // whether "the" visitor has entered the password, and the cache file
    // would be readable by anyone regardless once written.
    public function hasPassword(\WP_Post $post): bool
    {
        return $post->post_password !== '';
    }
}
