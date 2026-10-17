# Spec: Accept Markdown (WordPress plugin)

## Problem Statement

We want AI agents, LLM tooling and scripts to be able to fetch our WordPress posts as clean Markdown instead of full HTML pages. The off-the-shelf option we evaluated, roots/post-content-to-markdown, does this but we will not use it. A review of its code found:

- It serves password-protected post content to anyone who sends `Accept: text/markdown`.
- It runs shortcodes and blocks inside approved comments, which WordPress core never does.
- Its single-post comment feed bypasses its own post-type and per-post filters.
- Its feed cache invalidation deletes rows from the options table directly, so it silently does nothing on sites with a persistent object cache.
- It returns 406 by default for any Accept header it does not recognise. Because it runs before sitemaps and robots.txt, strict clients get errors on those endpoints.
- It loses paragraph breaks on classic-editor content because it skips `wpautop`.
- It rewrites `REQUEST_URI` globally to support `.md` URLs.
- It has 16 hooks, 14 filters, transients, direct SQL and three alternative request paths (`.md` suffix, query parameter, Accept header) plus Markdown feeds. That is far more surface than we need or want to audit.

We need our own plugin that does one thing: when a request for a public post says it prefers Markdown, return that post as Markdown. It must be small enough to read in one sitting and must never expose anything the public HTML page does not already expose.

## Solution

A single-file WordPress plugin, PHP 8.4 minimum, with no settings, no tables, no new URLs and no admin UI.

When a front-end GET or HEAD request for a single, published, publicly viewable, non-password-protected post, page or opted-in custom post type carries an `Accept` header that prefers `text/markdown` over `text/html`, the plugin responds with the post title and rendered content converted to Markdown. In every other case it does nothing and WordPress renders HTML exactly as before, except that every front-end response carries `Vary: Accept`.

Markdown responses are marked uncacheable, sniff-proof and sandboxed, so a misbehaving cache or browser cannot turn them into a problem for normal visitors.

## User Stories

