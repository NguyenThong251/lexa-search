=== Lexa Search ===
Contributors: quocduydev
Author: quocduydev
Author URI: https://quocduy.com.vn
Tags: search, woocommerce, multilingual, vietnamese, relevance
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 0.4.2
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

= 0.4.2 =
* The plugin's repository is now public, so update checks need no GitHub token and no configuration at all. A token remains optional, purely to raise GitHub's unauthenticated rate limit on busy shared hosts.

= 0.4.1 =
* The Indexing screen now shows the active "newest products first" setting — mode, which date it uses and the half-life — so the ranking change is verifiable from the dashboard instead of only by searching the storefront.
* Fixed stale copy on the Indexing screen claiming that indexing "does not change your site search yet". It has done since 0.3.0.

= 0.4.0 =
* Newest products first: a freshness pass that pushes recently added (or recently edited) products up the results. Configurable in Lexa Search → Settings — Off / Light / Medium / Strong, or "Newest first" for a strict date sort — with a choice of creation vs modified date and an adjustable half-life. Applies to the search page and the autocomplete API alike, and needs no reindex.
* Fixed: saving the settings screen silently switched front-end search off, because the form had no "enabled" field for the sanitizer to read. The checkbox now exists, and a one-time migration re-enables installs that were switched off by the old bug (a deliberate "off" set in 0.4.0 or later is left alone).
* Fixed: the Indexing dashboard and `wp lexa status` reported "front-end is using the engine" from the index-ready flag alone, so they showed green on exactly the sites where search was silently disabled. Both now report the real state and say which half is missing.
* Fixed: clearing the half-life field stored 1 day instead of falling back to the 180-day default.
* Self-hosted updates from the plugin's private GitHub repo.

= 0.3.0 =
* Typo tolerance + "did you mean".
* Vietnamese stopwords and min-should-match for precise multi-word ranking.
* Auto-index via Action Scheduler; admin dashboard; WP-CLI (index/run/reconcile/status/search).
* Front-end query integration (BM25F) replacing the title-only LIKE search.

== Upgrade Notice ==

= 0.4.2 =
Documentation only. If you added LEXA_GITHUB_TOKEN to wp-config.php you can now remove it — updates work without it.

= 0.4.1 =
Dashboard-only changes. No ranking, settings or index changes.

= 0.4.0 =
Changes search result ordering: new products are boosted by default (Medium). No reindex needed. Set Lexa Search → Settings → "Newest products first" to Off to keep the previous pure-relevance ranking.

= 0.3.0 =
First public preview. Rebuild the index after activating.
