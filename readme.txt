=== WP WS Reborn 2026 ===
Contributors: akshay_raje (original), Reborn 2026
Tags: web scraping, css selector, xpath, regex, realtime, shortcode, atom, rss, feed links
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1
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
* `[wpws_atom_zip_links url="https://oss..../atom_feed.xml" extension="zip"]` – list of .zip links from Atom

Original plugin: [WP Web Scraper](http://wp-ws.net/). Original readme and full changelog are in the `NEPOTREBNO` folder.

== Installation ==

1. Upload the plugin folder (e.g. `Wp-Ws-Rebirth-2026`) to `/wp-content/plugins/`
2. Activate **WP WS Reborn 2026** in the Plugins menu
3. Configure defaults under Settings → WP Web Scraper; use Sandbox to test shortcodes

== Changelog ==

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
