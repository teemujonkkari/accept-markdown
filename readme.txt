=== Accept Markdown ===
Contributors: teemujonkkari
Tags: markdown, ai, llm, content negotiation, api
Requires at least: 7.1.3
Tested up to: 7.1
Requires PHP: 8.4
Stable tag: 0.2.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Serves a public post as Markdown when the request's Accept header prefers text/markdown. Everything else gets the normal HTML page.

== Description ==

Accept Markdown is built for AI agents, LLM tooling and scripts that want a post's content without parsing a theme's HTML.

    $ curl -H 'Accept: text/markdown' https://example.com/hello-world/
    # Hello world!

    Welcome to WordPress. This is your first post.

It is deliberately small: one PHP file, no settings, no tables, no new URLs and no admin screens.

The plugin is distributed on GitHub, not in the WordPress.org plugin directory. Updates still arrive through the normal WordPress updater: the plugin checks the latest GitHub release twice a day and offers it on the Plugins screen.

**What gets served**

A post is served as Markdown only when all of these hold:

* The request is a front-end GET or HEAD for a single post, page or opted-in custom post type. Admin, REST, AJAX, cron, XML-RPC, feeds, previews and embeds are left alone.
* The post is published, on a publicly viewable post type, and has no password. Drafts, pending, scheduled, private and password-protected posts are never served, even to signed-in users.
* The `Accept` header prefers `text/markdown` over `text/html` (q-values per RFC 9110). A browser header, `*/*`, no header or `application/json` all get HTML. The plugin never answers 406.

**Rendering**

The content comes from the same `the_content` chain, in the main loop, that a signed-out visitor's HTML page uses, so content gates from membership and paywall plugins apply to the Markdown as well. After that the plugin only removes things: script, style, iframe, form, svg and similar elements with everything inside them, then a pass through the WordPress post kses rules, then conversion to Markdown. WordPress comments are never queried or rendered.

Output is `# <title>`, a blank line, then the body.

**Security model**

The guiding rule: the Markdown never exposes anything the public HTML page does not already expose.

* Rendering happens as a signed-out visitor, so dynamic blocks never list private posts.
* The handler runs last on `template_redirect`, so login walls and maintenance modes from other plugins run first.
* Markdown responses carry `Cache-Control: no-store, private`, `X-Content-Type-Options: nosniff`, a restrictive `Content-Security-Policy` and `X-Robots-Tag: noindex`. Every front-end response carries `Vary: Accept`.

**For developers**

Posts and pages are served straight away. Custom post types opt in with the post type support feature `accept-markdown`:

    register_post_type('book', [
        'public' => true,
        'supports' => ['title', 'editor', 'accept-markdown'],
    ]);

Opt any type out, including posts and pages:

    add_action('init', fn () => remove_post_type_support('page', 'accept-markdown'));

Two filters: `accept_markdown/allowed` decides per post (it cannot bypass the hard checks) and `accept_markdown/output` changes the final Markdown string.

Full documentation, the specification and the test suite are on [GitHub](https://github.com/teemujonkkari/accept-markdown).

== Installation ==

1. Upload the `accept-markdown` folder to `wp-content/plugins/`.
2. Activate the plugin. There is nothing to configure.
3. Check it works:

    curl -s -D - -H 'Accept: text/markdown' https://example.com/any-published-post/

The response should have `Content-Type: text/markdown` and start with `# ` and the post title.

Updates appear on the Plugins screen like any other plugin's, fetched from GitHub releases.

== Frequently Asked Questions ==

= Where do updates come from? =

From [GitHub releases](https://github.com/teemujonkkari/accept-markdown/releases), not WordPress.org. The plugin header's `Update URI` tells WordPress to ask the plugin instead of the directory, and the plugin fetches the latest release from the GitHub API, cached for 12 hours. Only a release zip from this repository is ever offered.

= Does this change my site for normal visitors? =

No. Browsers send an `Accept` header that prefers HTML, so they get the usual page. The only change to normal responses is the `Vary: Accept` header.

= Can it leak private, draft or password-protected content? =

No. Only published posts on publicly viewable post types without a password are served, and the checks run before any filter or post type support is consulted. Rendering happens as a signed-out visitor and goes through the same `the_content` chain as the public page, so membership plugins' restrictions apply too.

= Why does my share-buttons plugin's text appear in the Markdown? =

Because the plugin renders through the real `the_content` chain, the same one that lets membership plugins restrict content. Everything it adds already appears on the public page. Trim it with the `accept_markdown/output` filter.

= Will my page cache serve Markdown to browsers? =

No. Markdown responses are never cacheable (`no-store`) and the plugin defines `DONOTCACHEPAGE`. A cache that answers before plugins load can serve a cached HTML page to a Markdown client, but never the reverse.

= Is there a `.md` URL or a query parameter? =

No. The only trigger is the `Accept` header. There are no new URLs or rewrite rules.

== Changelog ==

= 0.2.1 =
* Author and plugin URI in the plugin header, so the Plugins screen shows them.

= 0.2.0 =
* Updates arrive through the WordPress updater from GitHub releases (Update URI header), with a working "View details" window.
* Security: the Markdown handler runs last on template_redirect, so login walls and maintenance modes from other plugins run first.
* Security: content is rendered through the real the_content chain in the main loop, so membership and paywall restrictions apply to the Markdown. Shortcodes and third-party content filters now run as on the page.
* License changed to MIT.
* Added readme.txt.

= 0.1.0 =
* Initial release.
