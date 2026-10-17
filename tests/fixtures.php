<?php

/**
 * Test fixtures for the HTTP suite. Run with:
 *   ACCEPT_MARKDOWN_TEST_PASSWORD=... wp eval-file tests/fixtures.php
 *
 * Idempotent: content is found by slug, created when missing and updated otherwise. Nothing is ever deleted or trashed.
 * No strict_types declaration: wp eval-file runs this through eval(), which rejects it.
 */

$password = getenv('ACCEPT_MARKDOWN_TEST_PASSWORD') ?: WP_CLI::error('Set ACCEPT_MARKDOWN_TEST_PASSWORD');

// Install the test mu-plugin and register its types in this process too.
$muPlugin = WPMU_PLUGIN_DIR.'/accept-markdown-test.php';
wp_mkdir_p(WPMU_PLUGIN_DIR);
copy(__DIR__.'/accept-markdown-test.php', $muPlugin);
if (! function_exists('am_test_register')) {
    require $muPlugin;
    am_test_register();
}

// Fixture content is written as-is; kses on save would strip the markup the tests need.
kses_remove_filters();

$admin = get_user_by('login', 'am-test-admin');
$adminId = $admin ? $admin->ID : wp_insert_user(['user_login' => 'am-test-admin', 'user_pass' => $password, 'user_email' => 'am-test-admin@example.invalid', 'role' => 'administrator']);
wp_update_user(['ID' => $adminId, 'user_pass' => $password, 'show_admin_bar_front' => 'true']);
wp_set_current_user($adminId);

