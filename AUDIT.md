# LocalEyes plugin audit

Read this before auditing. It exists because "I read the code and it looks right" is not evidence, and
this plugin is now ~34k lines across 89 PHP files and 4 JavaScript files.

The admin application is `assets/admin/admin.js`, a real file. It used to be a ~186KB nowdoc inside
`includes/admin-ui.php`, and this document said so long after it stopped being true - which is not a
harmless documentation lag. `audit.ps1` asserted that the largest embedded `<script>` block it could
parse was at least 50KB, on the strength of that sentence, and once the JavaScript moved out the
assertion could never be met: the check reported a broken extractor on every run. The only embedded
block left is the Search & Replace app in `localeyes-nap.php`, about 21KB.

## The rule

**Never conclude a feature works because the code looks correct. Conclude it from a check that would
fail if it were broken.**

Corollaries that have already cost real time on this codebase:

- A check that cannot fail proves nothing. If a check reports "clean", ask what would have to be true
  for it to report a problem, and confirm that path works. See *Negative control* below.
- A green tick from a stale harness is worse than no harness. One run of `filestest` reported a
  failure for `le_files_body('llms.txt')` that was purely the harness loading 3 modules while the
  function had moved to a 4th. If it had reported a pass for the wrong reason, nobody would have looked.
- A check that silently skips its most important input is a lie. `preg_match_all` with a lazy `(.*?)`
  over what was then a 186KB embedded admin block exceeded PCRE's backtrack limit and returned
  `false`, which is indistinguishable from "this file has no scripts". That silently skipped the
  single most important JavaScript block in the plugin while printing a pass. (The admin JS has since
  moved to `assets/admin/admin.js`; the lesson is the one that generalises.)
- A check calibrated against a codebase that has changed is the same failure wearing the other face.
  The size floor added to catch the case above outlived the layout it described, so it reported a
  broken extractor on every run instead. Both halves - too lenient, too strict - come from a check
  believing a description of the code rather than the code.

## Running it

```powershell
# once, to fetch PHPStan + the WordPress stubs into tools/vendor (~34MB, not committed)
.\tools\stan-setup.ps1

# static audit incl. PHPStan level 5; exits non-zero on a finding
.\tools\audit.ps1 -Php C:\path\to\php.exe

# runtime logic tests
.\tools\harness\run.ps1 -Php C:\path\to\php.exe
```

`-Php` is optional but three checks **skip** without it, and skips are printed in yellow rather than
counted as passes. Node is needed for the JavaScript checks and is detected automatically.

## What the static audit covers

| Check | Catches |
|---|---|
| `php -l` every file | Syntax errors |
| Duplicate declarations (tokenizer) | `Cannot redeclare` fatals |
| `le_*` called but never defined | Calls to functions that do not exist |
| String callables | `add_action('x','typo')`, `'callback' => 'typo'`, `'default' => 'typo'` |
| UI `api()` path vs registered routes | Buttons calling routes that do not exist |
| HTTP method mismatch | `api('x','POST')` where the route is GET-only |
| DOM id / class resolution | Handlers bound to elements that are never emitted |
| Duplicate ids per render block | A second control that `getElementById` can never reach |
| Missing `permission_callback` | Publicly callable REST routes |
| Duplicate route+method | A second registration that is unreachable |
| Argument counts | `ArgumentCountError` fatals |
| Option read/write | Both directions: a setting that saves and is never consumed, AND a `localeyes_` option that is READ but that nothing can ever write. The second half was missing, which is how `localeyes_pairs` ("Load defaults" always empty) and `localeyes_purge_on_uninstall` (an opt-in that could not be opted into) both shipped - each is a raw `get_option()`, invisible to a check that only tracked `le_opt`/`le_opt_set`. |
| JS syntax, incl. embedded blocks | One syntax error kills the whole admin UI |
| Modules on disk vs bootstrap | Code written but never loaded |
| **Activation wiring** | That `register_activation_hook` exists AND reaches `le_perf_redeploy_enabled()`. Deleting either line left every gate green while reactivation silently stopped re-applying module side effects. |
| **Unguarded optional functions** | `getmypid()` and friends called without `function_exists()` on a front-end path. A host that disables one makes PHP 8 throw `Error`, which on the page-cache store is a white screen for every visitor. |
| **Unwrapped .htaccess directives** | A directive written outside `<IfModule>`. Apache returns 500 for every request in that directory, and the cache directories serve the site's CSS and JS. |
| **UTF-8 BOM in any file** | Three stray bytes before `<?php` break `header()`, redirects and XML with "headers already sent". `build.ps1` only checks the main plugin file, which is how one shipped. |
| **PHPStan level 5** | Undefined variables and array keys, wrong argument types, null dereferences, **branches that can never be true**. The only check here that reasons about types and reachability rather than matching text. |

