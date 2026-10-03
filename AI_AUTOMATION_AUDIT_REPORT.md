# AI Content Automation Audit

Audit date: 2026-09-22

## Result

The AI workflow is now wired as a source-backed editorial pipeline:

`DataForSEO research → verified keyword → public competitor-page gap brief → AI draft → quality gate → draft/publish`

The database is reachable. At audit time it contains 40 keyword records, 0 competitor analyses, and 6 tracked AI articles. No separate `ai_provider_configs` row exists; the legacy direct Gemini setting is configured. On 2026-09-22, saving the AI configuration and a live Gemini connection test both succeeded (`gemini-2.5-flash`, 1438 ms).

## Fixed

- Corrected the dashboard/API contract. The dashboard now selects a real keyword ID, runs a competitor analysis against that ID, generates the article, quality-checks it, then saves it as the selected draft, published, or scheduled state.
- Replaced broken UI IDs and stale state names in competitor analysis. The old controls sent fields the API did not accept, so the feature could not complete.
- Fixed bulk article improvements and custom rewrites to call the supported per-article API operation.
- Added CSRF protection to every state-changing AI dashboard request.
- Restricted competitor crawling to public HTTP(S) hosts; localhost, private/reserved IPs, credential-bearing URLs, and redirects are rejected to prevent SSRF.
- Sanitized AI HTML before preview, publishing, and improving articles. Executable tags, event handlers, inline styles, and non-internal links are removed.
- Made publishing transactional, block duplicate slugs, map scheduled blog entries safely to `draft` (the existing blog enum does not support `scheduled`), and delete both the AI tracking row and blog post together.
- Added validation for AI settings, token limits, schedule settings, dates, and provider update handling.
- Replaced the installer’s public cron token with a randomly generated value and changed the external cron request to use `X-Cron-Token` instead of a query-string secret.
- Removed hard-coded SMTP and application encryption defaults from source. Local secrets are in ignored `.env`; an `.env.example` documents required values.

## Required configuration

1. Revoke the SMTP application password that was previously in source, create a new one, and put it in `.env` as `SMTP_PASS`.
2. Because a new `MASTER_ENCRYPTION_KEY` is now active, re-save any prior AI provider keys in the dashboard. This safely re-encrypts them with the new key.
3. Add an enabled `DataForSEO` provider for verified keyword/competitor metrics. Its API key format is `login:password`.
4. The direct Gemini connection is currently verified. Add a separately managed Gemini or OpenAI-compatible provider only if you want multi-provider switching.
5. For external scheduler monitoring, use the command shown in the admin dashboard. It checks only source-backed briefs and never auto-publishes guessed content.

## Verification

- PHP syntax passed for the changed API, crypto helper, and admin dashboard files.
- JavaScript syntax passed for the updated admin SPA.
- Database connectivity and AI table counts were verified.
- A live model connection was verified. Live DataForSEO research and a production publishing run remain pending until DataForSEO credentials are configured and a verified competitor brief is available.
