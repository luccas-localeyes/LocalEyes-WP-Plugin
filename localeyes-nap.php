<?php
/**
 * Plugin Name: LocalEyes Plugin
 * Description: LocalEyes control panel for site speed and structure. Scans PageSpeed Insights / Core Web Vitals, reports a dedicated TTFB analysis, builds native replacements for other plugins (copy their data, prove parity against the live page, then recommend you remove them yourself), provides a full SEO suite (meta, canonical, robots, redirects, table of contents, XML sitemap, robots.txt, llms.txt, breadcrumbs, schema), GSC index hygiene, and snapshot backups of everything it changes. Also includes the original NAP find-and-replace engine across page content, Elementor, Thrive, Rank Math, and options. The tool never deactivates or deletes any plugin.
 * Version: 2.7.6
 * Author: LocalEyes
 * Requires at least: 5.6
 * Requires PHP: 7.0
 * License: GPL-2.0-or-later
 * Update URI: false
 *
 * That header is not decoration. Since WP 5.8 a plugin that declares no update URI is still
 * matched against the wordpress.org directory BY FOLDER SLUG, and this plugin installs to
 * wp-content/plugins/localeyes-plugin/. If anyone ever registers that slug on wordpress.org, every
 * site running this would be offered - and with auto-updates on, silently given - a stranger's code
 * in its place. 'false' opts out of the .org update channel permanently. It is ignored harmlessly on
 * WP 5.6 and 5.7, and it does NOT stop the manual "Replace current with uploaded" upload path, which
 * is how this plugin is actually updated. See README, "Updating".
 *
 * Auth: the admin screens use your logged-in session. Every route, legacy NAP and v2 control panel
 * alike, needs `manage_options`. The REST API also accepts WordPress Application Passwords
 * (Basic auth).
 */

if (!defined('ABSPATH')) { exit; }

define('LOCALEYES_NAP_VERSION', '2.7.6');
define('LOCALEYES_VER', '2.7.6');

/**
 * Schema revision. Bump this by one whenever includes/db.php changes a table.
 *
 * Deliberately separate from LOCALEYES_VER: that string is the plugin header version and does not
 * change between dev builds, so gating dbDelta on it meant that once a site had recorded
 * '2.5.0-dev' no later schema change was ever applied. A column added after that point was never
 * created and every insert touching it failed silently.
 */
// Bump this on any schema change, and on any one-time migration that must reach existing installs.
// 2: le_schema_migrate_page_rows(). It was moved inside the db_ver gate to save a get_option() per
// request, but the constant stayed at 1 and every install that has ever activated already recorded
// 1 - so the gate was false forever and the migration became dead code on the entire installed base.
// Page-scope schema stayed in the table while the readers had moved to post meta, which means those
// blocks silently stopped being emitted.
// 3: le_migrate_clear_dead_psi_key() - removes the revoked bundled PageSpeed key so scans stop
//    failing with "API key not valid" on installs that were seeded with it.
define('LOCALEYES_DB_VER', 3);
define('LOCALEYES_FILE', __FILE__);
define('LOCALEYES_DIR', plugin_dir_path(__FILE__));
define('LOCALEYES_URL', plugin_dir_url(__FILE__));

// Modular bootstrap: loads the v2 control-panel modules (PSI, jobs, SEO suite, backups, dashboards).
// Kept separate so the proven NAP engine below stays intact.
require_once LOCALEYES_DIR . 'includes/bootstrap.php';

/* ============================ REST API ============================ */

add_action('rest_api_init', function () {
    /*
     * manage_options, the same as every other LocalEyes route, via the shared callback.
     *
     * These four were the last routes still gated at edit_pages after le_cap() was hard-wired to
     * manage_options. That left an Editor able to call POST /nap/apply - a site-wide search-and-
     * replace across post content AND options - and POST /nap/rollback to undo it, plus GET /backups,
     * while the same Editor could not read /perf/cache/stats. includes/backups.php states the rule
     * for exactly this: a restore "can overwrite site-wide settings wholesale", so it requires
     * manage_options. GET /backups is the legacy twin of that route and did not.
     *
     * Rewriting every page on a site is not a page-editing capability.
     */
    $perm = 'le_rest_perm';
    register_rest_route('localeyes/v1', '/ping',         ['methods' => 'GET',  'permission_callback' => $perm, 'callback' => 'localeyes_nap_ping']);
    register_rest_route('localeyes/v1', '/nap/preview',  ['methods' => 'POST', 'permission_callback' => $perm, 'callback' => 'localeyes_nap_preview']);
    register_rest_route('localeyes/v1', '/nap/apply',    ['methods' => 'POST', 'permission_callback' => $perm, 'callback' => 'localeyes_nap_apply']);
    register_rest_route('localeyes/v1', '/nap/rollback', ['methods' => 'POST', 'permission_callback' => $perm, 'callback' => 'localeyes_nap_rollback']);
    register_rest_route('localeyes/v1', '/backups',      ['methods' => 'GET',  'permission_callback' => $perm, 'callback' => 'localeyes_nap_backups_list']);
    register_rest_route('localeyes/v1', '/targets',      ['methods' => 'GET',  'permission_callback' => $perm, 'callback' => 'localeyes_nap_targets']);
    // Saved find/replace pairs. The option was read by localeyes_nap_default_pairs() and written by
    // nothing at all: no route, no button, no code path anywhere in the plugin. "Load defaults"
    // therefore always returned an empty list, and because the screen calls it on load, step 1
    // rendered with zero rows and looked broken until the user found "+ Add row".
    register_rest_route('localeyes/v1', '/nap/pairs',    ['methods' => 'GET',  'permission_callback' => $perm, 'callback' => 'localeyes_nap_pairs_get']);
    register_rest_route('localeyes/v1', '/nap/pairs',    ['methods' => 'POST', 'permission_callback' => $perm, 'callback' => 'localeyes_nap_pairs_set']);
});

/** The saved pairs, as the admin screen renders them. */
function localeyes_nap_pairs_get() {
    return ['ok' => true, 'pairs' => localeyes_nap_default_pairs()];
}

/**
 * Replace the saved pair set.
 *
 * The whole list is sent every time rather than one row at a time: the screen edits a table in place,
 * and a per-row API would need ids the option shape does not have. An empty array is a legitimate
 * payload and is how Delete all works, so this cannot use "empty means leave it alone".
 */
function localeyes_nap_pairs_set($req) {
    $in = $req->get_param('pairs');
    if (!is_array($in)) { return new WP_REST_Response(['ok' => false, 'error' => 'pairs must be a list'], 400); }

    $pairs = [];
    foreach ($in as $row) {
        if (!is_array($row)) { continue; }
        $from = isset($row['from']) ? sanitize_text_field((string) $row['from']) : '';
        $to   = isset($row['to'])   ? sanitize_text_field((string) $row['to'])   : '';
        if ($from === '') { continue; }   // a pair with nothing to find is not a pair
        $pairs[] = ['from' => $from, 'to' => $to];
    }
    update_option('localeyes_pairs', $pairs, false);
    return ['ok' => true, 'pairs' => $pairs, 'saved' => count($pairs)];
}