### On PHPStan and the missing baseline

There is deliberately **no `phpstan-baseline.neon`**. Every level-5 finding was fixed rather than
suppressed, so the gate runs against zero. If you add a baseline later, treat it as debt with a date
on it, not as a resting state.

Two config details worth understanding before touching `tools/phpstan.neon`:

- `dynamicConstantNames` lists `DOING_AUTOSAVE`, `REST_REQUEST`, `WP_CACHE` and friends. Without it,
  the concrete values in `phpstan-bootstrap.php` make PHPStan treat `defined('DOING_AUTOSAVE') &&
  DOING_AUTOSAVE` as provably false and report every such guard as dead code. Those findings are an
  artifact of the analysis setup, not bugs.
- `reportUnmatchedIgnoredErrors: true` is on because the first version of the ignore list used
  `foo\(\)` while PHPStan writes `Function foo not found.` with no parentheses, so it silently matched
  nothing. A rotting ignore list now fails loudly.

### What it does NOT cover

- **Whether any feature does the right thing.** These checks prove things are connected and parse.
- `api()` calls whose path is built from a variable. Reported as a SKIP, not a pass. Two exist:
  `PERF_ACT_EP[act]` in the Performance screen and the schema-build call in the panel.
- Anything requiring a live WordPress: hook ordering, capability behaviour, actual HTTP responses,
  whether the browser renders what you expect.
- PHP fatals that only occur on a code path (undefined variables, wrong types, null dereferences).

## False positives, with their exact shapes

Every one of these has been hit here. **Open the file and read it before believing a finding.**

| Reported as | Actually | Why |
|---|---|---|
| `#sm-images` never emitted | fine | `ck()` emits `id="'+id+'"`; there is no literal to match |
| `#m-title` never emitted | fine | `f()` emits `id="m-'+k+'"`; matched by *prefix*, not literal |
| `.le-deploy` never emitted | fine | `class="button '+expr+' le-deploy"`; the class follows a concat |
| `#le-perf-mount` never emitted | fine | emitted by PHP, consumed by an asset JS file (cross-file) |
| JS syntax error in `autofix.php` | fine | the `<script>` is inside a PHP **regex literal**, not output |
| JS syntax error in `preload-links.php` | fine | the script is built by PHP string concatenation |
| JS syntax error in `localeyes-nap.php` | fine | the block contains `<?php echo ... ?>` |
| `function api` declared twice | fine | one is **JavaScript** inside the nowdoc |
| `le_x() is never defined` | sometimes | another editor may be mid-write. **Re-run before believing a single hit.** |
| `le_x() is never defined` | fine if guarded | a call wrapped in `function_exists('le_x')` is an optional cross-module dependency |

`tools/audit.ps1` already suppresses the resolvable ones (prefixes, cross-file, `function_exists`
guards) and `tools/phpscan.php` uses `token_get_all` so PHP source is never mistaken for output.
The remaining rows are here so a future hit is recognised rather than re-investigated.

## Negative control

A passing audit means nothing until you have seen it fail. Break one thing on purpose and confirm the
audit catches it:

```powershell
# rename a route the UI calls, run the audit, expect a FIND, then undo
```

Do this whenever you change `tools/audit.ps1`. A refactor that makes the audit quieter is a
regression, not an improvement.

## Findings log

Append, never overwrite. A fixed item stays here so it is not rediscovered as new.

### 2026-08-07 — first full audit

