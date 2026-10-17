<?php

/**
 * Plugin Name: Accept Markdown
 * Plugin URI: https://github.com/teemujonkkari/accept-markdown
 * Description: Serves a public post as Markdown when the request's Accept header prefers text/markdown.
 * Version: 0.4.0
 * Author: Teemu Jönkkäri
 * Author URI: https://github.com/teemujonkkari
 * Requires at least: 7.1.3
 * Requires PHP: 8.4
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: accept-markdown
 * Update URI: https://github.com/teemujonkkari/accept-markdown
 */

declare(strict_types=1);

namespace AcceptMarkdown;

use AcceptMarkdown\Vendor\League\HTMLToMarkdown\Converter\TableConverter;
use AcceptMarkdown\Vendor\League\HTMLToMarkdown\HtmlConverter;
use WP_Post;

defined('ABSPATH') || exit;

require_once __DIR__.'/vendor_prefixed/vendor/autoload.php';

const NON_CONTENT = 'script|style|iframe|noscript|template|form|object|embed|svg';
const REPO = 'teemujonkkari/accept-markdown';

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
 * The content through the real the_content chain in the main loop, exactly as the signed-out HTML page gets it, so
 * every access gate a plugin hooks on the query or the_content applies, inside nested blocks too. Only comments are
 * switched off: comment blocks render empty and comment queries return nothing. Both are restored and the loop is
 * rewound afterwards, so the HTML fallback is unaffected.
 */
function renderContent(): string
{
    global $wp_query;
    $noComments = fn (mixed $result, \WP_Comment_Query $query): array|int => $query->query_vars['count'] ? 0 : [];
    add_filter('comments_pre_query', $noComments, PHP_INT_MAX, 2);

    $commentBlocks = array_filter(\WP_Block_Type_Registry::get_instance()->get_all_registered(), fn (\WP_Block_Type $type): bool => str_contains($type->name, 'comment'));
    $callbacks = array_map(fn (\WP_Block_Type $type): mixed => $type->render_callback, $commentBlocks);
    array_walk($commentBlocks, fn (\WP_Block_Type $type) => $type->render_callback = '__return_empty_string');

    try {
        $html = '';
        while (have_posts()) {
            the_post();
            $html = apply_filters('the_content', get_the_content());
        }

        return $html;
    } finally {
        remove_filter('comments_pre_query', $noComments, PHP_INT_MAX);
        array_walk($commentBlocks, fn (\WP_Block_Type $type, string $name) => $type->render_callback = $callbacks[$name]);
        $wp_query->rewind_posts();
        $wp_query->in_the_loop = false;
    }
}

