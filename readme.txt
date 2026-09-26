=== WP WS Reborn 2026 ===
Contributors: akshay_raje (original), Reborn 2026
Tags: web scraping, css selector, xpath, regex, realtime, shortcode, atom, rss, feed links
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Web scraper for WordPress. Fork of WP Web Scraper with feed link shortcodes. Display realtime data from any website in posts, pages or sidebar.

== Description ==

**WP WS Reborn 2026** (wp-ws-reborn2026) is a fork of WP Web Scraper. It keeps all original [wpws] shortcode and template tag features (CSS selector, XPath, regex, caching, callbacks, etc.) and adds:

* **wpws_atom_links** – Extract http(s) links ending with a given extension from any Atom/RSS feed (e.g. images from CNN RSS: `extension="jpg"`, or zip links from Atom: `extension="zip"`). Uses regex on raw feed; no DOM/namespace issues.
* **wpws_atom_zip_links** – Extract links by extension from Atom/RSS using DOM (alternative for Atom feeds with `<link href="...">`).

Usage examples:

* `[wpws url="https://example.com" query="#content"]` – classic scraper
* `[wpws_atom_links url="http://rss.cnn.com/rss/cnn_topstories.rss" extension="jpg"]` – list of image URLs from RSS
* `[wpws_atom_zip_links url="https://example.com/atom_feed.xml" extension="zip"]` – list of .zip links from Atom