Scope: 81 PHP files (17.4k lines), 3 JS files, the 183KB admin block. All 14 static checks run.

Clean: syntax, duplicate declarations, undefined functions, string callables, 123 UI `api()` paths
against 158 routes, HTTP methods, DOM ids and classes, duplicate ids, permission callbacks, duplicate
routes, argument counts, JS syntax, module loading.

| # | Finding | Severity | Status |
|---|---|---|---|
| 1 | `GET /seo/files/live` probed 9-10 endpoints **sequentially at 20s each** in one REST call, and SEO > Tools auto-fired that plus 2 more loopback fetches. On a host that blocks loopback the panel hung for minutes or died at the proxy. | High | **Fixed** — list-only by default, `?only=` per endpoint probed in parallel from the browser, 5s timeout |
| 2 | `GET /seo/robots` fetched loopback just to open the editor | Medium | **Fixed** — `?live=1` opt-in |
| 3 | Sitemap tab probed 2 competing sitemaps on load | Low | **Fixed** — listed without status |
| 4 | Breadcrumb detection fetched a page on every tab load | Low | **Fixed** — behind a button |
| 5 | `perf_page_cache_status` written, never read | Low | **Fixed** — removed; `le_page_cache_stats()` derives it live |
| 6 | `build.ps1` filtered only by extension, so a new `tools/` dir would ship inside the plugin zip | High | **Fixed** — directory exclusion plus an assertion on the built archive |
| 7 | Harness loaded 3 modules and reported a false failure after `le_llms_default()` moved | Medium | **Fixed** — see harness rot detection |
| 10 | llms.txt and the sitemap enumerated `get_post_types(array('public' => true))`, so Elementor templates, Thrive symbols and lightboxes, Popup Maker popups and popup themes were published as if they were pages | High | **Fixed** — `le_is_content_post_type()` in `includes/posttypes.php` |
| 11 | Fixing 10 in the sitemap alone left `scan.php` enumerating the old way, so every builder entry became a false `missing_from_sitemap` finding in the coverage report | Medium | **Fixed** — `scan.php` and `psi.php` share the helper; pinned in `llmstest` |

### Trade-offs in the post-type filter, recorded so they are not rediscovered

`le_is_content_post_type()` (`includes/posttypes.php`) applies four structural tests plus a
deny-list. Known consequences, none of them defects:

- **`show_in_nav_menus` is the gate most likely to produce a false negative.** If a legitimate CPT
  disappears from llms.txt or the sitemap, check that gate first. The `localeyes_content_post_types_deny`
  filter is not the fix — loosening the gate is.
- **The deny-list is redundant with the structural tests on a normally configured site**, and
  deliberately so. `llmstest` proves this by emptying it via the filter and re-asserting; only
  `wp_block`-style types, which are structurally indistinguishable from content, actually need it.
- **Sub-sitemaps for the removed types now 404** (`localeyes-sitemap-popup-1.xml` and friends).
  Search Console will report them until Google drops them. No action needed.
- **llms.txt is not cached.** `le_files_body()` (`includes/seo/files.php:70`) regenerates on every
  request when the option is empty, and the default limits are now 300 pages / 100 posts. Saving once
  in the editor stores the body and ends the regeneration. This is the intended path, not a
  workaround.
- **~12 other `get_post_types(array('public' => true))` call sites are intentionally untouched** —
  `seo/bulk.php`, `seo/listtable.php`, `seo/canonical.php`, `seo/toc*.php`, `inventory.php`,
  `aeo/settings.php`. They decide which editors get a meta box and which rows appear in admin grids,
  where "every public type" is correct. Only the four outward-facing enumerations (llms.txt, sitemap,
  crawler, PageSpeed) needed the narrower test.
- **A missing `posttypes.php` degrades silently.** `bootstrap.php` guards modules with `file_exists`
  and all four consumers guard with `function_exists`, so deleting it restores the old behavior
  rather than failing loudly. That is why the module is declared in `llmstest`'s `needs`.

Open at time of writing, all from another editor's in-flight work rather than shipped behaviour:

| # | Finding | Note |
|---|---|---|
| 8 | `includes/aeo/settings.php`, `includes/aeo/tools.php`, `includes/aeo/crawlers.php` on disk but not in `bootstrap.php` | ~27KB+ each, never loaded, so their REST routes do not exist. **The route check cannot see this**: it reads routes from files on disk whether or not they load. Confirm against bootstrap. |
| 9 | `le_perf_page_aeo()` called at `includes/perf/overrides.php:199`, not defined and not guarded | Would fatal if that path runs |

Findings 8 and 9 were **real when reported and resolved during the session** by the other editor:
`bootstrap.php` was updated to load all eight AEO modules. That is worth recording, because it shows
this codebase can change under an audit. Re-run before acting on any single finding, and note the
timestamps.

### 2026-08-07 (later) — PHPStan level 5 introduced

Scope: 84 PHP files. First run: **89 findings**. After adding the missing WordPress constants to
`phpstan-bootstrap.php`, correcting the ignore patterns and setting `dynamicConstantNames`, the
genuine set was **12**. All 12 fixed. Final state: **0 findings at level 5**, no baseline.

| # | Finding | Severity | Status |
|---|---|---|---|
| 10 | **UTF-8 BOM in `includes/seo/toc.php`.** Three bytes before `<?php`, which causes "headers already sent" and can corrupt `header()`/XML output. `build.ps1` BOM-checks only the main plugin file, so it shipped. | High | **Fixed** — BOM stripped, and the audit now checks every file |
| 11 | **`includes/seo/inspect.php`: dead branch.** `isset($result['term'])` tested a key the array never has (terms are reported under `post`), so the block could never run. A feature that silently did nothing. | Medium | **Fixed** — dead block and its unused `$tlink` removed |
| 12 | `includes/perf/cdn.php`: `$ex !== ''` on a list built with empties already filtered out | Low | **Fixed** |
| 13 | `includes/seo/listtable.php`: `esc_html(int)` where a string is expected | Low | **Fixed** — explicit cast |
| 14 | `includes/helpers.php`: `$out['error'] === ''` always true at that point | Low | **Fixed** |
| 15 | 6 × `isset($x) && $x !== null` — `isset()` already excludes null, so the second half was dead | Low | **Fixed** (cwv, overview ×2, font-audit, inspect ×2) |
| 16 | 5 × `isset($post->ID)` on a non-nullable property; the real intent was "is this a usable object" | Low | **Fixed** — `instanceof WP_Post` / `instanceof WP_Term` |

Both new gates were **negative-controlled**: injecting `$undefinedOnPurpose` made PHPStan report
`Undefined variable` at the exact line and the audit exit 1; adding a BOM made the BOM check fire.

### Not yet covered

Three layers, and it matters which one covers what:

| Layer | Covers | Proves |
|---|---|---|
| `audit.ps1` static checks | all 87 files | wired up, parses, loads |
| PHPStan level 5 | all 87 files | internally type-consistent, no dead branches |
| `harness/run.ps1` | 8 modules | the code returns the **right values** |

Runtime logic tests exist for `install-heal`, `posttypes`, `aeo/llms`, `seo/sitemap`, `seo/toc`,
`seo/breadcrumbs`, `seo/robots`, `seo/files` and the `.htaccess`/`wp-config` guards. **No runtime
tests yet for:**
`scan.php`, `aeo/audit.php`, `monitor.php`, `aeo/a11y.php`, `psi.php` (the five largest and riskiest,
because they parse external input and compute verdicts, so a wrong answer is silent), plus
`aeo/webmcp`, `aeo/fixes`, `aeo/tools`, `aeo/crawlers`, `aeo/settings`, `cwv.php`, `detect.php`.

Those have static coverage only. **This is the honest next task**, not a claim of completeness: PHPStan
proves those files are internally consistent, and nothing yet proves their scoring or parsing is
correct.

### Known blind spot introduced by finding 8

The route check counts a route as registered if any file declares it. If that file is not in
`bootstrap.php`, the route does not exist at runtime and a button calling it fails while the audit
says "resolves". Cross-check the module-loading section before trusting the route section.
