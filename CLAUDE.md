# LocalEyes — working rules

## DO NEVER INCLUDE WOOCOMMERCE

No WooCommerce features, panels, REST routes, schema, options, fixtures, site profiles, or examples. Not
even a one-line fix to existing WooCommerce code. Not a README note that names it. **This is not a
judgement call and has no exceptions.**

The WooCommerce SEO module was deleted from this plugin deliberately. If a change would touch anything
WooCommerce, do not make it. If it seems the rule must have an exception for the case in front of you,
that is the signal to ask, not to decide. Leaving something out of a batch and saying so is always
available.

**The single documented exception** is the cart/checkout/account never-cache guard in
`includes/perf/page-cache.php`, which never-caches those pages and any request carrying a cart-session
cookie. That is cache correctness, not a feature: without it the page cache stores one shopper's cart
page and serves it to the next visitor. The two cookie names cannot be generalised, because detecting a
cart-session cookie requires naming it.

Enforced by `checkNoWooCommerce()` in `tools/contractcheck.js`, which allowlists those occurrences by
exact string **and count** — so a new reference in that file still fails the build.

## Verification on this machine

There is no PHP runtime here, so `php -l`, PHPStan, duplicate-function detection and the
`tools/harness` suites cannot run. `tools/audit.ps1` runs but SKIPs five checks without `-Php <php.exe>`.

What actually runs, and must be green before any change is called done:

```
node tools/jscheck.js
node tools/phpbalance.js
node tools/contractcheck.js
.\tools\audit.ps1
```

Never report PHP changes as verified. State which checkers ran.

## Contract guards

Every check in `tools/contractcheck.js` exists because the exact bug it looks for shipped at least once.

When adding a guard, prove it by reintroducing the real bug and confirming it fires, then restore. A
guard never seen to fail is a guess — several here passed vacuously when first written, including one
whose literal-string pattern missed the actual regression, and one whose scanner desynchronised on a
regex literal and declared three live helpers dead.
