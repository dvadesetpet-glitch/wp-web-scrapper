# posao — WP Web Scraper (fork)

This repository is a fork of [wp-plugins/wp-web-scrapper](https://github.com/wp-plugins/wp-web-scrapper),
the original **WP Web Scraper** plugin by Akshay Raje. The original plugin files
remain at the repository root for reference.

## WP WS Reborn 2026

An updated, hardened version lives in [`WP-WS-Reborn-2026/`](WP-WS-Reborn-2026/).
Install that folder as a WordPress plugin (`wp-content/plugins/WP-WS-Reborn-2026/`).

Highlights over the original:

- **JSONPath** query type for JSON APIs (`query_type="jsonpath"`)
- Feed link shortcodes: `wpws_atom_links`, `wpws_atom_zip_links`
- HTTP authentication (basic / bearer), mobile emulation, AJAX lazy-load,
  stale-while-revalidate background cache refresh
- Gutenberg block, table keep/drop column & row helpers, parameterised callbacks
  (e.g. `callback="wpws_drop_columns:4"`)
- Security hardening: SSRF redirect re-validation (IPv4/IPv6 + integer-IP forms),
  allow-listed callbacks, credential masking, `LIBXML_NONET`, response-size cap

See [`WP-WS-Reborn-2026/readme.txt`](WP-WS-Reborn-2026/readme.txt) for the full
changelog and usage guide.

## License

GPLv2 or later, same as the original plugin.
