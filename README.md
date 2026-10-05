# LocalEyes Plugin (WordPress)

LocalEyes is a WordPress control panel for site speed and structure. It scans PageSpeed Insights /
Core Web Vitals, gives a dedicated TTFB analysis, builds native replacements for other plugins
(copying their data and proving parity against the live page, then recommending you remove them
yourself), provides a full SEO suite, surfaces GSC index hygiene, and keeps snapshot backups of
everything it changes. It also keeps the original NAP find-and-replace engine as the Search & Replace tool.

**It never deactivates or deletes any plugin. It copies functionality and recommends; you remove.**

- **Plugin name:** LocalEyes Plugin
- **Main file:** `localeyes-nap.php` (loader) + `includes/` modules
- **Version:** 2.5.0-dev
- **Author:** LocalEyes
- **Requires:** WordPress 5.6+, PHP 7.0+
- **License:** GPL-2.0-or-later
- **REST namespace:** `localeyes/v1` (v2 routes need `manage_options`; the NAP routes need `edit_pages`)
- **Full build plan:** `C:\Users\Capitao\.claude\plans\eager-yawning-tiger.md`

## Build status (v2.5.0-dev)

This is an in-progress rebuild delivered in phases. What is implemented and installable now:

| Area | Status |
|------|--------|
| Modular loader + custom DB tables (`wp_localeyes_*`) | Done |
| Real-time job engine (pause / continue / cancel, checkpointed) | Done |
| PSI / Core Web Vitals client (mobile + desktop, full audits, no truncation) | Done |
| Configure tab: PSI + GSC keys + admin App Password (show/hide, Save, Test, Refresh), Refresh Info, toggles | Done |
| Overview: field + lab scores with the "LocalEyes can do this / partial / manual / theme" badges | Done |
| TTFB: dedicated field-vs-lab analysis + prioritized actions | Done |
| Speed tab: PSI scan (homepage / whole site) with live progress | Done |
| Speed auto-fixes (hero, fonts, viewport, preconnect) with preview + Deploy + kill switch | Built (untested) |
| Per-URL optimization exclusions (path prefix, per module) | Built (untested) |
| Plugin replacement engine + duplication detector (never deactivates/deletes) | Built (untested) |
| SEO suite (meta, canonical, robots, redirects, sitemap, robots.txt, llms.txt, schema) + Rank Math import | Built (untested) |
| GSC index hygiene + builder page inventory (manual import or API token) | Built (untested) |
| Thrive redirect helpers | REST only (`POST /thrive/redirects`); no screen yet |
| LocalEyes-changes snapshot backups (REST only, no screen) | Built (untested) |
| Site Scan: read-only crawler (sitemap + DB diff, redirect chains, noindex, canonicals, schema, builder/theme) with a live feed | Built (untested) |

"Built (untested)" means the module and its dashboard exist and are wired to the REST API, but have
not yet been run on a live WordPress. All of it must be verified on staging before production.

As of v2.5.0-dev the static gates and the logic harness both run here: `.\tools\audit.ps1 -Php
<php.exe>` is clean (php -l, PHPStan level 5, duplicate declarations, route/DOM wiring, option
read/write) and `.\tools\harness\run.ps1 -Php <php.exe>` passes fourteen suites. That proves
structure and the logic those suites cover; it still does not prove any feature behaves
correctly against a real WordPress.

**Linting is now a gate.** Every PHP file must pass `php -l` before a change is considered done, and
the pure helpers (font MIME/audit, `@font-face` patching, preconnect head rewriting, config parsing)
have executable tests that run against real Lighthouse data. Structural checks alone are not enough:
they cannot tell a compiling file from a correct one.

### Phase 7 additions (built, untested)