/** List scannable posts (id + title) so the admin screen can show live "searching X / N" progress. */
function localeyes_nap_targets() {
    global $wpdb;
    $rows = $wpdb->get_results("SELECT ID, post_title, post_type FROM {$wpdb->posts}
        WHERE post_status IN ('publish','draft','pending','private','future') ORDER BY ID");
    $out = [];
    foreach ($rows as $r) { $out[] = ['id' => (int) $r->ID, 'title' => (string) $r->post_title, 'type' => (string) $r->post_type]; }
    return ['ok' => true, 'targets' => $out, 'total' => count($out)];
}

function localeyes_nap_backups_list() {
    $index = get_option('localeyes_nap_backups', []);
    if (!is_array($index)) $index = [];
    return ['ok' => true, 'backups' => array_reverse(array_values($index))];
}

function localeyes_nap_ping() {
    return [
        'ok'        => true,
        'plugin'    => 'localeyes-plugin',
        'version'   => LOCALEYES_NAP_VERSION,
        'elementor' => defined('ELEMENTOR_VERSION'),
        'rankmath'  => defined('RANK_MATH_VERSION') || class_exists('RankMath'),
        'site'      => home_url('/'),
    ];
}

/** Parse + sanitize the replacement pairs and optional post_ids scope. */
function localeyes_nap_input($req) {
    $reps = $req->get_param('replacements');
    $pairs = [];
    if (is_array($reps)) {
        foreach ($reps as $r) {
            $from = isset($r['from']) ? (string) $r['from'] : '';
            $to   = isset($r['to'])   ? (string) $r['to']   : '';
            if ($from !== '' && $from !== $to) { $pairs[] = ['from' => $from, 'to' => $to]; }
        }
    }
    $ids = $req->get_param('post_ids');
    $post_ids = is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : [];
    $ft = $req->get_param('field_types');
    $field_types = is_array($ft) ? array_values(array_filter(array_map('strval', $ft))) : [];
    return [$pairs, $post_ids, $field_types];
}

/**
 * Which builder made this post? elementor | thrive | bakery | classic
 *
 * The four-value contract is frozen; three callers depend on it. See includes/detect.php for the richer
 * answer the Scan screen uses.
 *
 * The two value tests are deliberate and are the same fix made in detect.php: WPBakery writes
 * _wpb_vc_js_status as the string 'false' on any post saved through the editor, and 'false' is truthy,
 * so the bare test reported every page on a WPBakery site as 'bakery'. _elementor_data is commonly '[]'
 * on a post opened in Elementor and left empty. le_detect_meta_has_data() is guarded because
 * includes/detect.php loads after this file.
 */
function localeyes_nap_builder($post_id) {
    $post_id = (int) $post_id;
    $has = function ($raw) {
        return function_exists('le_detect_meta_has_data') ? le_detect_meta_has_data($raw) : !empty($raw);
    };
    if (get_post_meta($post_id, '_elementor_edit_mode', true) === 'builder' || $has(get_post_meta($post_id, '_elementor_data', true))) return 'elementor';
    if (get_post_meta($post_id, 'tve_updated_post', true) || get_post_meta($post_id, 'tve_content_before_more', true)) return 'thrive';
    $content = (string) get_post_field('post_content', $post_id);
    if (strpos($content, '[vc_') !== false || get_post_meta($post_id, '_wpb_vc_js_status', true) === 'true') return 'bakery';
    return 'classic';
}

/** Map a stored-field name to a user-facing category. */
function localeyes_nap_category($field) {
    if ($field === 'post_content') return 'body';
    if ($field === '_elementor_data') return 'elementor';
    if (strpos($field, 'tve_') === 0) return 'thrive';
    if (strpos($field, 'rank_math_') === 0) return 'schema';
    if (strpos($field, 'option:') === 0) return 'widgets';
    return 'other';
}

/** Count occurrences of $from across string leaves of a (possibly nested) option value. */
function localeyes_nap_deep_count($val, $from) {
    if (is_string($val)) return substr_count($val, $from);
    $c = 0;
    if (is_array($val) || is_object($val)) { foreach ((array) $val as $v) $c += localeyes_nap_deep_count($v, $from); }
    return $c;
}

/** Replace across string leaves of a nested value, preserving structure. Sets $changed by ref. */
function localeyes_nap_deep_replace($val, $pairs, &$changed) {
    if (is_string($val)) {
        $n = $val;
        foreach ($pairs as $p) $n = str_replace($p['from'], $p['to'], $n);
        if ($n !== $val) $changed = true;
        return $n;
    }
    if (is_array($val)) { foreach ($val as $k => $v) $val[$k] = localeyes_nap_deep_replace($v, $pairs, $changed); return $val; }
    if (is_object($val)) { foreach (get_object_vars($val) as $k => $v) $val->$k = localeyes_nap_deep_replace($v, $pairs, $changed); return $val; }
    return $val;
}

/** Context fragments around every occurrence of $from in $text (raw; the UI escapes + highlights). */
function localeyes_nap_snippets($text, $from, $max = 50) {
    if (!is_string($text) || $from === '') return [];
    $out = []; $len = strlen($from); $off = 0; $n = 0;
    while (($pos = strpos($text, $from, $off)) !== false) {
        $start = max(0, $pos - 45);
        $frag = substr($text, $start, ($pos - $start) + $len + 45);
        $frag = trim(preg_replace('/\s+/', ' ', $frag));
        $out[] = ($start > 0 ? '…' : '') . $frag . '…';
        $off = $pos + $len; $n++;
        if ($n >= $max) break;
    }
    return $out;
}

/** Same, but across the string leaves of a nested option value (widgets / theme mods). */
function localeyes_nap_deep_snippets($val, $from, $max = 50) {
    $out = [];
    $walk = function ($v) use (&$walk, &$out, $from, $max) {
        if (count($out) >= $max) return;
        if (is_string($v)) { foreach (localeyes_nap_snippets($v, $from, $max - count($out)) as $s) $out[] = $s; return; }
        if (is_array($v) || is_object($v)) { foreach ((array) $v as $x) { if (count($out) >= $max) break; $walk($x); } }
    };
    $walk($val);
    return $out;
}

/**
 * Classify a stored meta value so scan and apply can work on its DECODED form.
 *
 * A meta value is one of three things: a plain string; a serialized array, which get_post_meta() has
 * already turned back into a PHP array; or a JSON string, which is how Elementor stores _elementor_data
 * and how most tve_* fields are stored. Elementor writes JSON as wp_slash(wp_json_encode(...)), so in the
 * database `/` is `\/` and every non-ASCII character is `\uXXXX`. A literal substr_count / str_replace on
 * that raw string therefore never matches a search for a URL (`https://old.com`) or an accented name
 * (`Peña`), and writing a replacement that contains a quote into it produces invalid JSON that blanks the
 * page. Decoding first fixes both: matching happens on the real text, and re-encoding after replacement
 * guarantees the stored bytes are valid JSON whatever the replacement contained.
 *
 * Returns array('kind' => 'array'|'json'|'string', 'data' => decoded-structure-or-original-string).
 */
function localeyes_nap_meta_shape($val) {
    if (is_array($val)) { return ['kind' => 'array', 'data' => $val]; }
    if (is_string($val)) {
        // Only try to decode something that could plausibly be a JSON array/object, so a plain string that
        // merely looks numeric or is a bare word is never misread as JSON.
        $trim = ltrim($val);
        if ($trim !== '' && ($trim[0] === '[' || $trim[0] === '{')) {
            // Decode WITHOUT the assoc flag so a JSON object stays an object. json_decode($v, true) turns
            // every {} into [], and re-encoding would then rewrite an empty Elementor settings object as
            // an empty array. localeyes_nap_deep_count/replace/snippets all handle stdClass, and
            // wp_json_encode restores the exact array-vs-object shape.
            $decoded = json_decode($val);
            if (is_array($decoded) || is_object($decoded)) { return ['kind' => 'json', 'data' => $decoded]; }
        }
        return ['kind' => 'string', 'data' => $val];
    }
    return ['kind' => 'string', 'data' => (string) $val];
}

/**
 * Decode one WPBakery Raw HTML / Raw JS payload.
 *
 * `[vc_raw_html]` and `[vc_raw_js]` store their content as base64_encode(rawurlencode($html)), so the
 * readable text never appears in post_content and a literal substr_count / LIKE for it always misses -
 * this is why an address inside the footer's Raw HTML block was invisible to Search & Replace. Strict
 * base64 tolerates the rare block stored un-encoded (returns it as-is).
 */
function localeyes_nap_wpb_raw_decode($payload) {
    $payload = trim((string) $payload);
    if ($payload === '') { return ''; }
    $b = base64_decode($payload, true);
    if ($b === false) { return $payload; }
    return rawurldecode($b);
}

/** The decoded text of every WPBakery Raw HTML/JS block in $content, for searching. */
function localeyes_nap_wpb_raw_decode_all($content) {
    if (!is_string($content) || strpos($content, '[vc_raw_') === false) { return ''; }
    $out = '';
    if (preg_match_all('/\[vc_raw_(html|js)\b[^\]]*\](.*?)\[\/vc_raw_\1\]/s', $content, $m, PREG_SET_ORDER)) {
        foreach ($m as $mm) { $out .= localeyes_nap_wpb_raw_decode($mm[2]) . "\n"; }
    }
    return $out;
}

/**
 * Replace inside WPBakery Raw HTML/JS blocks: decode, str_replace, re-encode as
 * base64_encode(rawurlencode()) so the stored bytes stay in WPBakery's own format. Only touched blocks
 * are re-encoded; an unchanged block is returned byte-for-byte so nothing else in the field shifts.
 */
function localeyes_nap_wpb_raw_replace($content, $pairs, &$changed) {
    if (!is_string($content) || strpos($content, '[vc_raw_') === false) { return $content; }
    return preg_replace_callback('/(\[vc_raw_(html|js)\b[^\]]*\])(.*?)(\[\/vc_raw_\2\])/s',
        function ($m) use ($pairs, &$changed) {
            $open = $m[1]; $payload = $m[3]; $close = $m[4];
            $b = base64_decode(trim($payload), true);
            $html = ($b === false) ? $payload : rawurldecode($b);
            $new = $html;
            foreach ($pairs as $p) { $new = str_replace($p['from'], $p['to'], $new); }
            if ($new === $html) { return $m[0]; }
            $changed = true;
            $encoded = ($b === false) ? $new : base64_encode(rawurlencode($new));
            return $open . $encoded . $close;
        }, $content);
}

/**
 * Normalise text for tag-tolerant DETECTION (find only, never for the write).
 *
 * Strips HTML tags, decodes entities, folds smart quotes/dashes to ASCII, then reduces every run of
 * punctuation OR whitespace to a single space and lowercases - so an address stored as
 * `715 X St<br />Suite 100<br />Atlanta` is detected by a one-line comma-separated search even though
 * no literal substring matches (the <br /> leaves no comma). It does NOT unify different wording
 * (`Suite 100` vs `#100TH` stay different). Replacement stays literal and case-exact, so a
 * detected-but-not-literal page is reported and never rewritten across tags.
 */
function localeyes_nap_normalize_text($s) {
    $s = (string) $s;
    if ($s === '') { return ''; }
    $s = wp_strip_all_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES);
    $s = str_replace(
        array("\xE2\x80\x9C", "\xE2\x80\x9D", "\xE2\x80\x98", "\xE2\x80\x99", "\xE2\x80\x93", "\xE2\x80\x94", "\xE2\x80\xA6", "\xC2\xA0"),
        array('"', '"', "'", "'", '-', '-', '...', ' '),
        $s
    );
    // One separator for any run of punctuation/whitespace (commas, '#', the space a <br /> became),
    // so a comma-separated one-line search matches the same address split across tags. Detection only,
    // so leaning permissive is safe. The /u pass returns null on invalid UTF-8; fall back if so.
    $folded = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
    $s = ($folded !== null) ? $folded : preg_replace('/\s+/', ' ', $s);
    $s = trim($s);
    return function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
}

/**
 * Scan for each `from`. No writes. field_types are ROUTING TOKENS (empty = everything):
 *   classic/bakery -> post_content (split by builder) · elementor -> _elementor_data
 *   thrive -> tve_* meta · schema -> rank_math_* post meta
 *   widgets -> widget_/theme_mods_ options (site-wide) · schema_opts -> rank_math/rank-math/wpseo options (site-wide)
 */