function render(WP_Post $post): string
{
    $html = renderContent();

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

    // Adjacent links with nothing between them are kept apart by CSS on the page; give them a space here.
    $html = preg_replace('#</a>(?=<a\b)#i', '</a> ', $html);

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

/**
 * The latest GitHub release as version, package URL and notes, cached for 12 hours; empty when there is none usable.
 * Only the tag name and the notes are taken from the API: the package URL is built from the tag under this
 * repository and must be one of the release's assets, so nothing outside the repository can be offered as an update.
 *
 * @return array{version: string, package: string, notes: string}|array{}
 */
function latestRelease(): array
{
    $release = get_site_transient('accept_markdown_release');
    if (is_array($release)) {
        return $release;
    }

    $response = wp_remote_get('https://api.github.com/repos/'.REPO.'/releases/latest', ['timeout' => 5, 'headers' => ['Accept' => 'application/vnd.github+json']]);
    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    $version = ltrim((string) ($body['tag_name'] ?? ''), 'v');
    $package = 'https://github.com/'.REPO."/releases/download/v$version/accept-markdown-$version.zip";
    $release = preg_match('/^\d+\.\d+\.\d+$/', $version) === 1 && in_array($package, array_column($body['assets'] ?? [], 'browser_download_url'), true)
        ? ['version' => $version, 'package' => $package, 'notes' => (string) ($body['body'] ?? '')]
        : [];
    set_site_transient('accept_markdown_release', $release, 12 * HOUR_IN_SECONDS);

    return $release;
}

// Updates from GitHub releases, through the normal WordPress updater. The Update URI header makes WordPress ask this
// filter instead of WordPress.org; WordPress compares the version itself.
add_filter('update_plugins_github.com', function (array|false $update, array $plugin): array|false {
    if ($plugin['UpdateURI'] !== 'https://github.com/'.REPO || ($release = latestRelease()) === []) {
        return $update;
    }

    return [
        'slug' => 'accept-markdown',
        'version' => $release['version'],
        'url' => 'https://github.com/'.REPO.'/releases/tag/v'.$release['version'],
        'package' => $release['package'],
    ];
}, 10, 2);

// The "View details" window on the Plugins screen, which would otherwise ask WordPress.org about this slug.
add_filter('plugins_api', function (mixed $result, string $action, object $args): mixed {
    if ($action !== 'plugin_information' || ($args->slug ?? null) !== 'accept-markdown' || ($release = latestRelease()) === []) {
        return $result;
    }
    $header = get_file_data(__FILE__, ['name' => 'Plugin Name', 'description' => 'Description']);

    return (object) [
        'name' => $header['name'],
        'slug' => 'accept-markdown',
        'version' => $release['version'],
        'homepage' => 'https://github.com/'.REPO,
        'download_link' => $release['package'],
        'sections' => [
            'description' => wpautop(esc_html($header['description'])),
            'changelog' => '<h4>'.esc_html($release['version']).'</h4>'.wpautop(esc_html($release['notes'])),
        ],
    ];
}, 10, 3);

// Same URL serves two representations. HTTP caches key on Accept; WordPress page-cache plugins cannot, so they must
// not store Markdown under the page URL.
add_action('plugins_loaded', function (): void {
    if (wantsMarkdown() && ! defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
});

add_action('send_headers', fn () => header('Vary: Accept', false));

// The same alternate in the HTML head, next to the JSON and RSS ones WordPress already prints there.
add_action('wp_head', function (): void {
    if ($post = eligiblePost()) {
        printf('<link rel="alternate" type="text/markdown" href="%s" />'."\n", esc_url(get_permalink($post)));
    }
});

// Last, so other plugins' and the theme's access gates (login walls, maintenance modes) redirect or exit first.
add_action('template_redirect', function (): void {
    $post = eligiblePost();
    if ($post) {
        // Tell clients that look for alternates (HEAD included) that this URL also serves Markdown.
        header(sprintf('Link: <%s>; rel="alternate"; type="text/markdown"', get_permalink($post)), false);
    }
    if (! $post || ! wantsMarkdown()) {
        return;
    }

    // Render as a signed-out visitor, so dynamic blocks never list private posts. The main query already ran as the
    // visitor, so run it again signed out: gates that decide at query time then see the same visitor as the rest of
    // the render. If the post is gone for a signed-out visitor, serve the HTML page.
    global $wp_query;
    wp_set_current_user(0);
    $wp_query->get_posts();
    if (($wp_query->posts[0] ?? null)?->ID !== $post->ID) {
        return;
    }

    try {
        $markdown = (string) apply_filters('accept_markdown/output', render($post), $post);
    } catch (\Throwable) {
        return;
    }

    status_header(200);
    header('Content-Type: text/markdown; charset='.get_option('blog_charset'));
    // Cached like the HTML page, keyed by Vary: Accept. Opt out behind a cache that ignores Vary.
    if (! apply_filters('accept_markdown/cacheable', true, $post)) {
        header('Cache-Control: no-store, private');
    }
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('X-Robots-Tag: noindex');

    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
        echo $markdown;
    }
    exit;
}, PHP_INT_MAX);