| Feature | Where |
|---|---|
| Throttled background queue (Action Scheduler; WP-Cron fallback) | `includes/queue.php` |
| API rate-limit status (waits, backs off, reports responding/not) | `includes/queue.php`. The backoff applies to every queued API call; `/api-status` reports it and the Speed tab now prints it under the scan buttons |
| GSC OAuth 2.0 (Connect + auto token refresh) | `includes/seo/oauth.php`, GSC tab |
| OAuth setup preflight (asks Google whether the redirect URI is registered, before you connect) | `includes/seo/oauth.php`, `/gsc/oauth/preflight`, "Check setup" on the GSC tab |
| Snapshot backups (list/create/restore/download/delete) | `includes/backups.php`, `/snapshots/*` REST routes |
| Real plugin reproductions: header/footer code (+analytics), SVG, Classic Editor, smooth scroll, post-types order, author box | `includes/replace/features/reproductions.php` |
| Post-removal verification (baseline → recheck → restore + reinstall advice) | `includes/replace/engine.php`, Plugins tab |
| Duplication vs theme/Rank Math/any plugin (exact tag) | `includes/duplication.php`. The Plugins tab uses `/duplication`; the per-tag breakdown at `/duplication/tags` has no screen yet |
| Canonicals: redirected-pages finder + audit + bulk deploy | `includes/seo/canonical.php`, GSC tab |
| Breadcrumbs (visible trail; BreadcrumbList JSON-LD is offered as a reviewable suggestion, never auto-output) | `includes/seo/breadcrumbs.php` |
| Table of contents: heading levels, four insert positions, per-post overrides, working anchor jumps | `includes/seo/toc.php`, `includes/seo/toc-meta.php` |
| Sitemap index + per-type; robots/llms/sitemap conflict detection, with one-click fixes for the safe ones (move a shadowing physical file aside, switch the WP core sitemap off through core's own `wp_sitemaps_enabled` filter). Both are reversible with Undo. Another plugin's sitemap is only ever reported, never switched off | `includes/seo/sitemap.php` |
| Schema builders: FAQ + Article (plus Organization/LocalBusiness). Builders only **propose** a block for you to review; they never store or emit one | `includes/seo/schema.php` |
| Pre-uninstall + on-deactivation snapshot; opt-in full purge on uninstall | `localeyes-nap.php` |

### Phase 8 additions (built, untested)

| Feature | Where |
|---|---|
| Per-page SEO + Schema editor in **any editor** (Gutenberg sidebar, Classic meta box, front-end + Elementor/Thrive/WPBakery drawer) | `includes/seo/panel.php`, `assets/seo-panel.js`, `assets/seo-panel.css` |
| Per-page inspector: identifies current SEO + schema from LocalEyes, Rank Math, Yoast, AIOSEO, SEOPress, plus an optional live-page fetch | `includes/seo/inspect.php` (`/seo/page-inspect`) |
| Shared read-only per-post getters behind both the importers and the inspector | `le_read_rankmath_meta` (meta.php), `le_read_yoast/aioseo/seopress_meta` + `le_all_detected_meta` (importers.php), `le_read_rankmath_schema` (schema.php) |
| Full per-page field parity: twitter description/image, additional keywords, breadcrumb title, max-snippet | meta store + `analysis.php` + `breadcrumbs.php` |

The panel writes to the existing LocalEyes SEO store and only renders on the live page once the SEO
replacement is Deployed (Plugins tab), so it never double-emits with Rank Math. In page builders
(Elementor/Thrive/WPBakery) it appears as a floating "LocalEyes SEO" launcher and drawer, since those
editors do not show a WP meta box or Gutenberg sidebar.

## Core Web Vitals

**The plugin cannot promise your site passes.** Hosting, theme, content and third-party scripts all
move these numbers and none of them are LocalEyes's to control. What it does is measure honestly,
fix specific defects, and make each fix prove itself.

**The verdict** (`includes/cwv.php`, Overview and Speed tabs) judges LCP, CLS and INP against the
thresholds in `le_cwv_thresholds()` and states pass or fail per metric and overall. It is **field
first**: real CrUX visitor data for the URL, falling back to origin-level field data, and only then
to a lab run. The source is shown next to every number, because a lab pass is not a Core Web Vitals
pass, and INP cannot be measured in a lab at all.

**Fixes have to prove themselves.** Every scan compares itself against the previous run for the same
URL and strategy and reports the before/after delta for the three metrics and the performance score.
If a fix changed nothing, it says so. `POST /speed/verify` returns that same comparison for a single
fix and remains available, but no screen calls it: the Speed tab reads the delta that `POST /psi/run`
already computes, so it costs no extra PageSpeed call.

**One scan per refresh.** The Speed tab has a single **Refresh everything** button. It runs PageSpeed
once for mobile and desktop and repaints scores, Core Web Vitals, fonts and every issue row from that
one scan, with the Live progress card showing each step and offering pause / continue / cancel. There
are deliberately no per-row re-scan buttons: each one used to run a *full* scan of the whole page, so
a list of 22 issues offered 22 identical 60-second scans that each spent a PageSpeed quota unit to
re-measure the same page.

**PageSpeed failures.** The timeout is `LE_PSI_TIMEOUT` (120s, overridable by defining it earlier).
Transient failures - a run that outlasts the timeout, Lighthouse's own "Something went wrong", a
Google 5xx - get exactly one retry. A rate limit and a 403 refusal get none: the first spends quota
that is already exhausted, and the second cannot succeed twice. Errors that survive the retry are
rewritten to name the page and what to check, rather than quoting a cURL socket error at the user.

## Fonts and the critical path

Fonts are the usual reason a WordPress page has a long critical request chain: without
`font-display`, the browser blocks text rendering until the font arrives.

| What | Where |
|---|---|
| **font-display on every @font-face** (not just Google's) - patches local stylesheets that declare fonts without one. This is what takes fonts off the critical path | `includes/perf/font-display.php` |
| **Font audit** - every font file with origin, size and format; flags families split across two origins and legacy `.ttf`/`.otf` with the woff2 saving. Report only | `includes/perf/font-audit.php` |
| **Capped font preload** - preloads the first fonts the page requests, bounded (default 2). Preloading every font competes with the LCP image and makes the chain worse | `includes/autofix.php` (`fonts_preload`) |
| **Preconnect with the right crossorigin** - a preconnect to a font origin without `crossorigin` opens the wrong kind of connection and the browser discards it. Also fixes tags hard-coded in the theme header, which `wp_resource_hints` cannot reach | `includes/autofix.php` (`preconnect`) |

LocalEyes **cannot** convert a `.ttf` to woff2 - there is no pure-PHP woff2 encoder. It flags the
files and the saving; self-hosting from Google returns woff2, or your theme author can supply it.
Reducing the number of font families is a design decision, so the audit reports and does not act.

## Site Scan

A read-only crawler on its own tab. It **reports; it never changes anything.** Fixes stay on the
Redirects, Canonicals, and per-page SEO screens, where each change gets reviewed individually.

**Discovery** reads the sitemap (auto-detecting LocalEyes', WP core's, or a plugin's, and following
a sitemap index into its children) *and* enumerates published posts, then diffs the two. The diff is
the point: URLs the sitemap advertises that no longer exist (`orphan in sitemap`), and published
pages the sitemap never mentions (`missing from sitemap`). Known redirect sources are folded in too,
so every redirect can be proven to still land somewhere sensible.

**Per URL** it records the full redirect chain with every hop and status code (with loop detection),
the final HTTP status, the emitted canonical, effective noindex from all three sources (robots meta
tag, `X-Robots-Tag` **response header**, and stored intent), every JSON-LD block actually on the
page, the SEO fields, and which builder and theme produced it.

**Live feed.** Four workers crawl in parallel and each row appears the moment its own fetch returns,
not batched at the end. Pause, continue, and cancel work mid-run, and a browser reload offers to
resume from where it stopped rather than starting over.

Findings live in `wp_localeyes_scan`, grouped by `scan_id` so runs are comparable; the newest three
are kept. Filter chips narrow to any single finding, and the whole run exports as CSV.

The crawler identifies itself with a `LocalEyes-Scan` user agent, and the 404 log and redirect hit
counters ignore that traffic, so scanning never shows up in your reports as visits the site did not
actually receive.

## How schema (structured data) works

**Nothing is applied automatically.** LocalEyes never generates, adds, or publishes structured data
on its own. There is no per-post-type default, no rule that attaches schema to pages you have not
opened, and no background write. A page with nothing confirmed on it outputs no JSON-LD at all.

The flow is always the same:

1. **It pulls what is there.** Open a page and the panel lists every schema block it can find: what
   is already confirmed on the page, what Rank Math holds for it, what the live HTML actually
   contains, and what LocalEyes could build for you. All of it read-only.
2. **You review and edit.** Each block is separate, with its own JSON editor. Edit any of them,
   delete any of them, turn any of them off, or add new ones. Inserting from any source only opens
   the block in an editor; it stores nothing.
3. **You press Confirm.** The block is validated and kept in the editor. It is still not saved.
4. **You press WordPress's Update button.** That is what saves the page.

Per-page schema is stored in the `_localeyes_schema` post meta key, which is why WordPress's own
Update button owns it (`includes/seo/schema-meta.php`). Site-wide and per-term blocks live in
`wp_localeyes_schema` and are edited from the SEO to Schema tab; those screens have no Update button,
so Confirm saves immediately there and the UI says so. The same applies inside Elementor, Thrive, and
WPBakery, which do not save through WordPress's post form.

Staging Rank Math's schema in bulk (SEO to Import) copies each post's blocks onto that post **turned
off**, so nothing is output until you open the page and confirm each one. Re-running it skips blocks
that are already present.

### Phase 13 additions (AEO personalization, built, untested)

Phase 12 shipped the AEO features with most of their behavior hardcoded. This makes all of it the
site owner's to control, through one settings store (`localeyes_aeo`, deep-merged over
`le_aeo_defaults()` in `includes/aeo/settings.php`, with ordered lists replaced rather than merged so
deleting the third of three sections actually deletes it).

| What is now editable | Where |
|---|---|
| **llms.txt build**: manual or template mode; `%pages%` `%posts%` `%cpts%` `%cpt:slug%` `%contact%` `%hours%` `%entity%` `%faq%` `%custom%` `%sitemap%` on top of the existing `le_seo_replace_vars()` engine; per-section post type, heading, count, ordering and descriptions; description source order; custom Markdown sections; URL exclusions; per-page exclude and pin | `includes/aeo/llms.php` |
| **llms-full.txt**: the same pages with their text inline, registered through the new `le_files_registry` filter so it is served and inspected like every other root file | `includes/aeo/llms.php`, `includes/seo/files.php` |
| **Accessibility rules**: per-rule on/off, per-rule "may a fixer repair this", severity; URL and selector exclusions applied to BOTH the audit and the fixers; the denied-roles and embed-provider maps as editable line lists | `includes/aeo/a11y.php`, `includes/aeo/settings.php` |
| **Per-element overrides**: pin the exact `aria-label`, `title` or `alt` for an element by selector, site-wide or per URL, applied before anything the fixers derive and reported back so the two never disagree | `le_aeo_overrides_for()`, `le_aeo_fix_override()` |
| **Fixer config**: all six modules now expose real fields (icon-class naming, external-link prefix, placeholder vs name, fallback frame title, per-module selector exclusions) instead of four of them having none | `includes/aeo/fixes.php` |
| **WebMCP tools**: rename and re-describe the six built-ins and pick which fields they return; the front-end script is descriptor-driven, so adding a tool never touches JavaScript | `includes/aeo/tools.php`, `assets/webmcp.js` |
| **Custom WebMCP tools**: read-only tools backed by a post type, a page, a post meta or option, static text, or a shortcode, with a "Try it" preview and a public `POST /aeo/tool` resolver that only answers for configured tool ids | `includes/aeo/tools.php` |
| **Contact and hours override**: field by field over Local SEO, so a site without Local SEO can still answer | `le_aeo_contact()`, `le_aeo_hours()` |
| **AI crawler rules**: per-bot allow/deny for 15 crawlers with `allow_all` / `answer_engines` / `block_all` presets, written through the existing `robots_txt` filter | `includes/aeo/crawlers.php` |
| **Entity facts** (the About block of llms.txt) and **FAQ** (written once, reused in llms.txt and the `get_faq` tool) | `includes/aeo/settings.php`, `includes/aeo/llms.php` |
| **Audit**: max URLs, post types, URL exclusions, daily/weekly schedule via WP-Cron and the throttled queue, regression email, trend across runs, CSV export | `includes/aeo/audit.php` |
| **Per-page AEO** in the existing Performance panel: agent summary (emitted as a meta tag and returned by `get_page_content`), exclude from llms.txt, pin, skip the audit, skip the fixers, and per-page element overrides | `includes/perf/overrides.php`, `assets/perf-panel.js` |

New AEO sub-tabs: **AI crawlers**, **Live files** (every root endpoint with its HTTP status and who
answered, including `missing` for a 404, plus a custom-path checker), and **Settings**.

New routes: `/aeo/settings` (GET/POST, with `reset`), `/aeo/maps`, `/aeo/llms/tokens`,
`/aeo/tools/config`, `/aeo/tools/preview`, `/aeo/tool` (public), `/aeo/crawlers`, `/aeo/trend`,
`/aeo/export`. `/aeo/llms` now takes `?file=full`.

Six new post meta keys (`_localeyes_aeo_exclude_llms`, `_localeyes_aeo_llms_pin`,
`_localeyes_aeo_skip_fixes`, `_localeyes_aeo_skip_audit`, `_localeyes_aeo_summary`,
`_localeyes_aeo_overrides`), all purged on opt-in uninstall.

**Selector matcher gotcha, fixed and tested:** attribute selectors are consumed before id and class,
because `a[href*="youtu.be"]` otherwise reads `.be` as a class and never matches. An unparseable
selector matches nothing rather than guessing.

### Phase 12 additions (AEO tab, agentic browsing, built, untested)

Lighthouse 13.3 added an **Agentic Browsing** category and PageSpeed Insights inherited it. It reports a
pass ratio, not a score, and covers four things: `llms.txt`, WebMCP, an agent-usable accessibility tree,
and CLS. The new **AEO** tab addresses all four, with one honest limit: **LocalEyes does not build an
accessibility tree.** A real tree needs a browser engine to compute roles and accessible names, and
there is none here. What it does is apply the subset of the rules that tree is judged by to the HTML
the server returns — so anything rendered by JavaScript is outside its reach. `llms.txt` moved here out
of SEO > Tools.

| Feature | Where |
|---|---|
| **Agent-accessibility rule engine**: link-name, button-name, image-alt, input-image-alt, label, `le-aria-role`, aria-valid-attr-value, frame-title, document-title, html-has-lang. Regex over the served HTML, not a DOM. `le-aria-role` is named that way deliberately: it is a hand-written deny-list for nine tags, not axe's full `aria-allowed-role` matrix, and borrowing that id would claim a completeness it does not have. Each failure carries the failing element and a derived, concrete suggested fix | `includes/aeo/a11y.php` |
| **Site-wide audit**: crawls every URL and applies those rules plus WebMCP form coverage, with the four-worker live feed, pause/continue/cancel and resume | `includes/aeo/audit.php`, table `wp_localeyes_agent` |
| **Lighthouse verdict**: probes for `category=AGENTIC_BROWSING` once and caches the answer; falls back to the agent-relevant accessibility audits plus CLS that PSI already returns | `le_aeo_psi_verdict()` |
| **llms.txt**: generator that includes descriptions, CPTs, posts and NAP; live validation of the three Lighthouse checks (H1, Markdown links, length) plus two of our own for the ways a file loses its H1 (a BOM, a `---` above the first H2); a report naming the entries whose description fell back to raw page text; detects another plugin answering `/llms.txt`. Note Google Search does not read this file and has said so — it is published for the answer engines that do, and for the Agentic Browsing score | `includes/aeo/llms.php` |
| **WebMCP declarative forms**: detects every form, stores per-form config keyed by a builder-stable signature, and injects `toolname` / `tooldescription` / `toolparamdescription` on output | `includes/aeo/webmcp.php`, `includes/aeo/fixes.php` |
| **WebMCP site tools**: `search_site`, `get_contact_info`, `get_business_hours`, `list_pages`, `get_page_content` registered through `document.modelContext ?? navigator.modelContext`. That API is an origin trial, so on a browser that does not expose it the script no-ops and nothing is registered — which the server cannot detect, so **AEO > What is working** reports this row as unverifiable rather than as a pass. There is no MCP server, no JSON-RPC transport and no `/.well-known` discovery here: an agent that does not execute the page's JavaScript sees only the annotated form attributes | `assets/webmcp.js`, route `/aeo/search` |
| **Opt-in fixers**: `agent_link_names`, `agent_aria_roles`, `agent_form_labels`, `agent_frame_titles`, `webmcp_forms`, `webmcp_tools`, all registered through `localeyes_perf_registry` so they inherit snapshots, kill switches and per-page overrides | `includes/aeo/fixes.php` |

All six fixers are **off by default** and share ONE output buffer. `agent_aria_roles` removes only the
invalid `role`, leaving `aria-expanded` and `aria-controls` intact so the accordion keeps working.

New REST routes: `/aeo/llms` (GET/POST), `/aeo/llms/generate`, `/aeo/llms/validate`, `/aeo/forms`
(GET/POST), `/aeo/tools`, `/aeo/search` (public), `/aeo/discover`, `/aeo/url`, `/aeo/latest`,
`/aeo/summary`, `/aeo/results`, `/aeo/check`, `/aeo/psi`. The old `/seo/llms*` routes are gone.

`le_is_own_crawler()` now matches the whole `LocalEyes-` user-agent prefix rather than only
`LocalEyes-Scan`, so the AEO audit and the live-page inspector stop counting as real traffic in the 404
log and redirect hit counters.

### Phase 11 additions (per-post performance panel, built, untested)

The WP Rocket per-post options box, in the right-hand column of every editor, plus four things Rocket's
box does not have. One renderer (`assets/perf-panel.js`) mounted three ways by `includes/perf/panel.php`:
a block-editor `PluginDocumentSettingPanel`, a Classic `side` meta box, and a floating drawer inside
Elementor / Thrive / WPBakery. Everything saves immediately over REST, independent of the post save.

| Feature | Where |
|---|---|
| **Never cache this page** (also raises `DONOTCACHEPAGE` for third-party caches) | `perf/overrides.php`, `perf/page-cache.php` |
| **Per-module checkboxes** matching Rocket option for option; a module that is off site-wide renders greyed and disabled | `perf/overrides.php` (`le_perf_panel_labels`, `le_perf_not_page_scoped`) |
| **Cache status + purge/warm this page** (extra) | `perf/page-cache.php` (`le_page_cache_url_status`), routes `perf/page/purge`, `perf/page/warm` |
| **Page-specific Critical Path CSS** generated from this page's own permalink, with revert (extra) | `perf/critical-css.php` (`le_critical_build`, `le_critical_generate_page`) |
| **Page LCP hero image + skip lazy-load for the first N images** (extra) | `includes/autofix.php` (`le_speed_hero_url`, `le_speed_lazy_skip_take`) |
| **Test this page with PageSpeed** inline (extra) | `assets/perf-panel.js` on the existing `psi/run` route |

New REST routes (all `localeyes/v1`, all `le_rest_perm` + a per-post `edit_post` check):
`GET|POST /perf/overrides` (extended payload), `POST /perf/page/purge`, `POST /perf/page/warm`,
`POST /perf/critical/page`, `POST /perf/critical/page/revert`.

New post meta, all unregistered and underscore-prefixed, all purged on opt-in uninstall:
`_localeyes_perf_never_cache`, `_localeyes_perf_hero`, `_localeyes_perf_lazy_skip`,
`_localeyes_perf_critical_css` (alongside the existing `_localeyes_perf_exclude`).

The duplicate per-page performance checkbox list was removed from the SEO panel, so per-page
performance now lives in exactly one place.

### Phase 10 additions (WP Rocket parity, built, untested)

Completes the performance suite (Performance tab, `includes/perf/`) toward WP Rocket parity.

| Feature | Where |
|---|---|
| **Add missing image dimensions** (width/height, same-origin, cached) | `includes/perf/image-dimensions.php` |
| **Preload links** (instant.page hover/touch prefetch) | `includes/perf/preload-links.php` |
| **Disable WordPress emojis** | `includes/perf/disable-emojis.php` |
| **Minify: Excluded CSS/JS files** | `includes/perf/minify-assets.php` (exclude fields) |
| **LazyLoad CSS background images** + YouTube/bg toggles | `includes/perf/lazy-media.php` |
| **Delay JS Safe Mode** (never delay internal scripts) + exclude | `includes/perf/delay-js.php` |
| **Defer JS user exclusion list** | `autofix.php` + `registry.php` (field bridged to the perf UI) |
| **Cache lifespan** (hourly expiry cron) + **auto-preload after purge** + preload exclude | `includes/perf/page-cache.php` |
| **Never-cache cookies / user agents / URLs** + query-string whitelist (honored pre-WP via a drop-in sidecar config) | `includes/perf/page-cache.php` |
| **DB: All transients + Optimize tables** | `includes/perf/dbclean.php` |
| **Clear used CSS** action | `includes/perf/unused-css.php` |

All modules render generically on the Performance screen (config fields + Deploy/Save/Revert + action
buttons), gated on their toggle, snapshotted on deploy, and paused while real WP Rocket is active.

### Phase 9 additions (Rank Math parity gaps + live compare, built, untested)

Closes the audited gaps to Rank Math (free + pro) and adds a live-vs-LocalEyes compare with adopt.

| Feature | Where |
|---|---|
| **Live compare + adopt** per-page (per-field Adopt + Adopt-all + schema block Insert). Every one of these only fills the editor; you still Confirm and Save | `includes/seo/inspect.php`, `assets/seo-panel.js` |
| **Live compare (site-wide)** live tags vs settings, adopt into settings | `includes/seo/compare.php`, SEO to Live compare tab |
| **Per-term (category/tag) SEO editor** (term edit screen + front-end admin bar) | `includes/seo/panel.php` |
| **Bulk edit** titles/descriptions/robots across posts (merge-patch, never wipes) | `includes/seo/bulk.php`, SEO to Bulk edit tab |
| **SERP + Facebook/Twitter snippet previews** (live) | `assets/seo-panel.js` |
| **Primary category + pillar content**; wired into `%category%` + breadcrumbs + link suggestions | `meta.php`/`settings.php`/`breadcrumbs.php`/`links.php` |
| **External-link options** (nofollow / new tab / nofollow image links), global + per-post | `includes/seo/linkopts.php` |
| **Schema presets** (offered as suggestions on matching pages, never auto-attached) + **import schema from a URL** | `includes/seo/templates.php` |
| **Export / import all SEO settings** (secrets excluded) | `includes/seo/exporter.php` |
| **Role Manager** (which capability may use LocalEyes) | `includes/seo/roles.php`, `helpers.php` |
| **Link Counter + Quick Edit** columns in the posts list | `includes/seo/listtable.php` |
| **RSS feed injection** (before/after each item) | `includes/seo/rss.php` |
| **.htaccess redirect sync** (non-regex 301/302/307) | `includes/seo/redirects.php` |
| **Automatic internal linking** (keyword map) | `includes/seo/links.php` |
| **Social-image watermark** (GD) | `includes/seo/watermark.php` |
| **Google Trends** link-out for the focus keyword; **max-video-preview**, `[localeyes_map]`, Local hours UI | panel + `local.php` |

Fixed defects: regex redirect normalization; Google Instant Indexing now uses the GSC OAuth token
(scope added); breadcrumb home-label/separator/prefix UI; `noindex.empty_archive` honored.

**Migration flow (why the compare exists):** all new front-end output stays behind the deploy gate,
so before Deploy the live-compare shows Rank Math's tags and you Adopt them into LocalEyes; then
Deploy and remove Rank Math. New DB table: `wp_localeyes_schema_templates` (created on version bump).

**Action Scheduler note:** the queue uses Action Scheduler when some other plugin on the site already
provides it; otherwise it falls back to per-tick WP-Cron events (works, but fires on traffic). Bundling
Action Scheduler with the plugin is recommended for reliable low-traffic processing.

**Still to verify on staging:** OAuth redirect round-trip, loopback fetch (needed by
canonical/parity/post-removal), and that no screen fatals. The two-level sub-tab reorg is partial:
new panels were added to existing screens (SEO, GSC, Plugins) rather than a full hash-routed
sub-tab bar.

### Phase 11 additions (retiring Rank Math and WP Rocket, built, untested)

Aimed at one question: can the other two plugins be deleted without losing anything.

| Feature | Where |
|---|---|
| **Rank Math redirections importer.** Its redirects live in its own table, so they were the one data set that died with the plugin. Handles all five match modes, expands a row's multiple sources into one rule each, and **reports every rule it could not reproduce** instead of dropping it | `includes/seo/importers.php` (`le_seo_import_rankmath_redirects`), `POST /seo/import-rankmath-redirects` |
| **Migration reconciliation.** Counts what Rank Math holds against what LocalEyes holds for per-post meta, schema blocks and redirects. Database only, so it still works on a host that blocks loopback | `includes/seo/rankmath-ready.php` (`le_rm_audit`), `GET /seo/rankmath-audit` |
| **Rank Math readiness checklist**, the SEO-side twin of the WP Rocket one. Per area: covered and verified, or the reason it is not. `ready` only when every core area passes | `includes/seo/rankmath-ready.php` (`le_rm_readiness`), `GET /seo/rankmath-readiness` |
| **Live verification**, the only part that fetches: samples real pages and compares emitted title/description/canonical against the LocalEyes store, probes imported redirects for the status they claim, and fetches the sitemap | `le_rm_probe_run`, `POST /seo/rankmath-verify` |
| **WP Rocket reclassified.** It was listed as `keep` / "never replaced" while `perf/wp-rocket.php` was a full checklist for retiring it, so the Plugins tab and the Performance tab contradicted each other | `includes/inventory.php` |

Two properties worth keeping:

**Unknown is never counted as covered.** Every area reports what was measured. "Not verified yet" is
reported as not working, because a checklist that treats unknown as covered is how the Rocket screen
once invited someone to delete a paid plugin on nginx.

**The reporting path makes no HTTP requests.** `le_rm_audit`, `le_rm_verify` and `le_rm_readiness` read
a stored probe result; only `le_rm_probe_run` fetches, behind its own route. `checkHotPathHttp` in
`tools/contractcheck.js` pins all three, and that guard was negative-controlled: moving a
`wp_remote_get` into `le_rm_verify` makes it fail at the exact line.

**Staged schema is not covered schema.** `le_schema_import_rankmath()` stages every block *disabled*
by design (the review-first contract in `schema.php`). A block that is staged emits nothing, so the
checklist treats "imported but all still unconfirmed" as a blocker rather than a pass. Confirming
blocks in bulk is still an open design question, because per-block review is the contract.

**WP Rocket is judged by the checklist, never by a page diff.** `le_feature_hosts('perf')` hosts no
segments: a cached page and an uncached one are byte-identical by design, so there is nothing for the
segment inventory or the baseline/recheck diff to see. The baseline route now refuses to store an
empty signature, so a zero-segment feature cannot produce a vacuous "nothing was lost".

Not built, and not losses this covers: Rank Math's Content AI and its Analytics history; WP Rocket's
Cloudflare/Varnish/Sucuri purge integrations, OPcache purge, and separate cache for logged-in users.
On nginx, compression and browser cache headers still cannot verify at all, because they are
`.htaccess` rules, so `le_rocket_readiness()` cannot report ready there.

## Prerequisites

Enable the PageSpeed Insights API on your GCP project and paste the key in Configure. Add your GSC
property + key and an admin Application Password there too. Testing happens on staging.

## Files in this folder

| File | What it is |
|------|-----------|
| `localeyes-nap.php` | Loader + the original NAP engine (Search & Replace). |
| `includes/` | v2 modules: `bootstrap`, `helpers`, `db`, `jobs`, `psi`, `overview`, `ttfb`, `admin-ui`, and (as they land) `autofix`, `inventory`, `duplication`, `replace/`, `seo/` (including `seo/schema-meta.php`, the per-page schema post-meta store), `backups`. |
| `assets/` | Enqueued front-end/editor assets: `seo-panel.js` + `seo-panel.css` (the per-page SEO/schema panel). Must be included in the zip. |
| `localeyes-plugin-<version>.zip` | Upload-ready package (contains `localeyes-plugin/` with the loader + `includes/`). Generated by `build.ps1`; never hand-assembled. The folder inside is always `localeyes-plugin/`, whatever the file is called. |
| `build.ps1` | Sets the version, packages the zip, and verifies it before shipping. The only supported way to build. |
| `README.md` | This document. |

> If you edit any source file, run `.\build.ps1 -Version <x.y.z>` before uploading (see **Rebuilding
> the zip**), otherwise WordPress installs the stale copy.

## Note on the NAP defaults

The old hardcoded Austin/Chicago/Dallas find/replace pairs were removed in v2.0. Search & Replace now
operates on current live content and loads/saves whatever pairs you enter (the `localeyes_pairs`
option). "Load defaults" restores your saved set, not any baked-in address.

## Why this exists

When a Google Business Profile address changes (for example after a suspension and move to
a new Regus/office address), the old NAP is usually scattered across dozens of places on the
site: page body, Elementor Theme Builder headers/footers, Thrive Architect content, Rank Math
schema, and widget/theme-option text. A plain search-and-replace plugin misses the builder
data and the schema, which is exactly where AI answer engines and Google read the address
from. This tool covers all of those surfaces in one pass and lets you roll back if anything
looks wrong.

This is the practical companion to the suspended-profile reinstatement work (Austin / Chicago /
Dallas). No addresses ship with the plugin: you enter the pairs for the move you are doing and save
them, and Search & Replace reloads them next time (see **Saved find/replace pairs** below).

## What it scans and edits (coverage)

| Surface | Where it lives | Routing token |
|---------|---------------|---------------|
| Classic page/post body | `post_content` | `classic` |
| WPBakery body | `post_content` (detected by `[vc_` / `_wpb_vc_js_status`) | `bakery` |
| Elementor (incl. header/footer Theme Builder templates) | `_elementor_data` post meta | `elementor` |
| Thrive Architect | `tve_*` post meta | `thrive` |
| Rank Math schema (per page) | `rank_math_*` post meta | `schema` |
| Widgets + theme options (site-wide) | `widget_*`, `theme_mods_*` options | `widgets` |
| SEO plugin settings (site-wide) | `rank_math*`, `wpseo*` options | `schema_opts` |

Builder is auto-detected per page (`elementor | thrive | bakery | classic`). Nested/serialized
option values are walked recursively so replacements preserve structure. Site-wide option groups
(`widgets`, `schema_opts`) are only scanned when you are **not** scoped to specific pages.

## Installing

1. In WordPress admin: **Plugins to Add New to Upload Plugin**.
2. Choose `localeyes-plugin-<version>.zip` and install, then **Activate**.
3. Open it from the **LocalEyes Plugin** item in the left admin menu (map-pin icon).

Requires a user with the `edit_pages` capability (editors and admins).

## Updating

**Do not deactivate, and do not delete.** Uploading over a live install is the supported update path:

1. Build with a bumped version: `.\build.ps1 -Version 2.6.0` (see **Rebuilding the zip**).
2. **Plugins to Add New to Upload Plugin**, choose the new zip, **Install Now**.
3. WordPress recognizes the existing copy and offers **Replace current with uploaded**, showing the
   current and new version side by side. Confirm it.

Settings, the 17 custom tables, snapshots and every stored module state survive untouched — the
replace swaps files only, and no deactivation hook runs, so the drop-in and `.htaccess` blocks stay
live throughout. Schema changes land by themselves: `le_bootstrap_maybe_upgrade()` runs on `init`
after the files change, gated on `LOCALEYES_DB_VER`, so the first request after the update applies
them whether it is a front-end hit or an admin one.

Two things make this reliable, and both are worth knowing about:

- If a leftover folder would block the upload, `includes/install-heal.php` clears it during the
  install. It only ever acts when the uploaded package's own header says LocalEyes. See
  **"Destination folder already exists"** below.
- `Update URI: false` in the plugin header keeps this plugin off the wordpress.org update channel.
  Without it, WP 5.8+ matches an installed plugin against the .org directory **by folder slug**, and
  this installs to `plugins/localeyes-plugin/` — so whoever registered that slug on .org could have
  their code installed over this plugin on every site running it, silently wherever auto-updates are
  on. `tools/contractcheck.js` fails the build if that header goes missing.

There is no self-update checker: sites are not told a new version exists, and nothing phones home.
Updating is always someone uploading a zip.

### If you want the site to report which build it is running

`GET /localeyes/v1/ping` returns `LOCALEYES_NAP_VERSION`, and the **Plugins** screen shows the
header version. Both are only as truthful as the version bump, which is why the build now owns it.

## Using the admin UI

Four tabs, left to right:

1. **Wrong NAP** - enter the wrong text to find (address, ZIP, phone, anything). Click
   **Load defaults** to preload the Austin/Chicago/Dallas pairs. Then **Start scan**.
2. **Pages & schema found** - dry-run results with a live progress bar, per-page context
   snippets, and checkboxes to pick which pages / content types to change.
3. **Fix to apply** - type the replacement for each wrong value and apply. **Apply to selected
   pages** uses your ticks from step 2; **Apply to all matches** hits everything found.
4. **Updated pages** - what changed, with the backup ID for rollback.

Nothing is written until you apply in step 3. A replacement with an empty "replace with" value
is rejected (the tool never blanks a value).

## REST API

Namespace: `localeyes/v1`. The original NAP routes below require the `edit_pages` capability; all v2
control-panel routes (everything under `seo/*`, `replace/*`, `psi/*`, `gsc/*`, `snapshots/*`, etc.) require
`manage_options` via `le_rest_perm` (configurable through the Role Manager).

| Method | Route | Purpose |
|--------|-------|---------|
| GET  | `/ping` | Health check; reports version + whether Elementor / Rank Math are present. |
| GET  | `/targets` | List scannable posts (id, title, type) for progress display. |
| POST | `/nap/preview` | Dry run. Returns hit count + hits with snippets. No writes. |
| POST | `/nap/apply` | Apply replacements, create a backup, flush caches. |
| POST | `/nap/rollback` | Restore a backup by `backup_id`. |
| GET  | `/backups` | List backups (newest first). |

### Auth

- **Admin screen:** your logged-in session (a `wp_rest` nonce).
- **REST from outside:** WordPress **Application Passwords** over HTTP Basic auth. The user
  still needs `edit_pages`.

### Request shape

`preview` / `apply` accept:

```json
{
  "replacements": [
    { "from": "3232 McKinney Ave Suite 500, 5th Floor, Dallas, TX 75204",
      "to":   "2911 Turtle Creek Blvd, Suite 300, Dallas, TX 75219" }
  ],
  "post_ids":    [12, 45],
  "field_types": ["classic", "elementor", "schema"]
}
```

- `post_ids` (optional): scope to specific pages. When present, site-wide option groups are skipped.
- `field_types` (optional): routing tokens from the coverage table above. Empty = everything.

### Example (curl)

```bash
# Dry run
curl -u "user:APPLICATION_PASSWORD" \
  -H "Content-Type: application/json" \
  -X POST "https://SITE/wp-json/localeyes/v1/nap/preview" \
  -d '{"replacements":[{"from":"801 Barton Springs Rd","to":"2021 Guadalupe Street, Suite 260"}]}'

# Apply (returns a backup_id)
curl -u "user:APPLICATION_PASSWORD" \
  -H "Content-Type: application/json" \
  -X POST "https://SITE/wp-json/localeyes/v1/nap/apply" \
  -d '{"replacements":[{"from":"801 Barton Springs Rd","to":"2021 Guadalupe Street, Suite 260"}]}'

# Roll it back
curl -u "user:APPLICATION_PASSWORD" \
  -H "Content-Type: application/json" \
  -X POST "https://SITE/wp-json/localeyes/v1/nap/rollback" \
  -d '{"backup_id":"nap_20260707_010101_ab12cd"}'
```

## Backups & rollback

- Each `apply` writes a full before-image of every changed field to a WordPress option
  (`localeyes_nap_backup_<id>`), indexed in `localeyes_nap_backups`.
- Backup IDs look like `nap_YYYYMMDD_HHMMSS_xxxxxx`.
- Rollback restores post content, post meta, and options exactly as they were.
- Backups are stored in the DB, so a DB restore or aggressive cleanup plugin can remove them.
  Keep your own DB backup for large changes.

## Cache flushing

After apply/rollback the plugin clears, best-effort: per-post Elementor CSS, the Elementor
files cache, the object cache, and W3 Total Cache / WP Super Cache / WP Rocket / WP Fastest
Cache / LiteSpeed if present.

> **CDN is not purged.** Cloudflare or any external CDN must be purged separately. The admin
> UI notes this too.

## Saved find/replace pairs

There are no built-in pairs. `localeyes_nap_default_pairs()` reads the `localeyes_pairs` option, and
the Search & Replace screen is what writes it:

| Button | What it does |
|---|---|
| **Save these** | Stores every row that has something to find, with its replacement, as the saved set |
| **Load saved** | Reloads that set, discarding on-screen edits |
| **Delete saved** | Empties the stored set. The table on screen is left alone |

Saved rows come back the next time the screen is opened, and are shared with anyone else who can use
the tool. A fresh install opens with one empty row.

This documented a hardcoded Austin/Chicago/Dallas table until v2.5.0-dev. Those pairs were removed in
v2.0 and the option that replaced them was read by `localeyes_nap_default_pairs()` and **written by
nothing**, so "Load defaults" always came back empty. The routes `GET`/`POST
localeyes/v1/nap/pairs` are what make the saved set real.

Order still matters when you enter them: keep the most specific `from` values above their substrings,
which the scanner does for you by sorting longest-first.

## Safety notes

- Always **preview** before **apply**. The preview is a true dry run.
- Order matters: keep the most specific `from` values above their substrings.
- The phone-number pair is a single shared number across all three profiles; the addresses are
  per city. Do not apply an address pair site-wide if pages for different cities share text.
- Site-wide options change global widgets/theme text; use `post_ids` scoping when you only mean
  to touch specific pages.

## Rebuilding the zip

Run the build script from the project root:

```powershell
cd C:\LocalEyes\LocalEyes-WP-Plugin
.\build.ps1 -Version 2.6.0
```

`-Version` rewrites all three version strings together — the `Version:` header, and the
`LOCALEYES_NAP_VERSION` / `LOCALEYES_VER` constants — then packages. Omit it to build whatever is
on disk. Either way the build **refuses to package a drifted version**: if the three disagree it
prints all three and exits non-zero. `node tools/contractcheck.js` enforces the same rule, so the
drift cannot reach a commit either.

Bump the version on every build you hand to a site. It is not cosmetic:

- `LOCALEYES_VER` is the cache-buster appended to every admin CSS and JS enqueue. A version that
  never changes is a browser cache key that never changes, so a client keeps running the previous
  build's admin JavaScript against the new build's REST API.
- The **Replace current with uploaded** screen compares versions. Frozen at `2.5.0-dev` it read
  `2.5.0-dev → 2.5.0-dev`, so an update could not be confirmed and no site could be asked which
  build it was on.

`LOCALEYES_DB_VER` is separate and is **not** touched by `-Version`. Bump it by hand on a schema
change; see the note on that constant for why tying it to the display version broke `dbDelta`.

It packages every source file (excluding `*.zip`, `*.docx`, and itself) under a single top-level
`localeyes-plugin/` folder, then verifies the archive before writing it to
`localeyes-plugin-<version>.zip`: forward-slash entry names, one top-level folder, entry count
matching the source set, a readable `Plugin Name:` header with no BOM, an `Update URI: false`
header, a packaged version matching the one this build claims, and a SHA-256 comparison of every
file after a test extraction. Any failure exits non-zero and leaves the existing zip untouched,
so a broken package never ships.

The folder name is `localeyes-plugin`, not `localeyes-wp-plugin`. The older name was abandoned
because a poisoned leftover directory of that name blocks every upload (see **Troubleshooting the
install**). Nothing in the PHP depends on the folder name: every path derives from `__FILE__`
through `plugin_dir_path()`.

**Do not change the slug to ship a release.** WordPress identifies an installed plugin by the
string `localeyes-plugin/localeyes-nap.php`, so a build under a different slug is a *different
plugin* to WordPress: the update path never applies, the old copy stays installed and active, and
the site ends up with two LocalEyes entries fighting over the same drop-in and `.htaccess` blocks.
`.\build.ps1 -Slug some-other-name` exists for one job only — breaking the deadlock when a poisoned
folder blocks an install and no LocalEyes copy is active to self-heal it. Clear the stale folder
from **Configure > Installation**, then go back to the real slug.

### Never use `Compress-Archive`

**`Compress-Archive` on PowerShell 5.1 writes entry names with backslash separators**
(`localeyes-wp-plugin\localeyes-nap.php`). Backslash is a legal filename character on Linux, so
WordPress unpacks a flat pile of oddly named files instead of a directory tree. Nothing lands at
`localeyes-wp-plugin/localeyes-nap.php`, and activation fails with **"Plugin file does not
exist."** This is exactly how the 2.4.0-dev package broke. `build.ps1` writes entry names by hand
to avoid it.

## Troubleshooting the install

### "Plugin file does not exist." when you click Activate

The zip was built with `Compress-Archive`, so every entry name uses backslashes and WordPress never
created a directory tree. See **Never use `Compress-Archive`** above.

**Fix:** rebuild with `.\build.ps1` and reinstall.

### "Destination folder already exists. .../wp-content/plugins/<folder>/"

The package is fine. WordPress prints this *after* unpacking and validating the plugin header
(`Plugin_Upgrader::check_package()`), so reaching this error means the zip is structurally correct.
A fresh **upload** install passes `clear_destination => false`, so WordPress refuses to write into
an existing directory.

A leftover directory survives even after uninstalling from the admin when it holds files WordPress
cannot recognize as a plugin, which is also why the WP 5.5+ "Replace current with uploaded" screen
does not appear: that screen only shows when WordPress can read the existing plugin's header to
compare versions.

**This is handled automatically as long as a LocalEyes copy is active.**
`includes/install-heal.php` hooks `upgrader_source_selection` and removes the blocking directory so
the install proceeds. It only acts when the uploaded package's own header says LocalEyes, and it only
ever deletes a *direct child* of `wp-content/plugins` that is either LocalEyes or has no plugin
header at all, so another plugin's folder can never be touched. It snapshots first and reports what
it did in an admin notice.

**If no LocalEyes copy is active, nothing can run.** Nothing inside a zip executes during
installation: WordPress unzips, reads the header, and moves files. Break the deadlock either way:

- Install under a folder name that is not blocked: `.\build.ps1 -Slug some-other-name`. No file
  access needed. Once active, remove the stale folder from **Configure > Installation**.
- Or delete `wp-content/plugins/<folder>/` over SFTP, or run
  `wp plugin install <zip> --force`, which clears the destination and extracts fresh.

Do not merge new files over an old folder. The stale backslash-named files each end in `.php` and
carry the same `Plugin Name:` header, so `get_plugins()` reports a **duplicate** "LocalEyes Plugin"
row pointing at a path that cannot activate. Clear the folder instead.

### Removing leftover folders from the admin

**Configure > Installation** lists orphaned LocalEyes directories in `wp-content/plugins`, plus any
files at the plugins root with a backslash in the name (what a `Compress-Archive` zip unpacks to on
Linux). Each row has a Remove button. The running copy and any active plugin are never listed, and
the REST route re-runs the scan server-side so only a path the scanner itself produced can be
deleted. `GET /localeyes/v1/install/scan`, `POST /localeyes/v1/install/cleanup`.

## robots.txt, llms.txt, breadcrumbs, TOC, header/footer code

### What can be seen live, and what can be edited

| Endpoint | Seen live | Edited from the admin |
|---|---|---|
| `robots.txt` | Yes | Yes — append extra rules, or replace the whole file |
| `llms.txt`, `ads.txt`, `humans.txt`, `security.txt` | Yes | Yes — the box is the whole file |
| LocalEyes sitemap + children | Yes, with entry counts | **No, by design** (see below) |
| `wp-sitemap.xml`, `sitemap_index.xml` | Yes (status only) | No — they belong to core / another plugin |
| Any other path on the site | Yes, via the custom box | No |
| JSON-LD | Yes, per page | Yes, per page (Schema panel) |

**SEO > Tools > Live files** fetches every endpoint over HTTP and reports status, size and **which
source answered**: `localeyes` (our handler sets an `X-LocalEyes-Served` header, so this is read, not
guessed), `physical file`, `other`, or `missing`. Where a real file shadows something the plugin could
serve, **Take control** moves it aside — the same reversible fix as the Conflicts panel.

`.well-known/security.txt` is served at the location RFC 9116 requires, and at the bare `/security.txt`
for older scanners.

**Only `llms.txt` has a default body.** `ads.txt`, `humans.txt` and `security.txt` serve nothing until
you write something, and clearing the box returns the endpoint to a 404. That is deliberate: an
invented or empty `ads.txt` can stop ad revenue, so "not set" must mean "not there".

### robots.txt has two modes

`append` (the default) adds your lines to what WordPress generates and appends the sitemap line.
`replace` makes your box the entire file. Two guards apply to replace mode:

1. **An empty box falls back to append**, so robots.txt can never end up blank.
2. **A site set to discourage search engines stays blocked.** When `blog_public` is off, WordPress
   serves `Disallow: /`, and that line is re-asserted whatever your body says. Without this, a replace
   body that merely omitted the line would quietly open a staging site to crawlers.

### The sitemap is view-only on purpose

**SEO > Sitemap > Live sitemap** shows the index XML, each child with its URL count, and the status of
any competing sitemap. There is no XML editor because the XML does not exist as a file: it is generated
from your content and the settings on that tab on every request, so edited text would be discarded by
the next crawl. Change the settings to change the output.

### The two text files mean different things

- **robots.txt** is generated by WordPress. The box on SEO > Tools holds **extra lines appended to
  it**, not the whole file, which is why it is normally empty. The screen now shows the assembled live
  file above the box, with its HTTP status.
- **llms.txt** is the opposite: that box **is** the whole file. Until something is saved, `/llms.txt`
  serves a generated scaffold, and the screen labels it as such.

Both show what is actually being served, fetched over HTTP. **A real file in the web root wins over
both**, because the web server answers before WordPress runs, so the `robots_txt` filter and the
`/llms.txt` handler never fire. When that happens the screen says so and links to
**SEO > Sitemap > Conflicts**, where *Move aside* resolves it.

### Breadcrumbs

Detection fetches a real post and reports what is already rendering breadcrumbs (Rank Math, Yoast,
Breadcrumb NavXT, a theme, or `BreadcrumbList` JSON-LD) with the matched markup, so two trails on one
page is visible before it ships. **Adopt Rank Math settings** copies its separator, home label, prefix
and home toggle. Options: include home, hide on the front page, link the current page, include the
category path, truncate long labels, and an extra wrapper class.

### Table of contents

Global defaults on SEO > Tools: enabled, insert automatically, which post types, which heading levels
(H2 to H6), minimum headings, box heading, numbered, collapsible, and where to place it — top, after
the first paragraph, before the first heading, or only where `[localeyes_toc]` is placed.

Every post overrides those in the **LocalEyes SEO** box: always show / never show / follow the site
setting, plus its own levels, position and title. Stored in `_localeyes_toc`, so WordPress's Update
button saves it.

**Clicking an entry jumps to the heading.** That needs the ids in the headings and the `#hrefs` in the
list to come from one pass over one copy of the HTML, so the filter runs at priority 12, after
`do_blocks`, `wpautop` and `do_shortcode`. Running earlier built the list from markup that was still
going to change, so the anchors pointed at ids that never reached the page. A heading that already has
an id keeps it and the entry links to that id. A small stylesheet adds `scroll-margin-top`, since a
sticky header otherwise hides the heading you just jumped to.

### Header / footer code, per page

Every post and page has its own Head / Body open / Footer fields in the **LocalEyes SEO** box, stored
in `_localeyes_headfoot` and saved by WordPress's Update button. Per-post code outputs **after** the
site-wide code; a per-post *Do not output the site-wide code on this page* checkbox suppresses the
global block for that page only. The default is additive on purpose: adding a tag to one page must
never silently drop the analytics running everywhere else.

Both scopes stay behind the same gate. Nothing outputs until the `header-footer-code` replacement is
deployed on the Plugins tab, and the editor panel warns with a link when it is not, so code is never
saved into something inert.

**This field stores raw `<script>` unchanged, by design** — hosting tracking tags is the whole point.
Nothing is escaped or stripped. The boundary is capability: REST writes go through an `auth_callback`
requiring `le_cap()` plus `edit_post`, and the Classic form goes through a nonce plus the same check.
That is the same boundary the site-wide field on the Tools screen already used, so the per-page version
grants no new privilege.

## Plugin lifecycle (no file access required)

The plugin writes several things *outside* its own folder: the `advanced-cache.php` drop-in, a
`WP_CACHE` line in `wp-config.php`, marker blocks in `.htaccess`, `wp-content/cache/localeyes/`, and
optionally `wp-content/mu-plugins/localeyes-fatal-guard.php`. WordPress only ever deletes
`wp-content/plugins/localeyes-plugin/`, so the plugin cleans up after itself instead.

| Action | What happens |
|---|---|
| **Update to a new version** | Plugins > Add New > Upload the new zip, then click **Replace current with uploaded**. No deletion step, settings and data are kept. |
| **Deactivate** | Takes a final snapshot, then removes the drop-in, the `WP_CACHE` line, the `.htaccess` blocks, and all cron events. **Settings stay in the database**, so reactivating re-deploys whatever was enabled. |
| **Delete / uninstall** | Everything above, plus `wp-content/cache/localeyes/`, the fatal-guard mu-plugin, stale `wp-config.php` backups, orphaned LocalEyes folders in `wp-content/plugins`, and the legacy NAP options. Any web-root file the SEO conflict tool moved aside is **renamed back**. Runs regardless of the purge toggle. |
| **Purge toggle** (Configure tab) | Controls only *your data*: the `localeyes_*` options and transients, the `_localeyes_*` post meta, the dismissed-notice user meta, `uploads/localeyes-watermark/`, and the 17 `wp_localeyes_*` tables. Off by default so your data survives a reinstall. |

The teardown is `le_perf_teardown_all()` (`includes/perf/registry.php`), which reverses each module
through the same two paths `le_perf_toggle()` uses, so a module's revert logic is never duplicated.

Uninstall then runs a second, **marker-driven** pass (`localeyes_uninstall_shared()`). The teardown
above only reverts modules whose *stored state* still says enabled, which is the right rule on
deactivate but leaves a hole on delete: if `localeyes_perf` was lost or reset while the drop-in and
`WP_CACHE` line were live, neither was removed and the site kept serving stale cached HTML with no
plugin left to flush it. The second pass asks the filesystem instead of the database and removes
anything still carrying a LocalEyes marker. It never runs on deactivate — doing that is the exact
bug `registry.php` documents, where every deactivation regex-processed `wp-config.php` with nothing
to undo.

On **multisite**, uninstall loops every site with `switch_to_blog()`, the same way activation does.
Per-site work (options, transients, meta, tables, crons, that site's uploads) happens once per
subsite; shared work (the drop-in, `wp-config.php`, the web-root `.htaccess`, `wp-content/plugins`)
happens once for the network.

The fatal-guard mu-plugin can also be removed on its own with **Remove fatal guard** on the Monitor
tab, since a must-use plugin is not removable from the normal Plugins screen.

One cosmetic residue: clearing an `.htaccess` block uses core's `insert_with_markers`, which leaves
the `# BEGIN LocalEyes ...` / `# END LocalEyes ...` comment pair behind with no rules between them.
No directives stay active.

### Removing and installing again

Delete then re-upload is clean, and does not depend on the self-heal. Three reasons:

1. `delete_plugins()` removes the plugin directory **recursively**, so nothing is left to collide with.
2. Every packaged file has an ordinary name. `build.ps1` asserts zero backslash entry names and
   **fails the build** rather than producing the kind of zip that caused the original breakage, so it
   cannot be reintroduced.
3. The plugin never writes inside its own folder. `LOCALEYES_DIR` is used only for `require` and
   `glob`; every generated file goes to `wp-content/cache/localeyes`, `wp-content/mu-plugins`,
   `wp-content/advanced-cache.php`, or `wp-config.php`, all of which the uninstall teardown removes.

Uninstall additionally **sweeps any other stale LocalEyes directory** out of `wp-content/plugins`, so
residue from an earlier broken install cannot sit there waiting to block a later upload. It reuses the
same scan as the Installation panel, so it will never touch an active plugin, another plugin's folder,
or anything outside `wp-content/plugins`. One thing to know: an **inactive** older LocalEyes copy kept
deliberately for rollback is treated as residue and swept. An active one never is.

**Be clear on what the self-heal does and does not cover.** It clears a blocked destination during an
install, which makes *updates over a live install* seamless. It cannot help after a Delete, because at
that point no LocalEyes code is running. The delete/re-add path is safe for the three reasons above,
not because anything intercepts a leftover.

If a Delete ever fails partway because the host denies write permission, recover with no file access:
build under a fresh folder name with `.\build.ps1 -Slug <fresh-name>`, install that, then clear the
stale folder from **Configure > Installation**.

## Related

- Suspended-profile reinstatement context (Regus addresses, saturation flags, sequence): the
  Austin / Chicago / Dallas move data you would enter and save as a pair set for each move.
- AEO entity signals: schema and NAP consistency are exactly the surfaces AI answer engines read,
  which is why the plugin covers Rank Math schema and Elementor/Thrive data and not just the body.