function localeyes_nap_scan($pairs, $post_ids = [], $field_types = []) {
    global $wpdb;
    $hits = [];
    $idClausePM = $post_ids ? (' AND pm.post_id IN (' . implode(',', $post_ids) . ')') : '';

    $allow = function ($tok) use ($field_types) { return empty($field_types) || in_array($tok, $field_types, true); };
    $wantContent = $allow('classic') || $allow('bakery');
    $metaConds = [];
    if ($allow('elementor')) $metaConds[] = "pm.meta_key = '_elementor_data'";
    if ($allow('thrive'))    $metaConds[] = "pm.meta_key LIKE 'tve_%%'";
    if ($allow('schema'))    $metaConds[] = "pm.meta_key LIKE 'rank_math_%%'";
    $wantWidgets   = $allow('widgets');
    $wantSchemaOpt = $allow('schema_opts');

    $builders = [];
    $builderOf = function ($pid) use (&$builders) {
        if (!isset($builders[$pid])) $builders[$pid] = localeyes_nap_builder($pid);
        return $builders[$pid];
    };

    foreach ($pairs as $p) {
        $like = '%' . $wpdb->esc_like($p['from']) . '%';

        // post_content — matched on the RAW stored bytes plus the decoded text of any WPBakery Raw
        // HTML/JS blocks (base64), and, when the literal is absent, a tag-tolerant DETECTION pass.
        if ($wantContent) {
            // Row selection: the batched client always scopes to post_ids, so evaluate those in PHP -
            // the SQL LIKE cannot see text that is base64-encoded in a raw block or split across tags.
            // Only the unscoped (site-wide) path falls back to LIKE, widened to also fetch raw-block pages.
            if ($post_ids) {
                $content_ids = $post_ids;
            } else {
                $rawLike = '%' . $wpdb->esc_like('[vc_raw_') . '%';
                $content_ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts}
                     WHERE post_status IN ('publish','draft','pending','private','future')
                     AND (post_content LIKE %s OR post_content LIKE %s)", $like, $rawLike));
            }
            $needleNorm = localeyes_nap_normalize_text($p['from']);
            foreach ($content_ids as $cid) {
                $post = get_post((int) $cid);
                if (!$post) { continue; }
                $raw = (string) $post->post_content;   // raw stored bytes, not the display-filtered copy
                $decodedRaw = localeyes_nap_wpb_raw_decode_all($raw);
                $b = $builderOf($post->ID);
                $cat = ($b === 'bakery') ? 'bakery' : 'classic';
                $c = substr_count($raw, $p['from']) + ($decodedRaw !== '' ? substr_count($decodedRaw, $p['from']) : 0);
                if ($c > 0) {
                    $h = localeyes_nap_hit($post->ID, $post->post_title, $post->post_type, 'post_content', $p, $c, $b, $cat);
                    $snips = localeyes_nap_snippets($raw, $p['from']);
                    if ($decodedRaw !== '') { $snips = array_merge($snips, localeyes_nap_snippets($decodedRaw, $p['from'])); }
                    $h['snippets'] = $snips;
                    $hits[] = $h;
                } elseif ($needleNorm !== '') {
                    // Tag-tolerant DETECTION: present once tags/whitespace/case/punctuation are normalised
                    // (e.g. an address split across <br />). Reported, never auto-replaced.
                    $norm = localeyes_nap_normalize_text($raw . ' ' . $decodedRaw);
                    if (strpos($norm, $needleNorm) !== false) {
                        $h = localeyes_nap_hit($post->ID, $post->post_title, $post->post_type, 'post_content', $p, substr_count($norm, $needleNorm), $b, $cat);
                        $h['match'] = 'split';
                        $h['snippets'] = localeyes_nap_snippets($norm, $needleNorm);
                        $hits[] = $h;
                    }
                }
            }
        }

        // _elementor_data + tve_* + rank_math_* meta (only the requested kinds)
        if (!empty($metaConds)) {
            // Match rows by the raw needle OR its JSON-escaped form. Elementor/Thrive store JSON where `/`
            // is `\/` and non-ASCII is `\uXXXX`, so a URL or accented term appears ONLY in the escaped
            // form; without this second LIKE those rows are never even fetched and the count is wrong.
            $jsonNeedle = trim((string) wp_json_encode($p['from']), '"');
            $likeJson = '%' . $wpdb->esc_like($jsonNeedle) . '%';
            $mrows = $wpdb->get_results($wpdb->prepare(
                "SELECT pm.post_id, pm.meta_key, p.post_title, p.post_type
                 FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE (" . implode(' OR ', $metaConds) . ")
                 AND p.post_status IN ('publish','draft','pending','private','future')
                 AND (pm.meta_value LIKE %s OR pm.meta_value LIKE %s)" . $idClausePM, $like, $likeJson));
            foreach ($mrows as $row) {
                $shape = localeyes_nap_meta_shape(get_post_meta($row->post_id, $row->meta_key, true));
                // Structured (Elementor/Thrive JSON, or a serialized array) is counted on the decoded
                // text; a plain string keeps the literal count.
                if ($shape['kind'] === 'string') {
                    $c = substr_count($shape['data'], $p['from']);
                    $snips = localeyes_nap_snippets($shape['data'], $p['from']);
                } else {
                    $c = localeyes_nap_deep_count($shape['data'], $p['from']);
                    $snips = localeyes_nap_deep_snippets($shape['data'], $p['from']);
                }
                if ($c > 0) {
                    $h = localeyes_nap_hit($row->post_id, $row->post_title, $row->post_type, $row->meta_key, $p, $c, $builderOf($row->post_id));
                    $h['snippets'] = $snips;
                    $hits[] = $h;
                }
            }
        }

        // Site-wide options (skipped when scoped to specific posts): widgets/theme_mods + SEO-plugin schema settings.
        if (empty($post_ids) && ($wantWidgets || $wantSchemaOpt)) {
            $groups = [];
            if ($wantWidgets)   $groups[] = ['pre' => ['widget_', 'theme_mods_'],      'category' => 'widgets', 'token' => 'widgets'];
            if ($wantSchemaOpt) $groups[] = ['pre' => ['rank_math', 'rank-math', 'wpseo'], 'category' => 'schema', 'token' => 'schema_opts'];
            foreach ($groups as $g) {
                $likes = array_map(function ($pre) use ($wpdb) { return $wpdb->esc_like($pre) . '%'; }, $g['pre']);
                $ph = implode(' OR ', array_fill(0, count($likes), 'option_name LIKE %s'));
                $args = $likes; $args[] = $like;
                $orows = $wpdb->get_results($wpdb->prepare(
                    "SELECT option_name FROM {$wpdb->options} WHERE (" . $ph . ") AND option_value LIKE %s", $args));
                foreach ($orows as $orow) {
                    $val = get_option($orow->option_name);
                    $c = localeyes_nap_deep_count($val, $p['from']);
                    if ($c > 0) $hits[] = [
                        'post_id' => 0, 'title' => $orow->option_name, 'type' => 'option',
                        'field' => 'option:' . $orow->option_name, 'category' => $g['category'], 'token' => $g['token'], 'builder' => '—',
                        'from' => $p['from'], 'to' => $p['to'], 'count' => (int) $c, 'url' => '',
                        'snippets' => localeyes_nap_deep_snippets($val, $p['from']),
                    ];
                }
            }
        }
    }

    // Restrict to requested routing tokens (belt-and-suspenders; the gates above already skip most).
    if (!empty($field_types)) {
        $hits = array_values(array_filter($hits, function ($h) use ($field_types) {
            return in_array($h['token'], $field_types, true);
        }));
    }
    return $hits;
}

function localeyes_nap_hit($pid, $title, $type, $field, $p, $count, $builder = '', $category = null) {
    $cat = ($category !== null) ? $category : localeyes_nap_category($field);
    return [
        'post_id' => (int) $pid, 'title' => (string) $title, 'type' => (string) $type,
        'field' => $field, 'category' => $cat, 'token' => $cat, 'builder' => (string) $builder,
        'from' => $p['from'], 'to' => $p['to'], 'count' => (int) $count,
        // 'exact' = literal, replaceable; the scan sets 'split' for tag-tolerant detection-only hits.
        'match' => 'exact',
        'url' => (string) get_permalink((int) $pid),
    ];
}

function localeyes_nap_preview($req) {
    list($pairs, $post_ids, $field_types) = localeyes_nap_input($req);
    if (empty($pairs)) return new WP_REST_Response(['ok' => false, 'error' => 'no replacements'], 400);
    $hits = localeyes_nap_scan($pairs, $post_ids, $field_types);
    return ['ok' => true, 'dry_run' => true, 'pairs' => $pairs, 'hit_count' => count($hits), 'hits' => $hits];
}

function localeyes_nap_apply($req) {
    list($pairs, $post_ids, $field_types) = localeyes_nap_input($req);
    // Never replace with an empty string — apply only pairs that have a replacement value.
    $pairs = array_values(array_filter($pairs, function ($p) { return isset($p['to']) && $p['to'] !== ''; }));
    if (empty($pairs)) return new WP_REST_Response(['ok' => false, 'error' => 'each replacement needs a "replace with" value'], 400);
    $hits = localeyes_nap_scan($pairs, $post_ids, $field_types);

    // 'split' hits are tag-tolerant DETECTION only — the literal string is not present, so they are
    // never written (a cross-tag rewrite could corrupt the markup). Exclude them from the apply set.
    $byKey = [];
    foreach ($hits as $h) {
        if (isset($h['match']) && $h['match'] === 'split') { continue; }
        $byKey[$h['post_id'] . '|' . $h['field']] = true;
    }

    $backup = []; $applied = 0; $changedPosts = []; $changedOpts = []; $skipped_kses = 0;
    foreach (array_keys($byKey) as $key) {
        list($pid, $field) = explode('|', $key, 2);

        if (strpos($field, 'option:') === 0) {
            $name = substr($field, 7);
            $old = get_option($name);
            $changed = false;
            $new = localeyes_nap_deep_replace($old, $pairs, $changed);
            if ($changed) {
                $backup[] = ['option' => $name, 'old_serialized' => maybe_serialize($old)];
                update_option($name, $new);
                $applied++; $changedOpts[$name] = true;
            }
            continue;
        }

        $pid = (int) $pid;
        if ($field === 'post_content') {
            // wp_update_post() runs post_content through wp_kses_post for any user WITHOUT unfiltered_html.
            // On multisite a site-admin has manage_options (so reaches this route) but not unfiltered_html,
            // so the replace would silently strip <script>/<iframe>/<style> and custom attributes out of
            // the page body - mangling classic/bakery pages. Editing raw page HTML is exactly what that
            // capability governs, so skip the write and report it rather than quietly corrupting the page.
            // Elementor/meta/option edits below do not pass through KSES and still apply.
            if (!current_user_can('unfiltered_html')) { $skipped_kses++; continue; }
            // Raw stored bytes (the WP_Post field), not the display-filtered copy, so the count that
            // matched in the scan and the string written here are the same text.
            $post = get_post($pid);
            $old = $post ? (string) $post->post_content : '';
            $new = $old; foreach ($pairs as $p) $new = str_replace($p['from'], $p['to'], $new);
            // Also replace inside WPBakery Raw HTML/JS blocks (base64(rawurlencode())).
            $rawChanged = false;
            $new = localeyes_nap_wpb_raw_replace($new, $pairs, $rawChanged);
            if ($new !== $old) {
                $backup[] = ['post_id' => $pid, 'field' => 'post_content', 'old' => $old];
                wp_update_post(['ID' => $pid, 'post_content' => wp_slash($new)]);
                $applied++; $changedPosts[$pid] = true;
            }
        } else {
            $old = get_post_meta($pid, $field, true);
            $shape = localeyes_nap_meta_shape($old);
            if ($shape['kind'] === 'string') {
                $new = $shape['data']; foreach ($pairs as $p) $new = str_replace($p['from'], $p['to'], $new);
                if ($new !== $shape['data']) {
                    $backup[] = ['post_id' => $pid, 'field' => $field, 'old' => $old];
                    update_post_meta($pid, $field, wp_slash($new));
                    $applied++; $changedPosts[$pid] = true;
                }
            } else {
                $changed = false;
                $newData = localeyes_nap_deep_replace($shape['data'], $pairs, $changed);
                if ($changed) {
                    // JSON string (Elementor/Thrive): re-encode so the stored bytes are valid JSON whatever
                    // the replacement contained - this is what stops a quote in the replacement from
                    // corrupting the layout. Serialized array: hand WP the array and let it re-serialize.
                    $newVal = ($shape['kind'] === 'json') ? wp_json_encode($newData) : $newData;
                    $backup[] = ['post_id' => $pid, 'field' => $field, 'old' => $old];
                    update_post_meta($pid, $field, wp_slash($newVal));
                    $applied++; $changedPosts[$pid] = true;
                }
            }
        }
    }

    // Only a batch that actually wrote something gets an id. It used to be generated unconditionally
    // and returned even when $backup was empty, so a no-op apply handed the UI a backup_id that
    // matched no stored backup, and rolling back with it answered "backup not found" (404).
    $backup_id = '';
    if (!empty($backup)) {
        $backup_id = 'nap_' . gmdate('Ymd_His') . '_' . wp_generate_password(6, false, false);
        update_option('localeyes_nap_backup_' . $backup_id, wp_json_encode($backup), false);
        $index = get_option('localeyes_nap_backups', []);
        if (!is_array($index)) $index = [];
        $index[] = ['id' => $backup_id, 'at' => gmdate('c'), 'changes' => count($backup)];
        update_option('localeyes_nap_backups', $index, false);
    }

    localeyes_nap_flush(array_keys($changedPosts));
    return ['ok' => true, 'applied' => $applied, 'posts_changed' => count($changedPosts),
            'options_changed' => count($changedOpts), 'backup_id' => $backup_id,
            // Page bodies left untouched because this user lacks unfiltered_html (multisite site-admins).
            'skipped_kses' => $skipped_kses];
}

function localeyes_nap_rollback($req) {
    $bid = (string) $req->get_param('backup_id');
    if ($bid === '') return new WP_REST_Response(['ok' => false, 'error' => 'backup_id required'], 400);
    $raw = get_option('localeyes_nap_backup_' . $bid);
    if (!$raw) return new WP_REST_Response(['ok' => false, 'error' => 'backup not found'], 404);
    $backup = json_decode($raw, true);
    if (!is_array($backup)) return new WP_REST_Response(['ok' => false, 'error' => 'corrupt backup'], 500);
    $restored = 0; $changedPosts = [];
    foreach ($backup as $b) {
        if (isset($b['option'])) {
            update_option($b['option'], maybe_unserialize($b['old_serialized']));
            $restored++;
            continue;
        }
        $pid = (int) $b['post_id'];
        if ($b['field'] === 'post_content') { wp_update_post(['ID' => $pid, 'post_content' => wp_slash($b['old'])]); }
        else { update_post_meta($pid, $b['field'], wp_slash($b['old'])); }
        $restored++; $changedPosts[$pid] = true;
    }
    localeyes_nap_flush(array_keys($changedPosts));
    return ['ok' => true, 'restored' => $restored, 'posts_changed' => count($changedPosts)];
}

/** Clear per-post Elementor CSS + global Elementor cache + object cache + common page caches so
 *  edits render. CDN/Cloudflare is external and must be purged separately (noted in the admin UI). */
function localeyes_nap_flush($post_ids) {
    foreach ($post_ids as $pid) { delete_post_meta((int) $pid, '_elementor_css'); }
    if (class_exists('\Elementor\Plugin')) {
        try {
            $inst = \Elementor\Plugin::$instance;
            if (isset($inst->files_manager)) { $inst->files_manager->clear_cache(); }
        } catch (\Throwable $e) { /* best-effort */ }
    }
    if (function_exists('w3tc_flush_all'))        { try { w3tc_flush_all(); } catch (\Throwable $e) {} }              // W3 Total Cache
    if (function_exists('wp_cache_clear_cache'))  { try { wp_cache_clear_cache(); } catch (\Throwable $e) {} }        // WP Super Cache
    if (function_exists('rocket_clean_domain'))   { try { rocket_clean_domain(); } catch (\Throwable $e) {} }          // WP Rocket
    if (function_exists('wpfc_clear_all_cache'))  { try { wpfc_clear_all_cache(true); } catch (\Throwable $e) {} }     // WP Fastest Cache
    do_action('litespeed_purge_all');                                                                                 // LiteSpeed Cache
    if (function_exists('wp_cache_flush'))        { wp_cache_flush(); }                                                // object cache
    if (function_exists('le_page_cache_clear'))   { le_page_cache_clear(''); }                                         // LocalEyes native page cache
}

/* ============================ Admin UI ============================ */

// Menu registration moved to includes/admin-ui.php (single "LocalEyes" menu with submenus). The
// Search & Replace screen (localeyes_nap_admin_page, below) is registered there as a submenu. This
// legacy top-level menu only runs if the v2 bootstrap did not load, so there is never a duplicate.
//
// The guard used to be !defined('LOCALEYES_VER'), which is defined unconditionally at the top of THIS
// file, so the branch was dead and the safety net it described did not exist: if includes/admin-ui.php
// ever failed to load there would have been no menu at all. Checking for the function admin-ui.php
// defines, at the time the menu is built, actually answers "did the v2 bootstrap load".
add_action('admin_menu', function () {
    if (function_exists('le_admin_menu')) { return; }
    add_menu_page('LocalEyes Plugin', 'LocalEyes Plugin', 'manage_options', 'localeyes-nap', 'localeyes_nap_admin_page', 'dashicons-location-alt', 58);
}, 5);

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $open = '<a href="' . esc_url(admin_url('admin.php?page=localeyes-nap')) . '">' . esc_html__('Open') . '</a>';
    array_unshift($links, $open);
    return $links;
});