Based on the original [WP Web Scraper](https://wordpress.org/plugins/wp-web-scrapper/) by Akshay Raje (GPLv2).

== Installation ==

1. Upload the plugin folder (e.g. `wp-ws-reborn-2026`) to `/wp-content/plugins/`
2. Activate **WP WS Reborn 2026** in the Plugins menu
3. Configure defaults under Settings → WP WS Reborn 2026; use the Sandbox tab to test shortcodes

== Changelog ==

= 1.3 =
* Dependencies: symfony/css-selector 2.5.5 (2014) → 5.4 (LTS line that still supports PHP 7.4). Removes the PHP 8.4 deprecation notices ("implicitly marking parameter as nullable", "strtolower(): Passing null"). Parser now uses CssSelectorConverter and catches all Throwables from invalid selectors.
* Fix: `callback="wpws_bold_words:A,B"` now bolds every listed word (previously only the first).
* Fix: IPv6 SSRF range check used a float string offset for masks like /7 and /10 (deprecated since PHP 8.1, an error in PHP 9); now uses intdiv(). Results were already correct.
* Removed site-specific helpers `wpws_keep_mobile_table_columns` and `wpws_bold_gorica`. **Breaking** for shortcodes that use them: switch to `wpws_keep_columns:1_2_3_4_10` / `wpws_bold_words:Gorica`, or re-add them from your theme/mu-plugin (and register them via `wpws_allowed_callbacks`).
* Deprecated (still working): fixed-name shortcuts `wpws_keep_first_N_columns`, `wpws_keep_first_N_rows`, `wpws_keep_X_cols_Y_rows`. Use the parameterised `wpws_keep_columns` / `wpws_keep_rows` / `wpws_filter_table_advanced`.
* Cleanup: removed dead PHP 5.3.3 check; plugin header now declares Requires PHP / Requires at least / License / Text Domain; Plugin/Author URI point to this repository; readme install steps corrected.
* Dev: PHPUnit test suite (table specs, JSONPath, SSRF/IP ranges, auth profiles, callback allow-list, bold words) and GitHub Actions CI on PHP 7.4 and 8.4.

= 1.2 =
* Security (critical): AJAX lazy-load (`ajax="1"`) no longer accepts URL, query or arguments from the browser. The placeholder carries only an opaque, HMAC-derived job ID and the parameters stay server-side, so `admin-ajax.php?action=wpws_scrape` can no longer be used as an open proxy by anonymous visitors. The nonce was dropped from this endpoint (it protected nothing and broke lazy-loading on full-page-cached pages once expired).
* Security (critical): credentials (`auth_user`, `auth_pass`, `auth_token`) and `headers` are no longer written into the public page HTML of AJAX placeholders.
* Feature: authentication profiles (Settings → Authentication profiles; `auth_profile="name"`) with bearer, basic and custom-header types, plus a `wpws_auth_profiles` filter for wp-config/env secrets. The block editor no longer stores credentials; blocks that still contain them show a warning and a one-click remove. Legacy inline credentials keep working.
* Security: DNS pinning — requests connect (via CURLOPT_RESOLVE) to the IP that passed the SSRF check, closing the DNS-rebinding window. Hosts that do not resolve are now blocked.
* Security: callback allow-list is now explicit. The "any function named wpws_*" wildcard is removed (it also matched fetch functions such as `wpws_get_content`). **Breaking:** custom `wpws_*` callbacks must be registered via the `wpws_allowed_callbacks` filter.
* Security: settings are sanitized on save (`register_setting` sanitize callback): numeric ranges, domain lists, auth profile syntax. Checkboxes are always stored as 0/1 — fixes "Sanitize HTML Output" silently staying on after being unchecked.
* Fix: rate limiting is now atomic (no lost updates under concurrent requests) and counts only real outgoing fetches per remote host. Previously every page view — including cache hits — counted, so busy pages could hit the limit and render nothing.
* Fix: `memory_limit = -1` (unlimited) no longer makes every response fail with "Content too large".

= 1.1 =
* Security: SSRF protection now re-validates every redirect hop (blocks public→internal redirects, e.g. cloud metadata), resolves both IPv4 (A) and IPv6 (AAAA) records, and blocks hex/octal/decimal integer-IP encodings.
* Security: `debug` now defaults to OFF (the debug HTML comment was emitted into public page source and could leak auth credentials); auth values are masked in any debug output.
* Security: callbacks are now allow-listed (filter `wpws_allowed_callbacks`) instead of blocked via an incomplete blacklist; arbitrary one-arg PHP functions can no longer be invoked from shortcodes.
* Security: XML/HTML parsing hardened with `LIBXML_NONET`; response download capped via `limit_response_size`.
* Security: admin Sandbox/Import controllers sanitize input and build an explicit post array instead of passing raw `$_POST` to `wp_insert_post()`.
* Fix: request timeout floored at 5s and activation default raised to 10s (the 2s default caused spurious failures); fixed `replace_xpath()` document-fragment reuse; fixed `ip_in_range()` /0 edge case; aligned admin text domain to `wp-web-scraper`.
* Fix: `[wpws_atom_zip_links]` no longer ships a hardcoded default URL (url is now required).
* Cleanup: deduplicated table-filter logic, removed dead code, removed `@` error-suppression in the settings view.
* UX: Sandbox debug tab now shows match count, fetch time and errors; richer, working example gallery (CSS/XPath/Regex/JSONPath/tables) plus ready-made shortcode examples.
* Feature: parameterised callbacks via "name:args" syntax (e.g. `callback="wpws_drop_columns:4"`). New table helpers `wpws_keep_columns`, `wpws_drop_columns`, `wpws_keep_rows`, `wpws_drop_rows` (1-based specs like `4`, `4_7`, `5-8`) — drop specific columns/rows and keep the rest without listing every keeper.
* Fix: query type for validation is read from the normalised args, so regex/XPath/JSONPath queries containing `<`/`>` validate correctly when args are passed as a query-string (e.g. the Sandbox).
* Docs: complete guide (help-files) and in-plugin Help tab + Quick Help updated for all new features — JSONPath, authentication, mobile emulation, AJAX/background refresh, table keep/drop helpers, parameterised callbacks, feed shortcodes, Gutenberg block, and the security/allow-list model.

= 1.0 =
* Initial release as WP WS Reborn 2026
* Plugin name: WP WS Reborn 2026 (wp-ws-reborn2026)
* Shortcodes: [wpws] (unchanged), [wpws_atom_links], [wpws_atom_zip_links]
* Help: wpws_atom_links example in guide; Sandbox: “Fill form from shortcode” and “Clear” for shortcode field
* Based on WP Web Scraper 4.0 codebase
