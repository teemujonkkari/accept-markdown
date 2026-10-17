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

A single-file WordPress plugin, PHP 8.4 minimum, with no settings, no database writes, no new URLs and no admin UI.

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
28. As a site owner, I want shortcodes never executed, so that no shortcode handler from any plugin or theme runs on a Markdown request.
29. As an AI agent, I want registered shortcode tags removed from the Markdown instead of appearing as literal `[tag]` text, including tags that arrive through rendered blocks such as synced patterns, so that the output contains no unrendered placeholders.
30. As a site owner, I want no third-party `the_content` filters run on a Markdown request, so that other plugins cannot inject share buttons, ads, tracking or anything else into the output.
31. As a site owner, I want feed requests (including single-post comment feeds) left untouched, so that RSS and Atom keep working and no comment content is exposed.
32. As a site owner, I want non-GET/HEAD requests ignored, so that form submissions and other methods behave exactly as before.
33. As a site owner, I want admin, REST, AJAX, cron and XML-RPC requests untouched, so that the plugin cannot affect the back end or the API.
34. As a site owner, I want the rendered HTML passed through the same post kses rules WordPress uses for untrusted authors before conversion, so that `javascript:` links and other disallowed protocols or markup cannot reach Markdown consumers, even when content was written by a user with `unfiltered_html`.
35. As a site owner, I want `script`, `style`, `iframe`, `noscript`, `template`, `form`, `object`, `embed` and `svg` elements removed together with everything inside them, before the kses pass, so that neither their markup nor their inner text (scripts, CSS, JSON data) ever appears in the output.
36. As a site owner, I want the post title stripped of tags, entity-decoded and collapsed to a single line, so that a title cannot inject extra Markdown structure.
37. As a site owner, I want every front-end response to carry `Vary: Accept`, so that standards-compliant caches never serve the Markdown representation to HTML visitors or the other way round.
38. As a site owner, I want Markdown responses to carry `Cache-Control: no-store, private`, so that CDNs that ignore `Vary` (for example Cloudflare by default) can never cache a Markdown body under a normal page URL.
39. As a site owner, I want WordPress page-cache plugins told not to cache Markdown requests, so that WP Super Cache and similar plugins do not store the Markdown body under the page URL.
40. As a site owner, I want Markdown responses to carry `X-Content-Type-Options: nosniff`, so that browsers never sniff the body as HTML.
41. As a site owner, I want Markdown responses to carry a restrictive `Content-Security-Policy` (`default-src 'none'; sandbox`), so that even a browser that renders the body cannot run anything from it.
42. As a site owner, I want Markdown responses to carry `X-Robots-Tag: noindex`, so that search engines that send a Markdown-preferring Accept header do not index a duplicate representation.
43. As a site owner, I want Accept headers longer than a fixed limit (4 KB) or with more than a fixed number of entries (32) treated as "not Markdown", so that a crafted header cannot cost noticeable CPU.
44. As a site owner, I want any exception thrown during rendering or conversion to cause a silent fallback to the normal HTML response, with no error details in the body, so that failures never leak internals or break the page.
45. As a site owner, I want a HEAD request that would produce Markdown to return the same headers with no body, so that HEAD stays consistent with GET.
46. As a site owner, I want the plugin to create no options, transients, tables, cron jobs, rewrite rules or admin screens, so that there is nothing to configure, migrate or clean up on uninstall.
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

## Implementation Decisions