/**
 * Saved find/replace pairs (editable in the UI). Defaults are intentionally EMPTY: v2.0 operates on
 * the current live content, and the old hardcoded Austin/Chicago/Dallas pairs were removed. The admin
 * screen loads and saves the `localeyes_pairs` option instead, so "Load defaults" restores whatever
 * the team last saved rather than any baked-in address.
 *
 * That last sentence was untrue until the /nap/pairs routes above existed: this function read an
 * option nothing ever wrote.
 */
function localeyes_nap_default_pairs() {
    $saved = get_option('localeyes_pairs', array());
    return is_array($saved) ? $saved : array();
}

function localeyes_nap_admin_page() {
    // manage_options: this screen runs a site-wide search-and-replace across post content and
    // options, and its REST routes require the same.
    if (!current_user_can('manage_options')) { wp_die('Insufficient permissions.'); }
    $data = [
        'rest'     => esc_url_raw(rest_url('localeyes/v1/')),
        'nonce'    => wp_create_nonce('wp_rest'),
        'defaults' => localeyes_nap_default_pairs(),
    ];
    ?>
    <div class="wrap le-wrap">
        <h1><span class="dashicons dashicons-location-alt" style="font-size:26px;height:26px;width:26px"></span> LocalEyes <span class="le-sub">Search &amp; Replace</span></h1>
        <?php
        // The shared tab bar, so this screen is not a dead end. Landing here used to remove every
        // LocalEyes navigation link from the page, leaving the WordPress sidebar as the only way back.
        if (function_exists('le_admin_tabs')) { echo le_admin_tabs('nap'); }
        ?>
        <div id="le-status" class="notice notice-info inline" style="padding:8px 12px;margin:8px 0"></div>

        <h2 class="nav-tab-wrapper" id="le-tabs">
            <a href="#" class="nav-tab nav-tab-active" data-tab="1">1 &middot; Wrong NAP</a>
            <a href="#" class="nav-tab" data-tab="2">2 &middot; Pages &amp; schema found</a>
            <a href="#" class="nav-tab" data-tab="3">3 &middot; Fix to apply</a>
            <a href="#" class="nav-tab" data-tab="4">4 &middot; Updated pages</a>
        </h2>
        <div id="le-msg" style="margin:10px 0"></div>

        <div class="le-panel" data-panel="1">
            <p>Enter the wrong text to find — address, ZIP, phone, anything. You'll set the replacements in step 3.</p>
            <table class="widefat striped" style="max-width:900px">
                <thead><tr><th style="width:40px">Use</th><th>Wrong text (find)</th><th style="width:40px"></th></tr></thead>
                <tbody id="le-rows"></tbody>
            </table>
            <p>
                <button class="button" id="le-add">+ Add row</button>
                <button class="button" id="le-defaults">Load saved</button>
                <button class="button" id="le-save">Save these</button>
                <button class="button" id="le-clear">Delete saved</button>
                <button class="button button-primary button-hero" id="le-scan">Start scan &rarr;</button>
                <span id="le-save-st" style="margin-left:8px;color:#555"></span>
            </p>
            <p class="description">Saved rows are reloaded the next time you open this screen, and shared with anyone else who can use this tool.</p>
        </div>

        <div class="le-panel" data-panel="2" style="display:none">
            <div id="le-progress" style="display:none;margin:8px 0;max-width:1000px">
                <progress id="le-prog" value="0" max="100" style="width:100%"></progress>
                <div id="le-prog-text" style="font-size:12px;color:#555"></div>
            </div>
            <div id="le-results" style="margin-top:10px"></div>
            <p><button class="button button-primary" id="le-to-fix">Next: Fix to apply &rarr;</button></p>
        </div>

        <div class="le-panel" data-panel="3" style="display:none">
            <p>Enter the replacement for each wrong value, then apply. Scope comes from the pages &amp; content types you ticked in step 2.</p>
            <table class="widefat striped" style="max-width:900px">
                <thead><tr><th>Wrong text</th><th>Replace with</th></tr></thead>
                <tbody id="le-fix-rows"></tbody>
            </table>
            <div id="le-apply-progress" style="display:none;margin:8px 0;max-width:1000px">
                <progress id="le-prog2" value="0" max="100" style="width:100%"></progress>
                <div id="le-prog2-text" style="font-size:12px;color:#555"></div>
            </div>
            <p>
                <button class="button button-primary" id="le-apply-sel">Apply to selected pages</button>
                <button class="button" id="le-apply-all">Apply to all matches</button>
            </p>
        </div>

        <div class="le-panel" data-panel="4" style="display:none">
            <div id="le-updated"><p class="description">Applied changes will appear here, with links to check each page.</p></div>
            <h2 style="margin-top:16px">Backups</h2>
            <div id="le-backups">Loading…</div>
            <p class="description">Elementor + object + common page caches are flushed on apply. If Cloudflare/another CDN fronts the site, purge it too, then hard-refresh.</p>
        </div>
    </div>
    <script>window.LE_NAP = <?php echo wp_json_encode($data); ?>;</script>
    <?php
    echo localeyes_nap_admin_js();
}

