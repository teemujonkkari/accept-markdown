<?php

/**
 * Plugin Name: Accept Markdown test hooks
 * Description: Test-only hooks for the Accept Markdown HTTP suite. Installed by tests/fixtures.php. Never use on a real site.
 */

declare(strict_types=1);

function am_test_register(): void
{
    $type = fn (bool $public, bool $support): array => [
        'public' => $public,
        'label' => 'Accept Markdown test',
        'supports' => $support ? ['title', 'editor', 'accept-markdown'] : ['title', 'editor'],
    ];
    register_post_type('am_doc', $type(true, true));
    register_post_type('am_plain', $type(true, false));
    register_post_type('am_removed', $type(true, true));
    register_post_type('am_internal', $type(false, true));
    remove_post_type_support('am_removed', 'accept-markdown');

    add_shortcode('am_test', fn (): string => 'amShortcodeOutput');

    // A third-party block that lists comments without "comment" in its name, like a reviews block.
    register_block_type('am/feedback', ['render_callback' => fn (): string => '<p>'.implode(' ', wp_list_pluck(get_comments(['number' => 5]), 'comment_content')).'</p>']);
}
add_action('init', 'am_test_register');

// Third-party content hooks: the_content must never reach Markdown, render_block does by decision.
// The render_block filter also runs shortcodes, as some plugins do, so shortcodes must never execute during rendering.
add_filter('the_content', fn (string $content): string => $content.'<p>amTheContentMarker</p>');
add_filter('render_block', fn (string $html, array $block): string => str_contains($block['attrs']['className'] ?? '', 'am-render')
    ? do_shortcode($html).'<p>amRenderBlockMarker</p>'
    : $html, 10, 2);

// Throws only while Accept Markdown renders (its temporary comments_pre_query filter is present), to test the fallback.
add_filter('render_block', function (string $html, array $block): string {
    if (str_contains($block['attrs']['className'] ?? '', 'am-throw') && has_filter('comments_pre_query')) {
        throw new RuntimeException('amRenderExceptionMarker');
    }

    return $html;
}, 10, 2);

add_filter('accept_markdown/allowed', fn (bool $allowed, WP_Post $post): bool => match ($post->post_name) {
    'am-protected' => true,
    'am-blocked' => false,
    default => $allowed,
}, 10, 2);

add_filter('accept_markdown/output', function (string $markdown, WP_Post $post): string {
    if ($post->post_name === 'am-throws') {
        throw new RuntimeException('amExceptionMarker');
    }

    return $post->post_name === 'am-post' ? $markdown."\namFooterMarker\n" : $markdown;
}, 10, 2);

// ?am_test=internal makes the main query the non-viewable CPT post, so the viewable check is exercised.
add_filter('request', fn (array $vars): array => ($_GET['am_test'] ?? '') === 'internal'
    ? ['post_type' => 'am_internal', 'name' => 'am-internal']
    : $vars);

// No canonical redirects, so requests like /?name=am-draft reach the plugin instead of a 301.
add_filter('redirect_canonical', '__return_false');