function am_fixture(string $slug, string $title, string $content, array $args = []): int
{
    $type = $args['post_type'] ?? 'post';
    $existing = get_posts(['name' => $slug, 'post_type' => $type, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids']);
    $post = $args + ['post_name' => $slug, 'post_title' => $title, 'post_content' => $content, 'post_status' => 'publish', 'post_author' => get_current_user_id()];

    // Existing fixtures are updated in place, so fixture changes reach the site without deleting anything.
    return $existing === [] ? wp_insert_post($post, true) : wp_update_post(['ID' => $existing[0]] + $post, true);
}

$p = fn (string $text, string $class = ''): string => sprintf(
    "<!-- wp:paragraph%s -->\n<p%s>%s</p>\n<!-- /wp:paragraph -->\n\n",
    $class ? ' {"className":"'.$class.'"}' : '',
    $class ? ' class="'.$class.'"' : '',
    $text,
);

// Public content.
$postId = am_fixture('am-post', 'Accept Markdown Post', "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">A heading</h2>\n<!-- /wp:heading -->\n\n".$p('amPostMarker body text.'));
am_fixture('am-page', 'Accept Markdown Page', $p('amPageMarker body text.'), ['post_type' => 'page']);
am_fixture('am-doc', 'Opted-in CPT', $p('amDocMarker'), ['post_type' => 'am_doc']);
am_fixture('am-plain', 'CPT without support', $p('amPlainMarker'), ['post_type' => 'am_plain']);
am_fixture('am-removed', 'CPT with support removed', $p('amRemovedMarker'), ['post_type' => 'am_removed']);
am_fixture('am-internal', 'Non-public CPT', $p('amInternalMarker'), ['post_type' => 'am_internal']);
am_fixture('am-blocked', 'Blocked by the allow filter', $p('amBlockedMarker'));
am_fixture('am-throws', 'Output filter throws', $p('amThrowsMarker'));

// Non-public content.
am_fixture('am-draft', 'Draft', $p('amDraftMarker'), ['post_status' => 'draft']);
am_fixture('am-pending', 'Pending', $p('amPendingMarker'), ['post_status' => 'pending']);
am_fixture('am-private', 'amPrivateMarker private post', $p('amPrivateMarker'), ['post_status' => 'private']);
am_fixture('am-future', 'Scheduled', $p('amFutureMarker'), ['post_status' => 'future', 'post_date' => '2099-01-01 00:00:00']);
am_fixture('am-protected', 'Protected post', $p('amProtectedMarker'), ['post_password' => 'am-secret']);
am_fixture('am-protected-page', 'Protected page', $p('amProtectedPageMarker'), ['post_type' => 'page', 'post_password' => 'am-secret']);

// Dynamic blocks that would list the private post for a signed-in admin.
am_fixture('am-listing', 'Listing', "<!-- wp:latest-posts {\"postsToShow\":100} /-->\n\n<!-- wp:query {\"queryId\":1,\"query\":{\"perPage\":100,\"postType\":\"post\",\"inherit\":false}} -->\n<div class=\"wp-block-query\"><!-- wp:post-template -->\n<!-- wp:post-title /-->\n<!-- /wp:post-template --></div>\n<!-- /wp:query -->");

// A published post with an unpublished autosave.
$autosaveId = am_fixture('am-autosave', 'Autosaved post', $p('Published text.'));
wp_create_post_autosave(['post_ID' => $autosaveId, 'post_type' => 'post', 'post_title' => 'Autosaved post', 'post_content' => $p('amAutosaveMarker'), 'post_excerpt' => '']);

// Rendering.
$patternId = am_fixture('am-pattern', 'Pattern with a shortcode', $p('Pattern text [am_test /] amPatternText'), ['post_type' => 'wp_block']);
am_fixture('am-shortcodes', 'Shortcodes', $p('Self-closing: [am_test /]').$p('Enclosing: [am_test]amEnclosed[/am_test] done.')."<!-- wp:shortcode -->\n[am_test]\n<!-- /wp:shortcode -->\n\n<!-- wp:block {\"ref\":$patternId} /-->\n\n".$p('Render filter target. [am_test /]', 'am-render'));
am_fixture('am-classic', 'Classic post', "First classic paragraph.\n\nSecond classic paragraph.");
$unsafeId = am_fixture(
    'am-unsafe',
    "Unsafe <em>title</em> &amp; more\nsecond line",
    $p('Visible text. <a href="javascript:alert(1)">bad link</a> <a href="https://example.com/">good link</a>')
        ."<!-- amHtmlCommentMarker -->\n\n"
        ."<!-- wp:html -->\n<script>amScriptMarker</script><style>.x{content:'amStyleMarker'}</style><svg><svg><text>amSvgInnerMarker</text></svg><text>amSvgOuterMarker</text></svg><noscript>amNoscriptMarker</noscript>\n<!-- /wp:html -->\n\n"
        .$p('Text before the unclosed script.')
        ."<!-- wp:html -->\n<script>amUnclosedMarker\n<!-- /wp:html -->\n\n"
        .$p('amAfterUnclosedMarker'),
);
$comment = ['comment_post_ID' => $unsafeId, 'comment_content' => 'amCommentMarker [am_test /]', 'comment_approved' => 1, 'comment_author' => 'Commenter'];
$existingComment = get_comments(['post_id' => $unsafeId, 'number' => 1, 'fields' => 'ids']);
$existingComment === [] ? wp_insert_comment($comment) : wp_update_comment(['comment_ID' => $existingComment[0]] + $comment);
am_fixture('am-convert', 'Conversion', "<!-- wp:table -->\n<figure class=\"wp-block-table\"><table><thead><tr><th>Name</th><th>Value</th></tr></thead><tbody><tr><td>alpha</td><td>1</td></tr></tbody></table></figure>\n<!-- /wp:table -->\n\n"
    ."<!-- wp:code -->\n<pre class=\"wp-block-code\"><code><span class=\"hl-k\">if</span> (a &lt; b &amp;&amp; c) { <span class=\"hl-f\">run</span>(); }</code></pre>\n<!-- /wp:code -->\n\n"
    ."<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"https://example.com/image.png\" alt=\"An image\"/><figcaption class=\"wp-element-caption\">amCaptionMarker</figcaption></figure>\n<!-- /wp:image -->\n\n"
    .$p('Paragraph after the figure.'));

update_option('permalink_structure', '/%postname%/');
flush_rewrite_rules();

WP_CLI::success("Fixtures ready (am-post is #$postId).");