/** The admin-screen JavaScript (NOWDOC — no PHP interpolation; reads config from window.LE_NAP). */
function localeyes_nap_admin_js() {
    return <<<'HTML'
<script>
(function(){
  var D = window.LE_NAP || {}; var REST = D.rest, NONCE = D.nonce;
  var CATS = ['classic','bakery','thrive','elementor','schema']; // per-page routing tokens
  var CATLABEL = { classic:'Classic', bakery:'Bakery', thrive:'Thrive', elementor:'Elementor', schema:'Schema', widgets:'Widgets' };
  var byId = function(id){ return document.getElementById(id); };
  var rows = [];
  var lastPages = [], lastOpts = [];

  function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
  function api(path, method, body){
    return fetch(REST + path, {
      method: method || 'GET',
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' },
      body: body ? JSON.stringify(body) : undefined
    }).then(function(r){ return r.json().then(function(j){ return { status: r.status, body: j }; }).catch(function(){ return { status: r.status, body: {} }; }); });
  }
  function msg(text, kind){ byId('le-msg').innerHTML = text ? '<div class="notice notice-' + (kind||'info') + ' inline" style="padding:8px 12px"><p>' + text + '</p></div>' : ''; }

  var targets = [], scanHits = [], fixMap = {};

  // ---------- tabs ----------
  function showTab(n){
    n = String(n);
    document.querySelectorAll('.le-panel').forEach(function(p){ p.style.display = (p.getAttribute('data-panel')===n) ? 'block' : 'none'; });
    document.querySelectorAll('#le-tabs .nav-tab').forEach(function(t){ t.classList.toggle('nav-tab-active', t.getAttribute('data-tab')===n); });
    if (n === '3') buildFixRows();
  }
  document.querySelectorAll('#le-tabs .nav-tab').forEach(function(t){
    t.addEventListener('click', function(e){ e.preventDefault(); showTab(t.getAttribute('data-tab')); });
  });

  // ---------- wrong-text rows (find only) ----------
  function renderRows(){
    var tb = byId('le-rows'); tb.innerHTML = '';
    rows.forEach(function(row, i){
      var tr = document.createElement('tr');
      tr.innerHTML =
        '<td style="text-align:center"><input type="checkbox" class="le-inc" data-i="'+i+'"'+(row.include?' checked':'')+'></td>' +
        '<td><input type="text" class="le-from" data-i="'+i+'" style="width:100%" value="'+esc(row.from)+'"></td>' +
        '<td style="text-align:center"><a href="#" class="le-del" data-i="'+i+'" title="remove">&times;</a></td>';
      if (row.shared) tr.style.background = '#fff8e5';
      tb.appendChild(tr);
    });
  }
  // The saved set, or a single empty row.
  //
  // Without the fallback this rendered an empty table on every fresh install, because the option it
  // reads was never written by anything: the screen opened on a headerless grid with no way to type
  // and no indication that "+ Add row" was the next step.
  function loadDefaults(){
    rows = (D.defaults || []).map(function(p){ return { from: p.from, to: p.to || '', shared: !!p.shared, include: !p.shared }; });
    if (!rows.length) { rows = [{ from: '', to: '', shared: false, include: true }]; }
    renderRows();
    // Replacements come from the saved set too, so a saved pair arrives with its "replace with" value
    // already filled in on step 3.
    rows.forEach(function(r){ if (r.from && r.to) { fixMap[r.from] = r.to; } });
  }

  function saveStatus(text, bad){
    var el = byId('le-save-st');
    if (el) { el.textContent = text || ''; el.style.color = bad ? '#b32d2e' : '#555'; }
  }

  function savePairs(){
    // Every row with something to find, including ones not ticked for this scan: the checkbox chooses
    // what to scan now, not what is worth keeping.
    var payload = rows.filter(function(r){ return r.from; })
      .map(function(r){ return { from: r.from, to: fixMap[r.from] || r.to || '' }; });
    saveStatus('Saving...');
    api('nap/pairs', 'POST', { pairs: payload }).then(function(r){
      if (r.status >= 400 || !(r.body && r.body.ok)) { saveStatus((r.body && r.body.error) || 'Could not save.', true); return; }
      D.defaults = r.body.pairs || [];
      saveStatus('Saved ' + (r.body.saved || 0) + ' row(s).');
    }).catch(function(e){ saveStatus(e.message, true); });
  }

  function clearPairs(){
    if (!confirm('Delete the saved rows? The table on screen is left as it is.')) return;
    saveStatus('Deleting...');
    api('nap/pairs', 'POST', { pairs: [] }).then(function(r){
      if (r.status >= 400 || !(r.body && r.body.ok)) { saveStatus((r.body && r.body.error) || 'Could not delete.', true); return; }
      D.defaults = [];
      saveStatus('Saved rows deleted.');
    }).catch(function(e){ saveStatus(e.message, true); });
  }
  function chosenForScan(){
    return rows.filter(function(r){ return r.include && r.from; })
      .map(function(r){ return { from: r.from, to: '' }; })
      .sort(function(a,b){ return b.from.length - a.from.length; });
  }
  byId('le-rows').addEventListener('input', function(e){
    var i = e.target.getAttribute('data-i'); if (i === null) return; i = +i;
    if (e.target.classList.contains('le-from')) rows[i].from = e.target.value;
  });
  byId('le-rows').addEventListener('change', function(e){
    var i = e.target.getAttribute('data-i'); if (i === null) return; i = +i;
    if (e.target.classList.contains('le-inc')) rows[i].include = e.target.checked;
  });
  byId('le-rows').addEventListener('click', function(e){
    if (!e.target.classList.contains('le-del')) return;
    e.preventDefault(); var i = +e.target.getAttribute('data-i'); rows.splice(i,1); renderRows();
  });
  byId('le-add').addEventListener('click', function(e){ e.preventDefault(); rows.push({from:'',to:'',shared:false,include:true}); renderRows(); });
  byId('le-defaults').addEventListener('click', function(e){ e.preventDefault(); loadDefaults(); saveStatus(''); });
  byId('le-save').addEventListener('click', function(e){ e.preventDefault(); savePairs(); });
  byId('le-clear').addEventListener('click', function(e){ e.preventDefault(); clearPairs(); });

  // ---------- results ----------
  function badge(b){
    var col = { elementor:'#92003b', thrive:'#1f7a3d', bakery:'#0073aa', classic:'#666' }[b] || '#666';
    return '<span style="display:inline-block;font-size:10px;padding:1px 6px;border-radius:8px;color:#fff;background:'+col+'">'+esc(b||'—')+'</span>';
  }

  // highlight the matched text inside an escaped snippet
  function hl(snippet, from){ var e = esc(snippet); var f = esc(from); return f ? e.split(f).join('<mark>'+f+'</mark>') : e; }
  function mentionsHtml(cats){
    // cats: { category: [ {from, s}, ... ] }
    var out = '';
    CATS.concat(['widgets']).forEach(function(cat){
      var list = cats[cat]; if (!list || !list.length) return;
      out += '<div style="margin:4px 0"><strong>'+CATLABEL[cat]+'</strong> ('+list.length+')<ul style="margin:2px 0 6px 16px">';
      list.slice(0,50).forEach(function(m){ out += '<li style="font-family:monospace;font-size:11px;color:#333">'+hl(m.s, m.from)+'</li>'; });
      if (list.length > 50) out += '<li>… +'+(list.length-50)+' more</li>';
      out += '</ul></div>';
    });
    return out;
  }

  function snippetList(snips){
    if (!snips || !snips.length) return '';
    var out = '<ul style="margin:2px 0 6px 16px">';
    snips.slice(0,50).forEach(function(m){ out += '<li style="font-family:monospace;font-size:11px;color:#333">'+hl(m.s, m.from)+'</li>'; });
    if (snips.length > 50) out += '<li>… +'+(snips.length-50)+' more</li>';
    return out + '</ul>';
  }
  var TOKLABEL = { widgets:'Widgets / theme options', schema_opts:'Schema settings (Rank Math / Yoast)' };

  function renderResults(hits){
    var pagesMap = {}, opts = {}, splitMap = {};
    (hits||[]).forEach(function(h){
      var snips = (h.snippets||[]).map(function(s){ return { from: h.from, s: s }; });
      if (h.match === 'split') {
        var sp = splitMap[h.post_id] || (splitMap[h.post_id] = { post_id: h.post_id, title: h.title, url: h.url, builder: h.builder, snips: [] });
        sp.snips = sp.snips.concat(snips);
        return;
      }
      if (h.type === 'option') {
        var tok = h.token || 'widgets';
        if (!opts[tok]) opts[tok] = { token: tok, items: [] };
        opts[tok].items.push({ title: h.title, count: h.count, snips: snips });
        return;
      }
      var pid = h.post_id;
      if (!pagesMap[pid]) pagesMap[pid] = { post_id: pid, title: h.title, url: h.url, builder: h.builder, cats: {}, ment: {} };
      pagesMap[pid].cats[h.category] = (pagesMap[pid].cats[h.category]||0) + h.count;
      pagesMap[pid].ment[h.category] = (pagesMap[pid].ment[h.category]||[]).concat(snips);
    });
    lastPages = Object.keys(pagesMap).map(function(k){ return pagesMap[k]; });
    lastOpts = Object.keys(opts).map(function(k){ return opts[k]; });
    var splitPages = Object.keys(splitMap).map(function(k){ return splitMap[k]; });
    var totalHits = (hits||[]).reduce(function(n,h){return n+(h.match==='split'?0:h.count);},0);
    var catsPresent = {}; lastPages.forEach(function(p){ Object.keys(p.cats).forEach(function(c){ catsPresent[c]=true; }); });

    var html = '<div style="border:1px solid #ccd0d4;background:#fff;padding:10px;max-width:1000px">';
    html += '<p><strong>'+totalHits+'</strong> mention(s) across <strong>'+lastPages.length+'</strong> page(s)'+(lastOpts.length?' + site-wide settings':'')+'.</p>';
    if (!lastPages.length && !lastOpts.length) html += '<p>No occurrences found.</p>';
    if (lastPages.length || lastOpts.length){
      html += '<div style="margin:6px 0;padding:6px 8px;background:#f6f7f7;border:1px solid #e2e4e7">';
      html += '<label style="font-weight:600"><input type="checkbox" id="le-all" checked> Select everything</label>';
      if (lastPages.length){
        html += ' &nbsp;|&nbsp; Pages: <a href="#" id="le-sel-all">all</a> · <a href="#" id="le-sel-none">clear</a>';
        html += ' &nbsp;|&nbsp; Type: ';
        CATS.forEach(function(c){ if (catsPresent[c]) html += '<label style="margin-right:8px"><input type="checkbox" class="le-cat-all" data-cat="'+c+'" checked> '+CATLABEL[c]+'</label>'; });
      }
      html += '</div>';
    }
    if (lastPages.length){
      html += '<table class="widefat striped"><thead><tr><th style="width:30px"></th><th>Page</th><th>Builder</th><th>Change</th><th>Mentions</th></tr></thead><tbody>';
      lastPages.forEach(function(p){
        var link = p.url ? '<a href="'+esc(p.url)+'" target="_blank" rel="noopener">'+esc(p.title||p.post_id)+'</a> ↗' : esc(p.title||String(p.post_id));
        var catboxes = CATS.filter(function(c){ return p.cats[c]; }).map(function(c){
          return '<label style="margin-right:10px;white-space:nowrap"><input type="checkbox" class="le-cat" data-id="'+p.post_id+'" data-cat="'+c+'" checked> '+CATLABEL[c]+' ('+p.cats[c]+')</label>';
        }).join('');
        html += '<tr><td style="text-align:center"><input type="checkbox" class="le-page" value="'+p.post_id+'" checked></td>'
             + '<td>'+link+'</td><td>'+badge(p.builder)+'</td><td>'+catboxes+'</td>'
             + '<td><a href="#" class="le-toggle" data-id="'+p.post_id+'">show mentions</a><div class="le-ment" id="ment-'+p.post_id+'" style="display:none;margin-top:4px">'+mentionsHtml(p.ment)+'</div></td></tr>';
      });
      html += '</tbody></table>';
    }
    if (lastOpts.length){
      html += '<p style="margin-top:8px"><strong>Site-wide settings</strong> — applied only with “Apply to all matches”:</p>';
      lastOpts.forEach(function(g){
        html += '<div style="margin-left:8px"><label><input type="checkbox" class="le-site" data-token="'+g.token+'" checked> <strong>'+(TOKLABEL[g.token]||g.token)+'</strong> ('+g.items.length+' setting(s))</label>';
        g.items.forEach(function(o){ html += snippetList(o.snips); });
        html += '</div>';
      });
    }
    if (splitPages.length){
      html += '<div style="margin-top:10px;padding:8px;background:#fff8e5;border:1px solid #f0c33c">';
      html += '<p style="margin:0 0 4px"><strong>'+splitPages.length+' page(s)</strong> contain this text but split across HTML tags (e.g. an address broken over several lines) or in a different case/format. Shown for reference — <strong>not auto-replaced</strong>. Replace these by a shorter contiguous fragment (e.g. just the street, or the city + ZIP).</p>';
      splitPages.forEach(function(p){
        var link = p.url ? '<a href="'+esc(p.url)+'" target="_blank" rel="noopener">'+esc(p.title||p.post_id)+'</a> ↗' : esc(p.title||String(p.post_id));
        html += '<div style="margin:4px 0">'+link+' '+badge(p.builder)+snippetList(p.snips)+'</div>';
      });
      html += '</div>';
    }
    html += '</div>';
    byId('le-results').innerHTML = html;
    var a = byId('le-sel-all'), n = byId('le-sel-none'), all = byId('le-all');
    if (a) a.addEventListener('click', function(e){ e.preventDefault(); document.querySelectorAll('.le-page').forEach(function(c){c.checked=true;}); });
    if (n) n.addEventListener('click', function(e){ e.preventDefault(); document.querySelectorAll('.le-page').forEach(function(c){c.checked=false;}); });
    if (all) all.addEventListener('change', function(){ var on=all.checked; document.querySelectorAll('.le-page,.le-cat,.le-cat-all,.le-site').forEach(function(c){c.checked=on;}); });
    document.querySelectorAll('.le-cat-all').forEach(function(t){ t.addEventListener('change', function(){ document.querySelectorAll('.le-cat[data-cat="'+t.getAttribute('data-cat')+'"]').forEach(function(c){c.checked=t.checked;}); }); });
    document.querySelectorAll('.le-toggle').forEach(function(t){
      t.addEventListener('click', function(e){ e.preventDefault(); var d = byId('ment-'+t.getAttribute('data-id')); var on = d.style.display==='none'; d.style.display = on?'block':'none'; t.textContent = on?'hide mentions':'show mentions'; });
    });
  }

  // ---------- Tab 2: real-time scan ----------
  function chunk(arr, n){ var out=[]; for (var i=0;i<arr.length;i+=n) out.push(arr.slice(i,i+n)); return out; }
  function scanProgress(done, total, label){
    byId('le-progress').style.display = 'block';
    var pr = byId('le-prog'); pr.max = total; pr.value = done;
    byId('le-prog-text').textContent = label || (done + ' / ' + total);
  }
  function doScan(){
    var pairs = chosenForScan();
    if (!pairs.length){ msg('Enter at least one wrong text to find in step 1.', 'warning'); return; }
    scanHits = []; lastPages = []; lastOpts = []; byId('le-results').innerHTML = ''; msg('');
    showTab(2); scanProgress(0, 1, 'Loading pages…');
    api('targets', 'GET').then(function(r){
      var t = (r.body && r.body.targets) || []; targets = t;
      var total = t.length; var titleById = {}; t.forEach(function(x){ titleById[x.id] = x.title; });
      var batches = chunk(t.map(function(x){ return x.id; }), 25); var done = 0;
      var run = Promise.resolve();
      batches.forEach(function(bt){
        run = run.then(function(){
          scanProgress(done, total + 1, 'Searching… ' + done + ' / ' + total + (bt.length ? ('  ·  e.g. ' + (titleById[bt[0]] || bt[0])) : ''));
          return api('nap/preview', 'POST', { replacements: pairs, post_ids: bt }).then(function(pr){
            if (pr.body && pr.body.hits && pr.body.hits.length){ scanHits = scanHits.concat(pr.body.hits); renderResults(scanHits); }
            done += bt.length; scanProgress(done, total + 1, 'Searching… ' + done + ' / ' + total);
          });
        });
      });
      run = run.then(function(){
        scanProgress(total, total + 1, 'Searching site-wide settings (widgets, schema)…');
        return api('nap/preview', 'POST', { replacements: pairs, field_types: ['widgets','schema_opts'] }).then(function(pr){
          if (pr.body && pr.body.hits && pr.body.hits.length){ scanHits = scanHits.concat(pr.body.hits); renderResults(scanHits); }
          done = total + 1;
          var totalHits = scanHits.reduce(function(n,h){ return n + h.count; }, 0);
          scanProgress(done, total + 1, 'Done — ' + totalHits + ' mention(s) across ' + lastPages.length + ' page(s).');
          if (!scanHits.length) byId('le-results').innerHTML = '<p>No occurrences found for the entered text.</p>';
        });
      });
      run.catch(function(e){ msg('Scan error: ' + e.message, 'error'); });
    }).catch(function(e){ msg('Could not load pages: ' + e.message, 'error'); });
  }

  // ---------- Tab 3: replacements ----------
  function distinctFroms(){ var seen = {}, out = []; scanHits.forEach(function(h){ if (!seen[h.from]){ seen[h.from] = true; out.push(h.from); } }); return out; }
  function buildFixRows(){
    var froms = distinctFroms(); var tb = byId('le-fix-rows'); tb.innerHTML = '';
    if (!froms.length){ tb.innerHTML = '<tr><td colspan="2">Run a scan in step 1 first.</td></tr>'; return; }
    froms.forEach(function(f){
      var tr = document.createElement('tr');
      tr.innerHTML = '<td style="font-family:monospace;font-size:12px">'+esc(f)+'</td>'
        + '<td><input type="text" class="le-fix" data-from="'+esc(f)+'" style="width:100%" placeholder="replace with…" value="'+esc(fixMap[f]||'')+'"></td>';
      tb.appendChild(tr);
    });
  }
  byId('le-fix-rows').addEventListener('input', function(e){ if (e.target.classList.contains('le-fix')) fixMap[e.target.getAttribute('data-from')] = e.target.value; });

  // ---------- apply (per-category, chunked) → Tab 4 real-time ----------
  function pageHasCat(id, cat){ var el = document.querySelector('.le-cat[data-id="'+id+'"][data-cat="'+cat+'"]'); return el && el.checked; }

  function doApply(scope){
    var pairs = distinctFroms().filter(function(f){ return (fixMap[f]||'').length; }).map(function(f){ return { from: f, to: fixMap[f] }; })
      .sort(function(a,b){ return b.from.length - a.from.length; });
    if (!pairs.length){ showTab(3); msg('Enter a replacement for at least one wrong value in step 3.', 'warning'); return; }
    var urlById = {}; lastPages.forEach(function(p){ urlById[p.post_id] = { url: p.url, title: p.title }; });
    var targetPages = scope === 'selected'
      ? Array.prototype.slice.call(document.querySelectorAll('.le-page:checked')).map(function(c){ return +c.value; })
      : lastPages.map(function(p){ return p.post_id; });
    if (scope === 'selected' && !targetPages.length){ msg('Select at least one page in step 2.', 'warning'); showTab(2); return; }

    var buckets = {};
    CATS.forEach(function(cat){ var ids = targetPages.filter(function(id){ return pageHasCat(id, cat); }); if (ids.length) buckets[cat] = ids; });
    // site-wide option groups (widgets, schema_opts) — only on "Apply to all", honoring their checkboxes
    var siteTokens = scope === 'all'
      ? Array.prototype.slice.call(document.querySelectorAll('.le-site:checked')).map(function(c){ return c.getAttribute('data-token'); })
      : [];
    var total = Object.keys(buckets).reduce(function(n,c){ return n + buckets[c].length; }, 0) + siteTokens.length;
    if (!total){ msg('Nothing selected to change — tick at least one content type in step 2.', 'warning'); showTab(2); return; }
    if (!confirm('Apply to ' + (scope==='selected' ? (targetPages.length+' page(s)') : 'ALL matches') + '? A backup is created per batch — you can roll back.')) return;

    showTab(4);
    var done = 0, applied = 0, skippedKses = 0, backups = [], updated = {};
    function render(label){
      var links = Object.keys(updated).map(function(id){ var u = urlById[id]; return u && u.url ? '<a href="'+esc(u.url)+'" target="_blank" rel="noopener">'+esc(u.title||id)+' ↗</a>' : null; }).filter(Boolean);
      byId('le-updated').innerHTML = '<progress value="'+done+'" max="'+total+'" style="width:100%;max-width:1000px"></progress>'
        + '<div style="font-size:12px;color:#555;margin-bottom:6px">'+esc(label)+'</div>'
        + (links.length ? ('<p><strong>Updated pages:</strong></p><p>' + links.join('<br>') + '</p>') : '');
    }
    render('Starting…');
    var run = Promise.resolve();
    Object.keys(buckets).forEach(function(cat){
      chunk(buckets[cat], 25).forEach(function(ids){
        run = run.then(function(){
          return api('nap/apply', 'POST', { replacements: pairs, post_ids: ids, field_types: [cat] }).then(function(r){
            if (r.body && r.body.ok){ applied += r.body.applied||0; skippedKses += r.body.skipped_kses||0; if (r.body.backup_id) backups.push(r.body.backup_id); ids.forEach(function(id){ updated[id] = true; }); }
            done += ids.length; render('Applying ' + CATLABEL[cat] + '… ' + done + ' / ' + total);
          });
        });
      });
    });
    siteTokens.forEach(function(tok){
      run = run.then(function(){
        return api('nap/apply', 'POST', { replacements: pairs, field_types: [tok] }).then(function(r){
          if (r.body && r.body.ok){ applied += r.body.applied||0; if (r.body.backup_id) backups.push(r.body.backup_id); }
          done += 1; render('Applying ' + (TOKLABEL[tok]||tok) + '… ' + done + ' / ' + total);
        });
      });
    });
    run.then(function(){
      return api('nap/preview', 'POST', { replacements: pairs }).then(function(v){
        var remaining = (v.body && typeof v.body.hit_count === 'number') ? v.body.hit_count : -1;
        done = total; render('Done. Applied ' + applied + ' change(s) in ' + backups.length + ' batch(es).');
        var verify = remaining === 0 ? ' Verified: 0 remaining.' : (remaining > 0 ? ' ⚠ ' + remaining + ' still found (unselected pages/types, or dynamic fields).' : '');
        var kses = skippedKses > 0 ? ' ⚠ ' + skippedKses + ' page body/bodies were left unchanged because your account lacks the unfiltered_html capability (common for multisite site admins); Elementor/schema/option edits still applied.' : '';
        msg('✓ Applied ' + applied + ' change(s) in ' + backups.length + ' batch(es).' + verify + kses, (skippedKses > 0 ? 'warning' : 'success'));
        loadBackups();
      });
    }).catch(function(e){ msg('Apply error: ' + e.message, 'error'); });
  }

  // ---------- backups ----------
  function loadBackups(){
    api('backups', 'GET').then(function(r){
      var list = (r.body && r.body.backups) || [];
      if (!list.length){ byId('le-backups').innerHTML = '<p>No backups yet.</p>'; return; }
      var html = '<table class="widefat striped" style="max-width:700px"><thead><tr><th>Backup</th><th>When</th><th>Changes</th><th></th></tr></thead><tbody>';
      list.slice(0,30).forEach(function(b){
        html += '<tr><td><code>'+esc(b.id)+'</code></td><td>'+esc(b.at)+'</td><td>'+esc(b.changes)+'</td><td><button class="button button-small le-rb" data-id="'+esc(b.id)+'">Roll back</button></td></tr>';
      });
      html += '</tbody></table>';
      byId('le-backups').innerHTML = html;
      document.querySelectorAll('.le-rb').forEach(function(btn){
        btn.addEventListener('click', function(){
          var id = btn.getAttribute('data-id');
          if (!confirm('Roll back ' + id + '? This restores the previous content.')) return;
          btn.disabled = true;
          api('nap/rollback', 'POST', { backup_id: id }).then(function(r){
            if (r.body && r.body.ok){ msg('Rolled back ' + r.body.restored + ' change(s).', 'success'); loadBackups(); }
            else { msg('Rollback failed: ' + ((r.body && r.body.error) || r.status), 'error'); btn.disabled = false; }
          });
        });
      });
    });
  }

  function loadStatus(){
    api('ping', 'GET').then(function(r){
      var b = r.body || {};
      if (b.ok){ byId('le-status').innerHTML = '✓ Plugin active (v' + esc(b.version) + ') · Elementor ' + (b.elementor?'✓':'—') + ' · Rank Math ' + (b.rankmath?'✓':'—') + ' · ' + esc(b.site); }
      else { byId('le-status').className = 'notice notice-error inline'; byId('le-status').innerHTML = '✗ ' + esc(b.error || 'plugin not responding'); }
    }).catch(function(e){ byId('le-status').className='notice notice-error inline'; byId('le-status').innerHTML = '✗ ' + esc(e.message); });
  }

  byId('le-scan').addEventListener('click', function(e){ e.preventDefault(); doScan(); });
  byId('le-to-fix').addEventListener('click', function(e){ e.preventDefault(); showTab(3); });
  byId('le-apply-sel').addEventListener('click', function(e){ e.preventDefault(); doApply('selected'); });
  byId('le-apply-all').addEventListener('click', function(e){ e.preventDefault(); doApply('all'); });

  loadDefaults(); loadStatus(); loadBackups(); showTab(1);
}());
</script>
HTML;
}