1. As an AI agent, I want to request a post URL with `Accept: text/markdown` and get Markdown back, so that I can read the content without parsing HTML.
2. As an AI agent, I want the Markdown to start with the post title as a level-one heading, so that I know what the document is about.
3. As an AI agent, I want the post body rendered from its blocks, with paragraphs restored for classic-editor content, so that the Markdown reflects the authored content.
4. As an AI agent, I want tables in the post converted to Markdown tables, so that tabular data stays structured.
5. As an AI agent, I want code blocks converted to clean fenced code without syntax-highlighter markup, so that code samples are copyable.
6. As an AI agent, I want images inside figures to be separated from the following block and their captions kept, so that the Markdown structure is not broken.
7. As an AI agent, I want the response `Content-Type` to be `text/markdown` with the site charset, so that I can decode it correctly.
8. As an AI agent sending `Accept: text/html, text/markdown;q=0.5`, I want HTML, so that my stated preference is respected.
9. As an AI agent sending `Accept: text/html;q=0.5, text/markdown;q=0.9`, I want Markdown, so that q-values decide the outcome.
10. As an AI agent sending `Accept: text/markdown;q=0, */*`, I want HTML, so that an explicit refusal of Markdown is honoured.
11. As an AI agent sending `Accept: text/*;q=0.1, text/markdown`, I want Markdown, so that the most specific matching media range wins per RFC 9110.
12. As an AI agent sending `Accept: TEXT/Markdown`, I want Markdown, so that media type matching is case-insensitive.
13. As a browser user sending a normal browser Accept header (including `*/*`), I want the normal HTML page, so that the plugin is invisible to me.
14. As a client sending no Accept header, I want HTML, so that the default representation stays HTML.
15. As a client sending an Accept header that lists neither HTML nor Markdown (for example `application/json`), I want the normal HTML response and never a 406, so that sitemaps, robots.txt and other endpoints keep working.
16. As a site owner, I want password-protected posts never served as Markdown, so that protected content cannot leak through the Accept header.
17. As a site owner, I want only posts with status `publish` served as Markdown, and never a preview, so that drafts, pending, scheduled and private posts and unpublished autosave changes to published posts never leak, even to logged-in users.
18. As a site owner, I want only post types that are publicly viewable to be eligible, so that internal post types can never be exposed.
19. As a site owner, I want posts and pages served as Markdown out of the box, so that the whole public content of a typical site is available to agents without code.
20. As a developer, I want a custom post type to opt in by declaring post type support for Markdown, either in its registration `supports` list or with the standard add-post-type-support call, so that enabling a CPT uses the normal WordPress mechanism and lives next to the CPT definition.
21. As a developer, I want custom post types not to be served unless they opt in, so that installing a plugin that registers a public CPT does not silently expose it.
22. As a developer, I want to turn Markdown off for posts or pages with the standard remove-post-type-support call, so that the defaults are reversible without a custom filter.
23. As a developer, I want one filter that decides per post whether Markdown is allowed (receiving the default decision and the post), so that I can block or allow individual posts, for example by ID, slug or meta.
24. As a site owner, I want the security checks (published, viewable type, no password) to run before and independently of post type support and that filter, so that neither a CPT declaring support nor a careless filter can re-enable protected, unpublished or non-viewable content.
25. As a developer, I want one filter on the final Markdown string (receiving the Markdown and the post), so that I can add front matter or a footer.
26. As a site owner, I want comments not supported in any form (no comment text, authors, counts, forms or comment feeds), so that commenter-controlled content can never reach the Markdown output.
27. As a site owner, I want HTML comments in post content (including block delimiters) dropped from the output, so that hidden editor notes or markup never leak to Markdown consumers.
28. As a site owner, I want the content rendered through the same `the_content` chain, in the main loop, that the signed-out HTML page uses, so that content gates from membership and paywall plugins (hooked on `the_post` or `the_content`) apply to the Markdown exactly as they apply to the page.
29. As an AI agent, I want shortcodes and dynamic blocks rendered as on the page, so that galleries, shortcode-authored text and listings appear in the Markdown instead of being missing or left as `[tag]` text.
30. As a site owner, I accept that third-party `the_content` output (share buttons, related posts, tracking text) can reach the Markdown, so that no second rendering chain exists that access-control plugins know nothing about. Everything it adds already appears on the public HTML page.
31. As a site owner, I want feed requests (including single-post comment feeds) left untouched, so that RSS and Atom keep working and no comment content is exposed.
32. As a site owner, I want non-GET/HEAD requests ignored, so that form submissions and other methods behave exactly as before.
33. As a site owner, I want admin, REST, AJAX, cron and XML-RPC requests untouched, so that the plugin cannot affect the back end or the API.
34. As a site owner, I want the rendered HTML passed through the same post kses rules WordPress uses for untrusted authors before conversion, so that `javascript:` links and other disallowed protocols or markup cannot reach Markdown consumers, even when content was written by a user with `unfiltered_html`.
35. As a site owner, I want `script`, `style`, `iframe`, `noscript`, `template`, `form`, `object`, `embed` and `svg` elements removed together with everything inside them, before the kses pass, so that neither their markup nor their inner text (scripts, CSS, JSON data) ever appears in the output.
36. As a site owner, I want the post title stripped of tags, entity-decoded and collapsed to a single line, so that a title cannot inject extra Markdown structure.
37. As a site owner, I want every front-end response to carry `Vary: Accept`, so that standards-compliant caches never serve the Markdown representation to HTML visitors or the other way round.
38. As a site owner, I want Markdown responses cached by the HTTP cache in front of the site exactly like the HTML page, keyed by `Vary: Accept`, so that agents on a hosting platform such as Seravo are served from the cache like browsers are. I want one filter to send `Cache-Control: no-store, private` instead, for sites behind a CDN that ignores `Vary` (for example Cloudflare with a cache-everything rule).
39. As a site owner, I want WordPress page-cache plugins told not to cache Markdown requests, so that WP Super Cache and similar plugins do not store the Markdown body under the page URL.
40. As a site owner, I want Markdown responses to carry `X-Content-Type-Options: nosniff`, so that browsers never sniff the body as HTML.
41. As a site owner, I want Markdown responses to carry a restrictive `Content-Security-Policy` (`default-src 'none'; sandbox`), so that even a browser that renders the body cannot run anything from it.
42. As a site owner, I want Markdown responses to carry `X-Robots-Tag: noindex`, so that search engines that send a Markdown-preferring Accept header do not index a duplicate representation.
43. As a site owner, I want Accept headers longer than a fixed limit (4 KB) or with more than a fixed number of entries (32) treated as "not Markdown", so that a crafted header cannot cost noticeable CPU.
44. As a site owner, I want any exception thrown during rendering or conversion to cause a silent fallback to the normal HTML response, with no error details in the body, so that failures never leak internals or break the page.
45. As a site owner, I want a HEAD request that would produce Markdown to return the same headers with no body, so that HEAD stays consistent with GET.
46. As a site owner, I want the plugin to create no options, tables, cron jobs, rewrite rules or admin screens, and no transient other than the self-expiring update-check cache, so that there is nothing to configure, migrate or clean up on uninstall.
47. As a site owner, I want activation on PHP below 8.4 or WordPress below 7.1.3 to be refused by WordPress via the plugin header, so that the plugin never runs on an unsupported runtime.
48. As a site owner, I want the HTML-to-Markdown library bundled under a plugin-private namespace, so that another plugin shipping a different version cannot conflict with ours.
49. As a maintainer, I want the bundled library pinned to an exact version with a committed lock file, and the prefixed copy reproducible from a single build command, so that we can audit and update it deliberately.
50. As a maintainer, I want `composer audit` and the HTTP test suite to run in CI, so that known library vulnerabilities and regressions are caught before release.
51. As a maintainer, I want the whole plugin in one file of roughly 150 lines or less, so that a full security review takes minutes.
52. As a maintainer, I want strict types and full parameter and return type declarations throughout, so that type confusion bugs surface early.
53. As a maintainer, I want the plugin to read only the `Accept` header and the request method from the request, and never query parameters, cookies or other headers, so that the input surface is exactly what is documented.
54. As a site owner, I want post embed requests (the `/embed/` endpoint) left untouched, so that oEmbed consumers keep getting the embed HTML.
55. As an AI agent sending q-values above 1 or with whitespace around `=` (for example `text/markdown; q = 0.9`), I want them parsed leniently and clamped to the 0 to 1 range, so that a sloppy but readable header still negotiates correctly and an oversized q cannot outrank everything.
56. As a site owner, I want the Markdown always rendered as a signed-out visitor, so that dynamic blocks (latest posts, query loops) never list private posts for a signed-in user and the Markdown contains only public content.
57. As a site owner, I want updates offered through the normal WordPress updater from this repository's GitHub releases, with an `Update URI` header so WordPress never asks WordPress.org about this slug, so that I get updates without the plugin being in the directory and no directory plugin of the same name can ever be offered in its place.

