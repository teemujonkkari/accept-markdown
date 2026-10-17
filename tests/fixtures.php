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

/** Creates or updates a fixture post, found by slug and type unless $lookup names other get_posts() arguments. */
function am_fixture(string $slug, string $title, string $content, array $args = [], array $lookup = []): int
{
    $lookup = $lookup ?: ['name' => $slug, 'post_type' => $args['post_type'] ?? 'post', 'post_status' => 'any'];
    $existing = get_posts($lookup + ['numberposts' => 1, 'fields' => 'ids']);
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
am_fixture('am-noisy', 'Noisy', $p('amNoisyMarker'));
am_fixture('am-cacheable-throws', 'Cacheable filter throws', $p('amCacheableThrowsMarker'));

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
// Non-content removal: nested closed elements, then several unclosed openers in a row (an Author can plant these).
am_fixture('am-removal', 'Removal', $p('Before.')
    ."<!-- wp:html -->\n<div><object data=\"x\"><object data=\"y\">amInnerObjectMarker</object>amOuterObjectMarker</object><p>Between.</p></div>\n<!-- /wp:html -->\n\n"
    .$p('amAfterNestedMarker')
    ."<!-- wp:html -->\n<script>amUnclosedOneMarker<script>amUnclosedTwoMarker<script>amUnclosedThreeMarker\n<!-- /wp:html -->\n\n"
    .$p('amAfterUnclosedMarker'));
am_fixture('am-convert', 'Conversion', "<!-- wp:table -->\n<figure class=\"wp-block-table\"><table><thead><tr><th>Name</th><th>Value</th></tr></thead><tbody><tr><td>alpha</td><td>1</td></tr></tbody></table></figure>\n<!-- /wp:table -->\n\n"
    ."<!-- wp:code -->\n<pre class=\"wp-block-code\"><code><span class=\"hl-k\">if</span> (a &lt; b &amp;&amp; c) { <span class=\"hl-f\">run</span>(); }</code></pre>\n<!-- /wp:code -->\n\n"
    ."<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"https://example.com/image.png\" alt=\"An image\"/><figcaption class=\"wp-element-caption\">amCaptionMarker</figcaption></figure>\n<!-- /wp:image -->\n\n"
    .$p('Paragraph after the figure.')
    // Sibling links with nothing between them, as flex-layout cards produce.
    ."<!-- wp:html -->\n<div><a href=\"tel:0401234567\">0401234567</a><a href=\"mailto:am@example.invalid\">am@example.invalid</a><a href=\"https://example.com/more\">amMoreLink</a></div>\n<!-- /wp:html -->\n");

// Slug collision: a draft with a published post's slug and an earlier date, which core allows (draft slugs are not
// made unique) and which puts both into the singular main query, the published post first.
am_fixture('am-collision', 'Slug collision', $p('amCollisionPublishedMarker'), ['post_date' => '2024-06-01 10:00:00']);
am_fixture('am-collision', 'Slug collision draft', $p('amCollisionDraftMarker'), ['post_status' => 'draft', 'post_date' => '2023-01-01 10:00:00'], ['title' => 'Slug collision draft', 'post_status' => 'draft']);

// Nested rendering: a Query Loop whose Post Content and Post Excerpt blocks render another post.
$nestedTag = term_exists('am-nested', 'post_tag') ?: wp_insert_term('am-nested', 'post_tag');
$nestedSourceId = am_fixture('am-nested-source', 'Nested source', $p('amNestedSourceText [am_test /]'));
wp_set_post_tags($nestedSourceId, 'am-nested');
am_fixture('am-nested', 'Nested', $p('Before the loop.')
    .'<!-- wp:query {"queryId":2,"query":{"perPage":10,"postType":"post","inherit":false,"taxQuery":{"post_tag":['.(int) $nestedTag['term_id'].']}}} -->'
    ."\n<div class=\"wp-block-query\"><!-- wp:post-template -->\n<!-- wp:post-content /-->\n<!-- wp:post-excerpt /-->\n<!-- /wp:post-template --></div>\n<!-- /wp:query -->\n\n"
    .$p('After the loop.'));

// Gated content: the test mu-plugin's membership-style gates show signed-out visitors a teaser instead of the body.
$membersTag = term_exists('am-members', 'post_tag') ?: wp_insert_term('am-members', 'post_tag');
$membersId = am_fixture('am-members', 'Members only', $p('amMembersBodyMarker'));
wp_set_post_tags($membersId, 'am-members');
am_fixture('am-members-loop', 'Members only, gated in the loop', $p('amMembersLoopMarker'));
am_fixture('am-members-index', 'Members index', $p('Before the members.')
    .'<!-- wp:query {"queryId":3,"query":{"perPage":10,"postType":"post","inherit":false,"taxQuery":{"post_tag":['.(int) $membersTag['term_id'].']}}} -->'
    ."\n<div class=\"wp-block-query\"><!-- wp:post-template -->\n<!-- wp:post-content /-->\n<!-- /wp:post-template --></div>\n<!-- /wp:query -->\n\n"
    .$p('After the members.'));

// Comment blocks, plus Latest Comments and a comment count, on a post with an approved comment.
$commentsId = am_fixture('am-comments', 'Comments', $p('Before the comments.')
    ."<!-- wp:comments -->\n<div class=\"wp-block-comments\"><!-- wp:comments-title /-->\n<!-- wp:comment-template -->\n<!-- wp:comment-author-name /-->\n<!-- wp:comment-content /-->\n<!-- /wp:comment-template -->\n<!-- wp:post-comments-form /--></div>\n<!-- /wp:comments -->\n\n"
    ."<!-- wp:latest-comments /-->\n\n<!-- wp:post-comments-count /-->\n\n<!-- wp:am/feedback /-->\n\n"
    .$p('After the comments.'));
$comment = ['comment_post_ID' => $commentsId, 'comment_content' => 'amBlockCommentMarker', 'comment_approved' => 1, 'comment_author' => 'amCommentAuthorMarker'];
$existingComment = get_comments(['post_id' => $commentsId, 'number' => 1, 'fields' => 'ids']);
$existingComment === [] ? wp_insert_comment($comment) : wp_update_comment(['comment_ID' => $existingComment[0]] + $comment);

// Rendering throws midway; the HTML fallback must still have comments, shortcodes and the_content filters.
$throwsRenderId = am_fixture('am-throws-render', 'Render throws', $p('Before the throw. [am_test /]')
    .$p('Throws during Markdown rendering.', 'am-throw')
    ."<!-- wp:comments -->\n<div class=\"wp-block-comments\"><!-- wp:comment-template -->\n<!-- wp:comment-content /-->\n<!-- /wp:comment-template --></div>\n<!-- /wp:comments -->");
$comment = ['comment_post_ID' => $throwsRenderId, 'comment_content' => 'amFallbackCommentMarker', 'comment_approved' => 1, 'comment_author' => 'Commenter'];
$existingComment = get_comments(['post_id' => $throwsRenderId, 'number' => 1, 'fields' => 'ids']);
$existingComment === [] ? wp_insert_comment($comment) : wp_update_comment(['comment_ID' => $existingComment[0]] + $comment);

update_option('permalink_structure', '/%postname%/');
flush_rewrite_rules();

WP_CLI::success("Fixtures ready (am-post is #$postId).");