/* ============================ Uninstall cleanup ============================ */

/**
 * Every cron hook the plugin can schedule. Lives here rather than in a module because this file is
 * the only one guaranteed to be loaded in the activation, deactivation and uninstall contexts.
 */
function localeyes_cron_hooks() {
    return array(
        'localeyes_monitor_tick',     // includes/monitor.php
        'le_page_cache_expire',       // includes/perf/page-cache.php
        'le_page_cache_warm_cron',    // includes/perf/page-cache.php
        'localeyes_dbclean_weekly',   // includes/perf/dbclean.php
        'localeyes_rank_daily',       // includes/seo/analytics.php
        'le_aeo_scheduled_audit',     // includes/aeo/audit.php
        'le_aeo_scheduled_report',    // includes/aeo/audit.php
        'localeyes_batch',            // includes/queue.php (WP-Cron fallback when Action Scheduler is absent)
        'localeyes_jobs_reap',        // includes/jobs.php
        'localeyes_perf_rebuild',     // includes/perf/registry.php (deferred builds after activation)
        'localeyes_perf_cache_gc',    // includes/perf/lib.php (sweeps orphaned minify/ucss/font files)
    );
}

function localeyes_clear_all_crons() {
    /*
     * wp_unschedule_hook(), not wp_clear_scheduled_hook().
     *
     * Core keys scheduled events on md5(serialize($args)), and wp_clear_scheduled_hook($hook) with no
     * arguments only removes events whose signature is md5(serialize(array())). le_queue_schedule()
     * schedules with array($job_id, $tick), so not one queued batch was ever cleared: after
     * uninstalling, the cron option still listed localeyes_batch events for a plugin that no longer
     * existed. wp_unschedule_hook() removes every signature for the hook, which is what was meant.
     */
    foreach (localeyes_cron_hooks() as $hook) {
        if (function_exists('wp_unschedule_hook')) { wp_unschedule_hook($hook); }
        else { wp_clear_scheduled_hook($hook); }
    }
    // Action Scheduler keeps its own tables and does not self-limit the way WP-Cron does, so a queue
    // left there outlives the plugin indefinitely rather than firing once and being dropped.
    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions(null, null, 'localeyes');
    }
}

