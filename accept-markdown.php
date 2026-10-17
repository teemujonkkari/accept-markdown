<?php

/**
 * Plugin Name: Accept Markdown
 * Description: Serves a public post as Markdown when the request's Accept header prefers text/markdown.
 * Version: 0.1.0
 * Requires at least: 7.1.3
 * Requires PHP: 8.4
 * License: GPL-2.0-or-later
 * Text Domain: accept-markdown
 */

declare(strict_types=1);

namespace AcceptMarkdown;

use AcceptMarkdown\Vendor\League\HTMLToMarkdown\Converter\TableConverter;
use AcceptMarkdown\Vendor\League\HTMLToMarkdown\HtmlConverter;
use WP_Post;

defined('ABSPATH') || exit;

require_once __DIR__.'/vendor_prefixed/vendor/autoload.php';

const NON_CONTENT = 'script|style|iframe|noscript|template|form|object|embed|svg';

/**
 * q-value of the most specific Accept entry matching $type (exact > type/* > *\/*), per RFC 9110.
 *
 * @param list<string> $entries lowercased Accept entries
 */
function quality(array $entries, string $type): float
{
    $major = strstr($type, '/', true);
    [$best, $q] = [-1, 0.0];

    foreach ($entries as $entry) {
        $params = array_map(trim(...), explode(';', $entry));
        $specificity = match (array_shift($params)) {
            $type => 2,
            "$major/*" => 1,
            '*/*' => 0,
            default => -1,
        };
        $qParam = array_find($params, fn (string $param): bool => preg_match('/^q\s*=/', $param) === 1);
        $entryQ = $qParam === null ? 1.0 : max(0.0, min(1.0, (float) trim(substr($qParam, strpos($qParam, '=') + 1))));

        if ($specificity > $best || ($specificity === $best && $best >= 0 && $entryQ > $q)) {
            [$best, $q] = [$specificity, $entryQ];
        }
    }

    return $q;
}

function wantsMarkdown(): bool
{
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $entries = explode(',', $accept);
    if ($accept === '' || strlen($accept) > 4096 || count($entries) > 32) {
        return false;
    }
    $markdown = quality($entries, 'text/markdown');

    return $markdown > 0 && $markdown > quality($entries, 'text/html');
}

/** The queried post if it is public and allowed, else null. The hard checks run before support and the filter. */
function eligiblePost(): ?WP_Post
{
    if (! in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)
        || is_admin() || wp_doing_ajax() || wp_doing_cron() || wp_is_serving_rest_request() || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)
        || is_feed() || is_preview() || is_embed() || ! is_singular()) {
        return null;
    }

    $post = get_queried_object();
    if (! $post instanceof WP_Post || $post->post_status !== 'publish' || ! is_post_type_viewable($post->post_type) || $post->post_password !== '') {
        return null;
    }

    return apply_filters('accept_markdown/allowed', post_type_supports($post->post_type, 'accept-markdown'), $post) ? $post : null;
}

/**
 * do_blocks() with no shortcode handlers, no third-party the_content filters, no comment blocks and no comment
 * queries. Nested blocks (Query Loops, Post Content, excerpts) run the_content and shortcodes themselves, so this
 * applies to the whole render. Everything is restored afterwards, so the HTML fallback is unaffected.
 */
function renderBlocks(string $content): string
{
    global $wp_filter;
    $theContent = $wp_filter['the_content'] ?? null;
    $wp_filter['the_content'] = new \WP_Hook();
    add_filter('the_content', 'do_blocks', 9);
    add_filter('the_content', 'wpautop');

    $noShortcodes = fn (): string => '';
    $noComments = fn (mixed $result, \WP_Comment_Query $query): array|int => $query->query_vars['count'] ? 0 : [];
    add_filter('pre_do_shortcode_tag', $noShortcodes, PHP_INT_MAX);
    add_filter('comments_pre_query', $noComments, PHP_INT_MAX, 2);

    $commentBlocks = array_filter(\WP_Block_Type_Registry::get_instance()->get_all_registered(), fn (\WP_Block_Type $type): bool => str_contains($type->name, 'comment'));
    $callbacks = array_map(fn (\WP_Block_Type $type): mixed => $type->render_callback, $commentBlocks);
    array_walk($commentBlocks, fn (\WP_Block_Type $type) => $type->render_callback = '__return_empty_string');

    try {
        return do_blocks($content);
    } finally {
        $wp_filter['the_content'] = $theContent;
        remove_filter('pre_do_shortcode_tag', $noShortcodes, PHP_INT_MAX);
        remove_filter('comments_pre_query', $noComments, PHP_INT_MAX);
        array_walk($commentBlocks, fn (\WP_Block_Type $type, string $name) => $type->render_callback = $callbacks[$name]);
    }
}

function render(WP_Post $post): string
{
    // Shortcodes never run during rendering; this removes the tags left as text, with any enclosed content.
    $html = strip_shortcodes(renderBlocks($post->post_content));
    if (! has_blocks($post->post_content)) {
        $html = wpautop($html);
    }

    // Remove non-content elements with everything inside them, innermost first; an unclosed one removes the rest.
    // This runs before kses because kses deletes disallowed tags but keeps the text between them.
    do {
        $html = preg_replace('#<('.NON_CONTENT.')\b[^>]*>(?:(?!<\1\b).)*?(?:</\1\s*>|$)#is', '', $html, -1, $count);
    } while ($count > 0);
    $html = wp_kses_post($html);

    // Flatten <pre> so syntax-highlighter markup doesn't leak into the code fence.
    $html = preg_replace_callback('#<pre\b[^>]*>(.*?)</pre>#is', fn (array $m): string => '<pre><code>'
        .htmlspecialchars(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8')
        .'</code></pre>', $html);

    // The converter has no <figure> handling, so an image would run into the next block.
    $html = preg_replace(['#<(/?)figure\b#i', '#<figcaption\b#i', '#</figcaption>#i'], ['<$1div', '<br><br><p', '</p>'], $html);

    $converter = new HtmlConverter([
        'header_style' => 'atx',
        'strip_tags' => true,
        'remove_nodes' => str_replace('|', ' ', NON_CONTENT),
        'hard_break' => true,
    ]);
    $converter->getEnvironment()->addConverter(new TableConverter());

    $title = wp_strip_all_tags(html_entity_decode($post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);

    return '# '.$title."\n\n".preg_replace("/\n(?:[ \t]*\n){2,}/", "\n\n", trim($converter->convert($html)))."\n";
}

add_action('init', function (): void {
    add_post_type_support('post', 'accept-markdown');
    add_post_type_support('page', 'accept-markdown');
}, 1);

// Same URL serves two representations: keep page caches out and tell HTTP caches to key on Accept.
add_action('plugins_loaded', function (): void {
    if (wantsMarkdown() && ! defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
});

add_action('send_headers', fn () => header('Vary: Accept', false));

// Last, so other plugins' and the theme's access gates (login walls, maintenance modes) redirect or exit first.
add_action('template_redirect', function (): void {
    if (! wantsMarkdown() || ! ($post = eligiblePost())) {
        return;
    }

    // Render as a signed-out visitor, so dynamic blocks never list private posts.
    wp_set_current_user(0);

    try {
        $markdown = (string) apply_filters('accept_markdown/output', render($post), $post);
    } catch (\Throwable) {
        return;
    }

    status_header(200);
    header('Content-Type: text/markdown; charset='.get_option('blog_charset'));
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('X-Robots-Tag: noindex');

    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
        echo $markdown;
    }
    exit;
}, PHP_INT_MAX);