## Implementation Decisions

- **Single module.** One plugin file containing three units: an Accept negotiator, an HTML-to-Markdown converter wrapper, and a request handler. No classes are required; namespaced functions with strict types are enough.
- **Accept negotiator.** Pure function: given the raw Accept header, returns whether Markdown is preferred. Algorithm, carried over from the reviewed prototype: for each of `text/markdown` and `text/html`, find the q-value of the most specific matching entry (exact match beats `type/*` beats `*/*`, with ties broken by higher q); Markdown wins only if its q is greater than zero and strictly greater than HTML's. Matching is case-insensitive. Whitespace around parameter names, `=` and values is ignored; q is clamped to the range 0 to 1, and a non-numeric q counts as 0. Empty, over-long (more than 4096 bytes) or over-split (more than 32 entries) headers mean "not Markdown". The prototype uses PHP 8.4 `array_find` for the q parameter lookup.
- **Eligibility, in this order, all hard requirements:** request method is GET or HEAD; not admin, REST, AJAX, cron, XML-RPC, feed, preview or embed; main query is singular; queried object is a post; post status is exactly `publish`; post type is publicly viewable per WordPress; post is not password-protected (checked on the post itself, not via the visitor's cookie, so a protected post is never served even to a visitor who knows the password). Only after all of these pass are post type support and the allow filter consulted.
- **Post type opt-in via post type support.** Eligibility per post type is a WordPress post type support feature named `accept-markdown`. The plugin adds that support to `post` and `page` on `init` at an early priority, after core registers its post types. A custom post type opts in through its `supports` list or the add-post-type-support call; any type, including `post` and `page`, opts out with the remove-post-type-support call at the default `init` priority or later. No other configuration exists. Support is necessary but not sufficient: a type that declares support still has to pass the hard checks, so a non-viewable CPT declaring support is never served. Attachments are excluded automatically because their status is never `publish`.
- **Filters (exactly three):**
  - A cacheable filter receiving `true` and the post. Returning false sends `Cache-Control: no-store, private` on that Markdown response, for sites behind a cache that ignores `Vary`.
  - An allow filter receiving the default boolean (true when the post's type has Markdown support) and the post. It is for per-post decisions (block or allow by ID, slug, meta), but it cannot bypass the hard eligibility checks.
  - An output filter receiving the final Markdown string and the post.
  - All use the `accept_markdown/` prefix: `accept_markdown/allowed`, `accept_markdown/output` and `accept_markdown/cacheable`.
- **Signed-out rendering.** Once a post passes every eligibility check, the handler sets the current user to 0 before rendering. Nothing is restored. Core `WP_Query` adds private posts for signed-in users who can read them, so without this a Latest Posts or Query Loop block would list private posts in the Markdown. It is done in the handler rather than in `plugins_loaded` so admin and REST requests that happen to send a Markdown Accept header stay signed in and untouched. Side effect: a Markdown request whose rendering throws falls back to the signed-out HTML page, which is acceptable.
- **Rendering.** The same pipeline as the signed-out HTML page, followed only by removals:
  1. Run the main loop (`have_posts`, `the_post`) with the current user set to 0 and apply the real `the_content` chain to `get_the_content()`. This is what a theme does, so every gate hooked on `the_post` (WooCommerce Memberships) or `the_content` (Paid Memberships Pro, MemberPress, Restrict Content Pro, Members) applies. Nested blocks (Query Loops with Post Content or Post Excerpt, Latest Posts excerpts) call `the_content` for the posts they render, so they go through the same gates. Blocks, shortcodes, texturize, smilies, auto-embeds and third-party content filters run as on the page. While the loop runs:
     - every registered block whose name contains `comment` renders empty (comments, comment template, comments title, comment form, latest comments, comment counts);
     - comment queries return nothing, which covers third-party blocks that list comments under another name, such as reviews.

     Both are restored in a `finally` block and the loop is rewound, so the HTML fallback after an exception renders normally.
  2. Remove the story 35 elements together with their content. An element with no closing tag removes everything after its opening tag, so malformed markup fails closed. This must run before kses, because kses deletes disallowed tags but keeps the text between them.
  3. Pass the HTML through the WordPress post kses rules.
  4. Apply the `<pre>` flattening and `<figure>`/`<figcaption>` block fixes from the prototype.
  5. Convert with comment preservation off, so HTML comments and block delimiters are dropped. Consecutive blank lines, including whitespace-only lines, are collapsed to at most one.

  Never done: a private rendering chain built from raw `post_content`. The plugin only removes from what the page renders and never reconstructs content, because a reconstruction bypasses every access gate other plugins add. WordPress comments are never queried or rendered.
- **Converter.** league/html-to-markdown, prefixed into the plugin-private `AcceptMarkdown\Vendor` namespace with php-scoper (or an equivalent tool), pinned exact version, ATX headings, strip tags on, hard breaks on, table converter registered, and the story 35 list also set as the converter's remove-nodes option as a second line of defence after rendering step 2. There is one autoload path and no runtime class detection.
- **Hooks (exactly six):**
  - `update_plugins_github.com`, which WordPress calls instead of asking WordPress.org because of the `Update URI` plugin header. It fetches the latest release from the GitHub API (cached in a site transient for 12 hours), takes only the tag name and the release notes from the response, builds the package URL from the tag under this repository's releases and checks that URL is one of the release's assets. WordPress compares the version and offers the update. It runs only during update checks (admin and cron), never on front-end requests, and the fetch is the plugin's only outbound HTTP request.
  - `plugins_api` for this slug only, so the "View details" window on the Plugins screen shows the plugin's description and the latest release's notes (escaped) from the same cache instead of asking WordPress.org.
  - `init` at an early priority adding Markdown support to `post` and `page`.
  - An early hook (`plugins_loaded`) that tells page-cache plugins not to cache when the negotiator says Markdown.
  - `send_headers` adding `Vary: Accept` to every front-end response.
  - `template_redirect` at priority `PHP_INT_MAX` running the handler, so access gates that other plugins or the theme hook there (login walls, maintenance modes) redirect or exit first. Plugins load alphabetically, so at the default priority the handler would run and exit before most of them.

  The temporary filters added and removed around the loop (rendering step 1) do not count as permanent hooks.
- **Response contract for Markdown:**
  - Status 200.
  - Headers:
    - `Content-Type: text/markdown; charset=<blog charset>`
    - `Vary: Accept`
    - no `Cache-Control` of its own, so the HTTP cache applies the same policy as to the HTML page; `Cache-Control: no-store, private` when the cacheable filter returns false
    - `X-Content-Type-Options: nosniff`
    - `Content-Security-Policy: default-src 'none'; sandbox`
    - `X-Robots-Tag: noindex`
  - Body: `# <title>`, a blank line, then the converted content. HEAD omits the body.
- **Failure behaviour.** The handler builds the full Markdown string before sending any header. If anything throws, it returns without output, and WordPress continues with the normal HTML response. Nothing is logged to the response.
- **No persistence.** No caching layer of its own (no object cache, no transient other than the update check). The HTTP cache in front of the site is the cache; conversion cost is a few milliseconds per post.
- **Plugin header.** `Requires PHP: 8.4` and `Requires at least: 7.1.3`. No settings link, no text domain beyond the header.

## Testing Decisions

- **One seam: HTTP.** All tests are black-box HTTP requests against a WordPress site with pretty permalinks and the plugin active. They assert only status, response headers and body text. No test calls plugin functions directly or inspects internal state.
- **Target site.** The base URL comes from the `ACCEPT_MARKDOWN_BASE_URL` environment variable and defaults to `http://accept-markdown.local` (the Local dev site). CI points it at a disposable WordPress Playground instance.
- **A good test** sends one request with specific headers and asserts on the observable response. The Accept negotiation edge cases (stories 8 to 15, 43, 55) are covered as a table of Accept values to expected representation, each one HTTP request.
- **Test mu-plugin.** One file in the test directory, installed into `wp-content/mu-plugins/`, holds every test hook: the test CPTs, a test shortcode, a `the_content` and a `render_block` filter that output markers, and the allow and output filters used by the tests, a `template_redirect` login wall registered after the plugin's handler, and two membership-style content gates: a `the_content` filter that swaps tagged posts for a teaser and a `the_post` action that rewrites one post in the loop.
- **Fixtures** are created by one wp-cli script, run from the Local site shell for local dev and from the Playground blueprint in CI:
  - a normal published post
  - a published page
  - a published post of a public CPT that declares Markdown support
  - a published post of a public CPT that does not declare it
  - a published post of a non-public CPT that declares it
  - a password-protected post and a password-protected page
  - a draft
  - a private post
  - a scheduled post
  - a post containing a table, a highlighted code block, a figure with caption, a classic-editor post without `<p>` tags, a `javascript:` link, a registered shortcode (self-closing and enclosing), a shortcode block, an HTML comment with a marker string, and an approved comment with a marker string
  - a post containing an inline `<script>`, an inline `<style>` and an inline `<svg>`, each with a marker string inside, plus an unclosed `<script>` followed by a marker
  - a synced pattern containing a registered shortcode, inserted into a published post
  - a published post with a pending autosave that contains a marker string
  - a private post with a marker string in its title, plus a published post containing a Latest Posts block and a Query Loop block, so both would list the private post for a signed-in administrator
  - a post gated on `the_content`, a post gated on `the_post`, and a published post whose Query Loop renders the first one through a Post Content block
- **Must-have security tests:**
  - Protected posts and pages, drafts, private and scheduled posts, and the non-public CPT that declares support each return HTML (never Markdown) with Markdown Accept.
  - The post, the page and the opted-in public CPT return Markdown; the public CPT without support returns HTML.
  - A public CPT that declares support and then has it removed with the remove-post-type-support call returns HTML.
  - Shortcodes render as on the page: the test shortcode's output appears and no `[tag]` text is left.
  - The gated posts return their teaser and never their body in Markdown, signed in or not, and so does the published post whose Query Loop renders a gated post.
  - The approved comment's marker and the HTML comment's marker never appear in the Markdown.
  - A Query Loop with Post Content and Post Excerpt rendering another post runs that post through the same chain, so its shortcode output and `the_content` output appear.
  - A post with Comments, Comment Template, Comments Title, comment form, Latest Comments, comment count and a third-party comment-listing block renders as its own text only: no comment text, author, count or form.
  - When rendering throws midway, the HTML fallback still shows the post and its comments, so everything switched off during rendering is restored and the loop is rewound.
  - Output from a third-party `the_content` filter registered by a test mu-plugin appears in the Markdown. This pins story 30, so a change to it is a deliberate decision.
  - Output from a third-party `render_block` filter registered by a test mu-plugin does appear in the Markdown. This pins the accepted trade-off in Further Notes, so a change to it is a deliberate decision.
  - The script, style, svg and unclosed-script markers never appear in the Markdown.
  - The shortcode inside the synced pattern is executed and no `[tag]` text is left.
  - A preview request for the post with the pending autosave, made by a logged-in editor with Markdown Accept, returns HTML, and the autosave marker never appears in any Markdown response.
  - The post's `/embed/` URL returns HTML with Markdown Accept.
  - The post with the Latest Posts and Query Loop blocks, requested with Markdown Accept by a signed-in administrator, never contains the private post's marker.
  - The same administrator's normal HTML request still shows the admin bar, so signing out applies only to Markdown requests.
  - The `javascript:` link is absent from the Markdown.
  - Every Markdown response carries the full header set from the response contract.
  - Every HTML response carries `Vary: Accept`.
  - The sitemap and robots.txt respond normally with `Accept: application/xml` and `Accept: text/plain`.
  - A 10 KB Accept header returns HTML.
  - The allow filter returning true for a password-protected post still returns HTML.
  - A `template_redirect` login wall registered after the plugin's handler, at the default priority, redirects a signed-out Markdown request to the login page instead of serving Markdown.
- **Prior art:** the roots plugin's Pest suite used the same pattern (a request helper over a Playground server, parsed status and headers, Pest expectations). We reuse the pattern, not the code.
- **CI** runs the suite against the minimum supported PHP (8.4) and the latest PHP, plus `composer audit`.

## Out of Scope

- `.md` URL suffixes, `?format=markdown` or any other query parameter, and any new URL or rewrite rule.
- Markdown feeds, comment feeds, archive, category, tag, author, search and front-page listing output.
- Comments in any form.
- 406 Not Acceptable responses or any other change to non-Markdown responses beyond `Vary: Accept`.
- Advertising the Markdown representation (Link header, `<link rel="alternate">`, RSS autodiscovery).
- Diagnostic headers such as `X-Markdown-Source`.
- A conversion or response cache.
- Settings pages, options, WP-CLI commands.
- Front matter or metadata (author, dates, taxonomies) in the output; the output filter is the extension point if needed.
- Rate limiting; that belongs to the web server or CDN.
- Multisite-specific behaviour beyond what works per site by default.

## Further Notes

- The plugin grew out of a 108-line prototype from the review session, which validated the Accept negotiation algorithm against nine edge cases. Everything it had (the negotiator, the `<pre>` and `<figure>` fixes, the converter setup, the cache headers) was carried over, and the prototype has been removed.
- Known trade-off of running the real `the_content` chain: share buttons, related posts and similar text from other plugins can appear in the Markdown. Accepted deliberately on 2026-10-17 after a security review. The previous design rendered from raw `post_content` through a private chain and served members-only posts in full, because membership plugins gate on `the_post` and `the_content` and know nothing about a second chain. Removing from the page's output is safe; reconstructing it is not. The output filter is the place to trim.
- Known trade-off of auto-embeds: an embed whose markup is only an iframe (for example YouTube) is removed with the iframe and leaves nothing. Embeds with a fallback blockquote keep their text and link.
- Dynamic blocks are kept by decision: they run through `do_blocks`, so blocks such as latest posts or query loops appear in the Markdown. They are core or plugin PHP that already runs on the public HTML page, so they expose nothing new.
- Known trade-off of `do_blocks`: it runs third-party `render_block` filters, so their output can reach the Markdown. This is accepted as part of keeping dynamic blocks, for the same reason: that output already appears on the public HTML page. A test pins this behaviour.
- Known limitation with page-cache plugins: a cache that serves pages from `advanced-cache.php`, such as WP Super Cache, answers before `plugins_loaded`, so a Markdown request can get a cached HTML page. The reverse never happens, because `DONOTCACHEPAGE` keeps Markdown out of those caches. Agents may therefore sometimes get HTML on such sites. This is accepted; fixing it would mean hooking into each cache plugin.
- Markdown is cacheable by default since 0.3.0. Until then it was `no-store`, which removed the cross-serving risk with `Vary`-ignoring CDNs but made every agent request hit PHP. The primary hosting target (Seravo's Nginx cache) honours `Vary: Accept`, verified on a production site, and the cacheable filter restores `no-store` where a CDN does not. Only the HTTP cache is affected: `DONOTCACHEPAGE` still keeps Markdown out of WordPress page-cache plugins, which key on the URL alone.
- Naming: plugin name "Accept Markdown", slug and text domain `accept-markdown`, PHP namespace `AcceptMarkdown`, filter prefix `accept_markdown/`, post type support feature `accept-markdown`.