// On deactivation, take a final LocalEyes-changes snapshot so the user always has a backup before
// removing the plugin (Phase 7.11). Non-destructive; the snapshot survives in the snapshots table.
register_deactivation_hook(__FILE__, 'localeyes_deactivate_backup');
function localeyes_deactivate_backup() {
    if (function_exists('le_snapshot_create') && function_exists('le_export_state')) {
        le_snapshot_create('pre_deactivate', 'Auto snapshot on deactivation', le_export_state());
    }

    // Snapshot first (above), then remove everything written outside the plugin folder: the
    // advanced-cache.php drop-in, the WP_CACHE line in wp-config.php, and the .htaccess blocks. A
    // deactivated plugin must never keep serving cached pages or redirecting URLs. Stored settings
    // are left intact, so reactivating re-deploys whatever was enabled.
    if (function_exists('le_perf_teardown_all')) { le_perf_teardown_all(); }
    localeyes_clear_all_crons();
}

register_uninstall_hook(__FILE__, 'localeyes_nap_uninstall');

/**
 * Uninstall entry point, multisite-aware.
 *
 * Everything below used to run against the current blog only. On a network that meant the site the
 * administrator happened to be on was cleaned and every other subsite kept its whole option set and
 * its own wp_N_localeyes_* tables, with no plugin left to manage or remove them. Same shape as the
 * bug le_bootstrap_activate_router() exists to fix, and the loop here is deliberately that function's
 * loop: get_sites() with switch_to_blog()/restore_current_blog().
 *
 * No batching counterpart, unlike activation. Activation defers because activate_plugin() is a
 * budgeted request that leaves the plugin live and half-configured if it times out; a partial
 * uninstall has no such state to protect, there is nothing left to drain a queue from afterwards, and
 * the work per site is deletes rather than dbDelta plus filesystem writes.
 *
 * The split matters: per-site work touches options, meta, tables, crons and that site's uploads
 * directory. Shared work touches wp-config.php, the advanced-cache.php drop-in, the web-root
 * .htaccess and wp-content/plugins, all of which exist once per network and must be done once.
 */
function localeyes_nap_uninstall() {
    if (is_multisite() && function_exists('get_sites')) {
        $sites = array_map('intval', (array) get_sites(array('number' => 0, 'fields' => 'ids')));
        foreach ($sites as $site_id) {
            switch_to_blog($site_id);
            localeyes_uninstall_site();
            restore_current_blog();
        }
        // Lives in sitemeta, so the {$wpdb->options} sweep in the purge can never reach it.
        delete_site_option('localeyes_activate_pending');
    } else {
        localeyes_uninstall_site();
    }

    localeyes_uninstall_shared();
}