- **Single module.** One plugin file containing three units: an Accept negotiator, an HTML-to-Markdown converter wrapper, and a request handler. No classes are required; namespaced functions with strict types are enough.
- **Accept negotiator.** Pure function: given the raw Accept header, returns whether Markdown is preferred. Algorithm, carried over from the reviewed prototype: for each of `text/markdown` and `text/html`, find the q-value of the most specific matching entry (exact match beats `type/*` beats `*/*`, with ties broken by higher q); Markdown wins only if its q is greater than zero and strictly greater than HTML's. Matching is case-insensitive. Whitespace around parameter names, `=` and values is ignored; q is clamped to the range 0 to 1, and a non-numeric q counts as 0. Empty, over-long (more than 4096 bytes) or over-split (more than 32 entries) headers mean "not Markdown". The prototype uses PHP 8.4 `array_find` for the q parameter lookup.
- **Eligibility, in this order, all hard requirements:** request method is GET or HEAD; not admin, REST, AJAX, cron, XML-RPC, feed, preview or embed; main query is singular; queried object is a post; post status is exactly `publish`; post type is publicly viewable per WordPress; post is not password-protected (checked on the post itself, not via the visitor's cookie, so a protected post is never served even to a visitor who knows the password). Only after all of these pass are post type support and the allow filter consulted.
- **Post type opt-in via post type support.** Eligibility per post type is a WordPress post type support feature named `accept-markdown`. The plugin adds that support to `post` and `page` on `init` at an early priority, after core registers its post types. A custom post type opts in through its `supports` list or the add-post-type-support call; any type, including `post` and `page`, opts out with the remove-post-type-support call at the default `init` priority or later. No other configuration exists. Support is necessary but not sufficient: a type that declares support still has to pass the hard checks, so a non-viewable CPT declaring support is never served. Attachments are excluded automatically because their status is never `publish`.
- **Filters (exactly two):**
  - An allow filter receiving the default boolean (true when the post's type has Markdown support) and the post. It is for per-post decisions (block or allow by ID, slug, meta), but it cannot bypass the hard eligibility checks.
  - An output filter receiving the final Markdown string and the post.
  - Both use the `accept_markdown/` prefix: `accept_markdown/allowed` and `accept_markdown/output`.
- **Signed-out rendering.** Once a post passes every eligibility check, the handler sets the current user to 0 before rendering. Nothing is restored. Core `WP_Query` adds private posts for signed-in users who can read them, so without this a Latest Posts or Query Loop block would list private posts in the Markdown. It is done in the handler rather than in `plugins_loaded` so admin and REST requests that happen to send a Markdown Accept header stay signed in and untouched. Side effect: a Markdown request whose rendering throws falls back to the signed-out HTML page, which is acceptable.
- **Rendering.** A fixed chain of core functions only, never the `the_content` filter chain:
  1. Render blocks with `do_blocks` in isolation, so dynamic core blocks produce their HTML as on the page. Nested blocks (Query Loops with Post Content or Post Excerpt, Latest Posts excerpts) run `the_content` and shortcodes on other posts by themselves, so the restrictions must hold for the whole render, not just the outer content. While `do_blocks` runs:
     - the core `pre_do_shortcode_tag` filter makes every shortcode output nothing, so no shortcode handler executes anywhere, including from a `render_block` filter that calls `do_shortcode`;
     - `the_content` holds only core's `do_blocks` and `wpautop`, so nested content renders without any third-party content filter;
     - every registered block whose name contains `comment` renders empty (comments, comment template, comments title, comment form, latest comments, comment counts);
     - comment queries return nothing, which covers third-party blocks that list comments under another name, such as reviews.

     All of this is restored in a `finally` block, so the HTML fallback after an exception renders normally.
  2. Strip registered shortcodes with the core strip-shortcodes function, removing the tags left as text (from the post itself, synced patterns or nested posts). Enclosing shortcodes are removed together with their enclosed content.
  3. Apply `wpautop` only when the raw content has no blocks, so classic-editor posts keep their paragraphs.
  4. Remove the story 35 elements together with their content. An element with no closing tag removes everything after its opening tag, so malformed markup fails closed. This must run before kses, because kses deletes disallowed tags but keeps the text between them.
  5. Pass the HTML through the WordPress post kses rules.
  6. Apply the `<pre>` flattening and `<figure>`/`<figcaption>` block fixes from the prototype.
  7. Convert with comment preservation off, so HTML comments and block delimiters are dropped. Consecutive blank lines, including whitespace-only lines, are collapsed to at most one.

  Not run: shortcodes, auto-embeds (bare embed URLs stay as plain links), texturize, smilies, and any third-party content filter. WordPress comments are never queried or rendered.
- **Converter.** league/html-to-markdown, prefixed into the plugin-private `AcceptMarkdown\Vendor` namespace with php-scoper (or an equivalent tool), pinned exact version, ATX headings, strip tags on, hard breaks on, table converter registered, and the story 35 list also set as the converter's remove-nodes option as a second line of defence after rendering step 4. There is one autoload path and no runtime class detection.
- **Hooks (exactly four):**
  - `init` at an early priority adding Markdown support to `post` and `page`.
  - An early hook (`plugins_loaded`) that tells page-cache plugins not to cache when the negotiator says Markdown.
  - `send_headers` adding `Vary: Accept` to every front-end response.
  - `template_redirect` at default priority running the handler.

  The temporary filters added and removed around `do_blocks` (rendering step 1) do not count as permanent hooks.
- **Response contract for Markdown:**
  - Status 200.
  - Headers:
    - `Content-Type: text/markdown; charset=<blog charset>`
    - `Vary: Accept`
    - `Cache-Control: no-store, private`
    - `X-Content-Type-Options: nosniff`
    - `Content-Security-Policy: default-src 'none'; sandbox`
    - `X-Robots-Tag: noindex`
  - Body: `# <title>`, a blank line, then the converted content. HEAD omits the body.
- **Failure behaviour.** The handler builds the full Markdown string before sending any header. If anything throws, it returns without output, and WordPress continues with the normal HTML response. Nothing is logged to the response.
- **No persistence.** No caching layer of its own (no transients, no object cache). Markdown responses are uncacheable by design; conversion cost is a few milliseconds per post. A cache can be added later as a separate decision.
- **Plugin header.** `Requires PHP: 8.4` and `Requires at least: 7.1.3`. No settings link, no text domain beyond the header.

## Testing Decisions

- **One seam: HTTP.** All tests are black-box HTTP requests against a WordPress site with pretty permalinks and the plugin active. They assert only status, response headers and body text. No test calls plugin functions directly or inspects internal state.
- **Target site.** The base URL comes from the `ACCEPT_MARKDOWN_BASE_URL` environment variable and defaults to `http://accept-markdown.local` (the Local dev site). CI points it at a disposable WordPress Playground instance.
- **A good test** sends one request with specific headers and asserts on the observable response. The Accept negotiation edge cases (stories 8 to 15, 43, 55) are covered as a table of Accept values to expected representation, each one HTTP request.
- **Test mu-plugin.** One file in the test directory, installed into `wp-content/mu-plugins/`, holds every test hook: the test CPTs, a test shortcode, a `the_content` and a `render_block` filter that output markers, and the allow and output filters used by the tests.
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
- **Must-have security tests:**
  - Protected posts and pages, drafts, private and scheduled posts, and the non-public CPT that declares support each return HTML (never Markdown) with Markdown Accept.
  - The post, the page and the opted-in public CPT return Markdown; the public CPT without support returns HTML.
  - A public CPT that declares support and then has it removed with the remove-post-type-support call returns HTML.
  - Shortcodes are never executed: the test shortcode's handler output never appears, and its `[tag]` text is absent too.
  - The approved comment's marker and the HTML comment's marker never appear in the Markdown.
  - A Query Loop with Post Content and Post Excerpt rendering another post never executes that post's shortcode or `the_content` filters.
  - A post with Comments, Comment Template, Comments Title, comment form, Latest Comments, comment count and a third-party comment-listing block renders as its own text only: no comment text, author, count or form.
  - When rendering throws midway, the HTML fallback still runs shortcodes, `the_content` filters and shows comments, so everything switched off during rendering is restored.
  - Output from a third-party `the_content` filter registered by a test mu-plugin never appears in the Markdown.
  - Output from a third-party `render_block` filter registered by a test mu-plugin does appear in the Markdown. This pins the accepted trade-off in Further Notes, so a change to it is a deliberate decision.
  - The script, style, svg and unclosed-script markers never appear in the Markdown.
  - The shortcode inside the synced pattern is neither executed nor present as `[tag]` text.
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
- **Prior art:** the roots plugin's Pest suite used the same pattern (a request helper over a Playground server, parsed status and headers, Pest expectations). We reuse the pattern, not the code.
- **CI** runs the suite against the minimum supported PHP (8.4) and the latest PHP, plus `composer audit`.

## Out of Scope

- `.md` URL suffixes, `?format=markdown` or any other query parameter, and any new URL or rewrite rule.
- Markdown feeds, comment feeds, archive, category, tag, author, search and front-page listing output.
- Comments in any form, and shortcodes in any form.
- 406 Not Acceptable responses or any other change to non-Markdown responses beyond `Vary: Accept`.
- Advertising the Markdown representation (Link header, `<link rel="alternate">`, RSS autodiscovery).
- Diagnostic headers such as `X-Markdown-Source`.
- A conversion or response cache.
- Settings pages, options, WP-CLI commands.
- Front matter or metadata (author, dates, taxonomies) in the output; the output filter is the extension point if needed.
- Rate limiting; that belongs to the web server or CDN.
- Multisite-specific behaviour beyond what works per site by default.

## Further Notes

- A 108-line prototype exists from the review session and validated the Accept negotiation algorithm against nine edge cases. It predates several decisions in this spec, so it is a starting point only. It is missing:
  - the status and viewable-type checks
  - the password check on the post itself (it uses `post_password_required`, which honours the visitor's password cookie)
  - the preview and embed exclusions
  - the post type support opt-in for pages and CPTs
  - the fixed rendering chain (it still uses `the_content`, which runs shortcodes)
  - the kses pass and the element removal before it
  - q-value clamping and whitespace tolerance
  - the security headers
  - the Accept size limits
  - the exception fallback
  - the HEAD handling
- Known trade-off of skipping shortcodes: content that only exists inside a shortcode (galleries, embeds wrapped in shortcodes, enclosing shortcodes around text) is missing from the Markdown. This is accepted; the content we care about is authored in blocks or classic HTML.
- Dynamic blocks are kept by decision: they run through `do_blocks`, so blocks such as latest posts or query loops appear in the Markdown. They are core or plugin PHP that already runs on the public HTML page, so they expose nothing new.
- Known trade-off of `do_blocks`: it runs third-party `render_block` filters, so their output can reach the Markdown. This is accepted as part of keeping dynamic blocks, for the same reason: that output already appears on the public HTML page. A test pins this behaviour.
- Known limitation with page-cache plugins: a cache that serves pages from `advanced-cache.php`, such as WP Super Cache, answers before `plugins_loaded`, so a Markdown request can get a cached HTML page. The reverse never happens, because Markdown requests are never stored. Agents may therefore sometimes get HTML on such sites. This is accepted; fixing it would mean hooking into each cache plugin.
- `Cache-Control: no-store` on Markdown responses means agents re-fetch every time. That is acceptable for the expected traffic and removes the whole CDN cross-serving risk class. Revisit only with real traffic data.
- Naming: plugin name "Accept Markdown", slug and text domain `accept-markdown`, PHP namespace `AcceptMarkdown`, filter prefix `accept_markdown/`, post type support feature `accept-markdown`.
