=== Lexa Search ===
Contributors: quocduydev
Author: quocduydev
Author URI: https://quocduy.com.vn
Tags: search, woocommerce, multilingual, vietnamese, relevance
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multilingual, mixed-language product search for WordPress & WooCommerce — diacritic-insensitive, model-code aware, typo-tolerant, BM25F relevance. Self-hosted on MySQL, zero external services.

== Description ==

Lexa Search replaces WordPress/WooCommerce's default `LIKE` search with a real relevance engine that handles **mixed-language content** — a non-English phrase, a Latin brand name, and an alphanumeric model code in the **same** product title — which Anglo-centric search plugins mistokenize.

Per-token language routing: each token is classified (Vietnamese word / Latin word / code / number) and handled correctly and simultaneously, in one field.

* **Diacritic-insensitive** — typing "may cua" finds "Máy Cưa" (works even where the host lacks the `intl` extension).
* **Model-code / SKU aware** — "HS7601" and partial "hs76" both resolve; codes are never folded or stemmed.
* **Typo tolerant** — "may cuaa" / "may cwa" still find the right product; hard typos get a "did you mean" suggestion.
* **BM25F relevance** — field-weighted ranking (title > SKU > attributes > content), not date order.
* **Self-hosted** — a custom MySQL inverted index. No external service, no recurring cost, no data leaves your server.
* **Auto-indexing** — products are re-indexed on save/stock/price/trash via Action Scheduler.

The default engine is built for catalogs up to ~50k items. A pluggable engine seam (Meilisearch / Typesense / Elasticsearch) is planned for larger scale.

Developed by **quocduydev** (https://quocduy.com.vn). Copyright (c) 2026 quocduydev, licensed under GPL v2 or later.

== Installation ==

1. Upload the `lexa-search` folder to `/wp-content/plugins/`, or install the zip via Plugins → Add New → Upload.
2. Activate the plugin.
3. Go to **Lexa Search → Indexing** and click **Build / rebuild index** (or run `wp lexa index` for large catalogs).
4. That's it — front-end product search now uses the engine.

WP-Cron disabled? Add a server cron so auto-indexing drains unattended:
`*/5 * * * * cd /path/to/site && wp lexa run --quiet`

== Frequently Asked Questions ==

= Does it work without WooCommerce? =
Yes. WooCommerce product fields (SKU, categories) are added automatically when present; otherwise it indexes posts, pages, and custom post types.

= Does it require any external service? =
No. The default engine runs entirely on your MySQL database.

= How do I turn it off? =
Deactivate the plugin, or untick "enabled" in Lexa Search → Settings. The site falls back to default search.

== Changelog ==

= 0.3.0 =
* Typo tolerance + "did you mean".
* Vietnamese stopwords and min-should-match for precise multi-word ranking.
* Auto-index via Action Scheduler; admin dashboard; WP-CLI (index/run/reconcile/status/search).
* Front-end query integration (BM25F) replacing the title-only LIKE search.

== Upgrade Notice ==

= 0.3.0 =
First public preview. Rebuild the index after activating.