/** Everything that is per-blog: options, transients, meta, tables, crons, this site's uploads. */
function localeyes_uninstall_site() {
    global $wpdb;
    // Always remove the legacy NAP options.
    $like = $wpdb->esc_like('localeyes_nap_') . '%';
    $names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
    foreach ((array) $names as $n) { delete_option($n); }

    /*
     * Put back any web-root file the SEO conflict tool moved aside, BEFORE the purge below deletes
     * conflict_moved_files - the map that records where each one went. Without this the site's own
     * robots.txt stayed on disk as robots.txt.localeyes-disabled, with LocalEyes no longer serving a
     * replacement and nothing left that knew the two were related.
     *
     * Unconditional, not gated on the purge toggle, for the same reason as the teardown below: it is
     * not data worth preserving across a reinstall, it is a site left misconfigured.
     */
    if (function_exists('le_seo_conflict_restore_all')) { le_seo_conflict_restore_all(); }

    /*
     * Always remove what the plugin wrote OUTSIDE its own folder, whether or not the user opted into
     * a data purge. WordPress deletes only the plugin's own directory, so anything left
     * here is orphaned with no plugin remaining to manage it: a live advanced-cache.php would keep
     * serving stale HTML forever and a stale .htaccess block would keep 301-redirecting. That is not
     * data worth preserving across a reinstall, it is a site that misbehaves. Redirect definitions
     * live in the database, so a reinstall plus one .htaccess sync restores them.
     *
     * This is the state-driven pass, so it runs per site: it reads THIS site's stored module flags to
     * decide what to revert, and on a network each subsite has its own. localeyes_uninstall_shared()
     * runs the marker-driven pass once afterwards, and catches whatever the stored state missed.
     */
    if (function_exists('le_perf_teardown_all')) { le_perf_teardown_all(); }

    // Per site: the cron option is per-blog, so a network install has one schedule per subsite.
    localeyes_clear_all_crons();

    // Only purge the v2 data + tables if the user opted in (Configure toggle). Otherwise leave them so
    // data survives a reinstall. The final deactivation snapshot is the backup.
    if (!get_option('localeyes_purge_on_uninstall')) { return; }
    $opts = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('localeyes_') . '%'));
    foreach ((array) $opts as $n) { delete_option($n); }

    /*
     * Transients, which the sweep above cannot reach.
     *
     * The checkbox that arms this branch reads "Delete all LocalEyes data", and the sweep matches
     * option_name LIKE 'localeyes_%'. WordPress stores a transient as the option '_transient_<key>'
     * plus '_transient_timeout_<key>', so every one of them begins with an underscore and NONE of
     * them matched: localeyes_overview, localeyes_llms_body, localeyes_llms_full_body,
     * localeyes_aeo_tools_data, localeyes_image_audit and localeyes_upgrading all survived a purge
     * that reported success, as did every le_aeo_* rate-limit key (one option pair per client IP per
     * minute, so on a busy site that is the largest single residue of the lot).
     *
     * delete_option() by name rather than a bulk DELETE: it fires the option hooks and, on a site
     * with a persistent object cache, invalidates the cached copy. A raw DELETE leaves the value
     * being served from memory afterwards.
     */
    $le_tprefixes = array('_transient_localeyes_', '_transient_timeout_localeyes_',
                          '_transient_le_aeo_',    '_transient_timeout_le_aeo_');
    foreach ($le_tprefixes as $le_tp) {
        $le_trans = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like($le_tp) . '%'
        ));
        foreach ((array) $le_trans as $n) { delete_option($n); }
    }

    // Per-page performance panel meta (F4 + the per-post panel) and per-page schema blocks.
    if (function_exists('delete_post_meta_by_key')) {
        delete_post_meta_by_key('_localeyes_perf_exclude');
        delete_post_meta_by_key('_localeyes_perf_never_cache');
        delete_post_meta_by_key('_localeyes_perf_hero');
        delete_post_meta_by_key('_localeyes_perf_lazy_skip');
        delete_post_meta_by_key('_localeyes_perf_critical_css');
        delete_post_meta_by_key('_localeyes_aeo_exclude_llms');
        delete_post_meta_by_key('_localeyes_aeo_llms_pin');
        delete_post_meta_by_key('_localeyes_aeo_skip_fixes');
        delete_post_meta_by_key('_localeyes_aeo_skip_audit');
        delete_post_meta_by_key('_localeyes_aeo_summary');
        delete_post_meta_by_key('_localeyes_aeo_overrides');
        delete_post_meta_by_key('_localeyes_schema');
        // Two the list had fallen behind on: the per-page header/footer code box
        // (includes/replace/features/headfoot-meta.php) and the per-page table-of-contents override
        // (includes/seo/toc-meta.php). Both are written per post and were never cleaned, so a purged
        // site kept a _localeyes_ meta row on every page either feature had touched.
        delete_post_meta_by_key('_localeyes_headfoot');
        delete_post_meta_by_key('_localeyes_toc');
    }

    /*
     * User meta. le_setup_notice() writes this the moment an administrator dismisses the setup
     * notice, so on a multi-admin site there is one row per administrator, and nothing has ever
     * deleted any of them. $delete_all = true, hence the 0 user id and empty value.
     */
    if (function_exists('delete_metadata')) {
        delete_metadata('user', 0, 'localeyes_setup_notice_dismissed', '', true);
    }

    /*
     * Watermarked copies in this site's uploads directory (includes/seo/watermark.php). One PNG per
     * image per watermark setting, kept forever as a cache and never registered as an attachment, so
     * nothing in WordPress's own uninstall path knows about them. Per-site on multisite, which is why
     * this is here rather than in localeyes_uninstall_shared().
     */
    if (function_exists('le_perf_rrmdir') && function_exists('wp_get_upload_dir')) {
        $le_up = wp_get_upload_dir();
        if (!empty($le_up['basedir'])) {
            $le_wm = $le_up['basedir'] . '/localeyes-watermark';
            if (is_dir($le_wm)) {
                le_perf_rrmdir($le_wm);   // clears contents only
                @rmdir($le_wm);           // ...so drop the now-empty directory too
            }
        }
    }

    foreach (array('jobs','psi_results','snapshots','seo_meta','schema','schema_templates','redirects','page_inventory','parity','canonical','scan','agent','monitor_checks','monitor_incidents','log_404','indexing_log','rank_history') as $t) {
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}localeyes_{$t}");
    }
}

/**
 * Everything that exists once per WordPress install, however many sites are on it: the drop-in, the
 * WP_CACHE line, the web-root .htaccess, wp-content/cache, wp-content/mu-plugins and the plugins
 * directory itself. Runs once, after every site has been through localeyes_uninstall_site().
 */
function localeyes_uninstall_shared() {
    /*
     * Sweep the artifacts by their marker, not by stored state.
     *
     * le_perf_teardown_all() skips any module whose stored state is not enabled, and that guard is
     * correct - see its docblock in includes/perf/registry.php for the damage unconditional teardown
     * did. But it means the drop-in and the WP_CACHE line are removed only if the option that
     * remembers them is still intact and still says "enabled". If localeyes_perf was lost, reset or
     * left disabled while the artifacts were live, uninstall removed NEITHER, and the site went on
     * serving stale cached HTML from wp-content/cache with no plugin left to flush or remove it.
     *
     * So: one final pass, uninstall only, that asks the filesystem instead of the database. Every
     * call below is already marker-scoped and a documented no-op when its artifact is absent -
     * le_page_cache_remove_dropin() unlinks only a file carrying the LocalEyes marker,
     * le_page_cache_set_wp_cache(false) strips only the marked define, and le_speed_htaccess() with
     * an empty rule set bails when there is nothing between the markers.
     *
     * NOT on deactivation. That is the whole reason this is a separate pass: running it there is
     * exactly the bug registry.php describes, where every deactivation of every install regex-
     * processed wp-config.php with nothing to undo.
     */
    if (function_exists('le_page_cache_remove_dropin'))  { le_page_cache_remove_dropin(); }
    if (function_exists('le_page_cache_set_wp_cache'))   { le_page_cache_set_wp_cache(false); }
    if (function_exists('le_speed_htaccess')) {
        // The Redirects block is already cleared unconditionally inside le_perf_teardown_all().
        le_speed_htaccess('LocalEyes Compression', array());
        le_speed_htaccess('LocalEyes Cache', array());
    }

    if (function_exists('le_perf_rrmdir') && function_exists('le_perf_cache_root')) {
        $cache_root = le_perf_cache_root();
        le_perf_rrmdir($cache_root);   // clears contents only
        @rmdir($cache_root);           // ...so drop the now-empty root too
    }
    if (function_exists('le_monitor_guard_path')) {
        $guard = le_monitor_guard_path();
        if (file_exists($guard)) { @unlink($guard); }
    }

    /*
     * Any wp-config.php copy the old page-cache write left in the web root. Backups written by the
     * current code live under the cache root and were just removed with it, but a site that deployed
     * the page cache once on an older build and uninstalls without ever calling the write path again
     * would otherwise be left with a plain-text file containing its database password and auth salts,
     * served to anyone who requests the URL, with no plugin remaining to clean it up. Deleted rather
     * than moved, unlike the runtime migration: there is no LocalEyes left to manage the copy, and the
     * live wp-config.php is untouched.
     */
    if (function_exists('le_perf_wp_config_path')) {
        $wpc = le_perf_wp_config_path();
        if ($wpc !== '') {
            $stale = @glob($wpc . '.localeyes-backup-*');
            foreach ((array) $stale as $f) { if (is_file($f)) { @unlink($f); } }
        }
    }

    /*
     * Sweep any OTHER stale LocalEyes directory out of wp-content/plugins. WordPress deletes only the
     * directory being uninstalled, so residue from an earlier broken install would otherwise sit there
     * indefinitely and block a later upload with "Destination folder already exists" -- with no active
     * plugin left to heal it, since nothing in a zip runs during installation.
     *
     * No extra safety logic here: every entry le_heal_scan() returns has already passed the guards in
     * includes/install-heal.php. It never returns the running copy, an active plugin, a directory whose
     * header belongs to another plugin, or anything that is not a realpath-resolved direct child of
     * wp-content/plugins. Note this does sweep an INACTIVE older LocalEyes copy kept for rollback;
     * an active one is always left alone.
     */
    if (function_exists('le_heal_scan') && function_exists('le_heal_delete_dir')) {
        foreach (le_heal_scan() as $le_stale) {
            if (isset($le_stale['type']) && $le_stale['type'] === 'file') {
                @unlink($le_stale['path']);
            } else {
                le_heal_delete_dir($le_stale['path']);
            }
        }
    }
}
