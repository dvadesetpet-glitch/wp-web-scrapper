# WP WS Reborn 2026

A hardened, feature-extended fork of [WP Web Scraper](https://github.com/wp-plugins/wp-web-scrapper)
(by Akshay Raje). **Self-contained:** download or clone this repository and use it
directly as a WordPress plugin — `vendor/` is bundled, so no Composer install is needed.

## Install

Copy this folder into `wp-content/plugins/` (e.g. `wp-content/plugins/wp-ws-reborn-2026/`),
then activate **WP WS Reborn 2026** under Plugins. Configure it under
**Settings → WP WS Reborn 2026** (Sandbox tab to test shortcodes).

## Highlights over the original

- **JSONPath** query type for JSON APIs (`query_type="jsonpath"`)
- Feed link shortcodes: `wpws_atom_links`, `wpws_atom_zip_links`
- HTTP authentication (basic / bearer), mobile emulation, AJAX lazy-load,
  stale-while-revalidate background cache refresh
- Gutenberg block, table keep/drop column & row helpers, parameterised callbacks
  (e.g. `callback="wpws_drop_columns:4"`)
- Security hardening: SSRF redirect re-validation (IPv4/IPv6 + integer-IP forms),
  allow-listed callbacks, credential masking, `LIBXML_NONET`, response-size cap

See [`readme.txt`](readme.txt) for the full changelog, and the in-plugin **Help** tab
or [`help-files/html/wpws-guide.html`](help-files/html/wpws-guide.html) for the complete guide.

## Development

Requires PHP 7.4+. `vendor/` is committed so the plugin runs without Composer; after changing
dependencies run `composer install --no-dev` and commit `vendor/`.

Unit tests (no WordPress needed — `tests/bootstrap.php` stubs the few WP functions used):

```sh
phpunit        # PHPUnit 9.6
```

CI runs the suite on PHP 7.4, 8.1 and 8.4 for every push and pull request.

## License

GPLv2 or later, same as the original plugin.
