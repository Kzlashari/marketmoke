# Technical SEO Audit Report

Audit date: 2026-10-01

## Findings

- The original admin SEO screen was limited to verification strings and a custom head textarea. Its requests did not match the settings API contract, so saved settings could not reliably power public output.
- `robots.php` and `sitemap.php` used separate, duplicated logic. RSS had no endpoint. The `.htaccess` SPA rewrite did not reserve `/robots.txt`, `/sitemap.xml`, or `/rss.xml`.
- A shared SEO helper existed but was not used by the original public file endpoints or the HTML head. Generated schema, canonical tags, and metadata were therefore disconnected from most admin settings.
- The database stores editable content in `blog_posts`, `pages`, and `site_settings`. Published blog posts and pages are valid sitemap and RSS sources. Private dashboards, API routes, auth routes, drafts, and deleted rows are excluded.
- The marketplace has public collection routes (`/marketplace` and `/browse-websites`) but no confirmed stable public per-listing route. The sitemap includes only those real public hubs, avoiding speculative listing URLs.

## Implemented Design

- `includes/seo.php` is the single configuration, robots, sitemap, RSS, canonical, schema, and status service.
- Public documents are dynamic database-backed endpoints. As a result, publishing, updating, unpublishing, or deleting a blog/page is reflected immediately when automatic updates are enabled, with no stale static sitemap to maintain.
- When automatic updates are disabled, the last explicitly regenerated robots, sitemap, and RSS snapshots are served until another regeneration. This makes the toggle operational instead of cosmetic.
- Sitemap chunking switches the root document to a sitemap index above the configured chunk size and serves `/sitemap-N.xml` parts.
- The protected settings API validates custom JSON-LD before saving and records regeneration timestamps. The admin SEO dashboard saves the same settings used by public files and rendered page metadata.

## Local Verification Notes

- `/robots.txt`, `/sitemap.xml`, and `/rss.xml` returned HTTP 200 locally with valid text/XML and no private URLs in the sitemap.
- Sitemap and RSS XML parsed successfully.
- The local database used for verification was missing the optional `pages` table and had no published posts, so the sitemap contained only verified public hubs and RSS contained an empty valid channel. The generator safely handles this partial local installation.
