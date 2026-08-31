=== 404 to 301 - Redirect Manager, 301 Redirection, 404 Error Logs & 404 Monitoring ===
Contributors: aioseo, smub, benjaminprojas
Tags: redirect manager, redirection, 301 redirect, 404 monitor, broken links
Requires at least: 6.4
Tested up to: 7.1
Stable tag: 4.0.4
Requires PHP: 7.4
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Redirect manager for 301, 302 and 307 redirection. Monitor and fix 404 errors, log every broken link and protect your SEO after a migration.

== Description ==

**404 to 301** is a redirect manager and 404 error monitor for WordPress. Create your own custom redirects with 301, 302 or 307 redirection, send every remaining 404 error to a page of your choosing, and keep a full log of the broken links visitors and search engines actually hit.

Broken links cost you traffic. A visitor who lands on a 404 page usually leaves, and Google eventually drops the URL from its index along with whatever link equity it had earned. This redirect manager fixes both halves of that problem. You get precise redirection rules for the URLs you know about, and 404 error logs that surface the ones you don't.

> **Need more than redirects?**<br />
> 404 to 301 is free and every feature is included, with no license key and no upsell. If you also want full-site and .htaccess redirect rules, automatic 404 monitoring across large sites, Google Search Console crawl error data, and a complete SEO toolkit alongside your redirection, take a look at [AIOSEO Pro](https://aioseo.com/?utm_source=wprepo&utm_medium=link&utm_campaign=liteplugin).

= 🔀 Custom 301 Redirects and Redirection Rules =

Take control of your URLs from one redirect manager. Create as many custom redirects as you need and pick the redirect type per rule, so a permanent move uses a 301 redirect and a temporary campaign URL uses a 302 or 307 redirect.

Match URLs by exact path when you know the address, by prefix when a whole folder has moved, or by regular expression when you need a pattern. Prefix matching covers the wildcard redirect cases, where every URL under `/old-shop/` should land on its counterpart under `/shop/`. Regex redirect rules handle the messy ones, like a query string that changed format or a date-based permalink structure you have retired.

Every redirect carries a hit counter and a last-hit timestamp, so you can see which rules are earning their keep and which are dead weight. Toggle a rule off without deleting it when you want to test, and manage the whole set from a table with search, filters, bulk actions and pagination.

= 🎯 Automatic 404 Redirection to Any Page =

You will never catch every broken URL by hand. Set a global fallback and 404 to 301 redirects every leftover 404 error automatically, to your homepage, a specific page, or any URL you like, using the redirect type you choose.

That turns a dead end into a route back into your site. Visitors who would have bounced off a 404 page keep browsing, and search engines following an old inbound link reach real content instead of an error.

= 📋 404 Error Logs and Broken Link Monitoring =

The 404 error logs tell you which links are breaking and where the traffic is coming from. Each entry records the requested URL, the referrer, the IP address, the user agent and the timestamp, so you can tell a mistyped address apart from a broken inbound link that deserves a redirect.

Busy broken URLs stay readable. Repeat hits are deduplicated into a single row with a hit count, so one popular dead link is one entry instead of thousands. Sort by hits and the URLs worth fixing rise to the top on their own.

Two filters do most of the triage for you. Filter by referrer source to separate the 404 errors your own pages are linking to, which are yours to fix in the content, from the ones arriving off other sites, which want a redirect. Filter by request type to split real page and post requests from missing files and assets, so a hotlinked image or a stale script does not sit in the same queue as a lost article.

Each 404 error carries a status of open, ignored or fixed, so the log works as a queue rather than a pile. Filter by date range, search by URL, then turn any logged 404 into a redirect in a couple of clicks. Privacy is covered too: IP addresses can be masked, and paths you do not care about can be excluded from logging entirely.

= 📊 404 Monitoring Without Opening the Plugin =

Most 404 error monitoring fails for a boring reason. Nobody remembers to go and look. So the numbers come to you instead.

A Recent 404s widget on the WordPress dashboard lists your busiest broken URLs from the last 30 days, and the admin menu carries a count of the 404 errors still waiting on a decision, the same way WordPress flags pending updates. Summary cards above the log split the totals by status, so you can see what is still open against what you have already fixed or ignored. WordPress Site Health runs its own checks on your 404 monitoring setup and tells you when logging is switched off, when the log table has grown large enough to need pruning, or when another redirect plugin is installed alongside this one and the two could fight.

= 🔍 Protect Your SEO After a Site Migration =

A site migration is where redirects earn their reputation. Change a permalink structure, move to a new domain, or retire a batch of old posts, and every inbound link and every indexed URL points somewhere that no longer exists.

Map the old URLs to the new ones with prefix or regex redirection and the link equity follows. For everything you miss, the 404 error logs act as a safety net: watch the log for a week after launch and you will see the URLs Google and your visitors are still asking for, then redirect them. Those are the same URLs that show up as crawl errors in Google Search Console, caught on your own site without waiting for the next crawl.

The same routine works long after launch. Link rot is constant, external sites link to pages you later rename, and a redirect manager plus 404 monitoring is how you keep finding those before they cost you rankings.

> **Catch broken links before your visitors do**<br />
> 404 to 301 handles the visitors who already hit a dead URL. To find the dead links still sitting in your content, install [Broken Link Checker](https://wordpress.org/plugins/broken-link-checker-seo/) — it's free, scans your posts, pages and comments for internal and external links that no longer resolve, and lets you fix or unlink them from one screen.

= 🔁 Automatic Redirects When a Permalink Changes =

Renaming a post is the most common way to break your own URLs. Switch on slug change monitoring and 404 to 301 writes the redirect for you: rename a post or page, and the old permalink starts pointing at the new one before anyone hits a 404.

You also get control over the guessing WordPress does on its own. Core quietly tries to match an unknown URL to a similar post before this plugin ever sees the request, which hides real 404 errors from your logs and can send a visitor to the wrong article. Leave that behavior alone, block just the closest-post guesses, or block guessing altogether so every miss is logged and redirected by your rules instead.

= 📧 404 Email Alerts and Scheduled Reports =

Nobody wants to live in the dashboard. Set a hit threshold and 404 to 301 sends an email alert when a broken link starts getting real traffic, so a busy site does not flood your inbox over a single stray request.

Switch on Email Reports and you also get a daily, weekly or monthly digest of your 404 activity with the log attached as a CSV. It is a five-second read that tells you whether anything needs attention.

= 📥 Import Redirects in Bulk, Export Your 404 Logs =

Moving in from another redirect plugin does not mean retyping your rules. The redirects importer does a bulk redirect import from a CSV file, previews what it found and flags duplicates before anything is written, so you can see the damage before you commit to it.

Exports work the other way. Download the 404 error log as a CSV whenever you want it, filtered exactly the way the Logs page is showing, so a report you built with the referrer and date filters is the report you get. Your settings travel too: export them as JSON on one site and import them on the next, which turns a redirect and logging configuration you like into something you can roll out across every site you run.

Log cleanup keeps the table honest. Prune by age, by row count, or on a schedule, so a high-traffic site does not grow an unbounded 404 log, with a purge action for when you want to start over.

= 🧰 Redirect Manager Use Cases =

* **Site migration redirects** - Map old URLs to new ones after a redesign, domain change or permalink update.
* **Ecommerce URL changes** - Redirect discontinued products and retired category pages to live alternatives.
* **Blog cleanup** - Point merged or deleted posts at the article that replaced them.
* **Broken inbound links** - Recover traffic from external sites linking to URLs that have moved.
* **Campaign URLs** - Use short, memorable paths with a 302 or 307 redirect to a landing page.
* **Fixing 404 errors at scale** - Work through the 404 error logs by hit count and redirect what matters.
* **Missing images and files** - Spot hotlinked assets and dead downloads with the request type filter.

= ⚡ Built for Performance and Developers =

The plugin does its work only on a 404 request. Healthy page loads are never touched, so a redirect manager that sits on a busy site costs nothing on the pages that are working. Custom redirects resolve through a hashed, indexed lookup.

Developers get a REST API at `/404-to-301/v1/` covering every admin operation, a full WP-CLI command set (`wp 404-to-301 logs|redirects|settings`), and a filterable action pipeline for hooking in custom logic. It is multisite compatible, and each site in the network keeps its own redirects and 404 error logs.

= Full 404 to 301 Feature List =

Everything the free redirect manager includes. For the wider SEO toolkit, see [All in One SEO](https://aioseo.com/?utm_source=wprepo&utm_medium=link&utm_campaign=liteplugin).

* Custom redirect manager with unlimited redirects
* 301 redirect (permanent) support
* 302 redirect (found) support
* 307 redirect (temporary) support
* 410 Gone and 451 status responses
* Exact URL matching
* Prefix matching for folders, URL sections and wildcard redirect patterns
* Regex redirect rules for pattern-based redirection
* Query string handling per rule (ignore or require)
* Enable or disable any redirect without deleting it
* Hit counter on every redirect rule
* Last-hit timestamp on every redirect rule
* Automatic 404 redirection with a global fallback
* Redirect all 404 errors to the homepage
* Redirect all 404 errors to a chosen page or URL
* Full 404 error logs with requested URL and referrer
* IP address and user agent logging
* Deduplicated 404 logs with hit counts
* 404 error status workflow (open, ignored, fixed)
* Referrer source filter (linked from your site, from elsewhere, or no referrer)
* Request type filter (pages and posts, or files and assets)
* Date-range filtering and URL search on logs
* One-click redirect creation from a logged 404
* Bulk actions on redirects and 404 logs
* Recent 404s dashboard widget for at-a-glance 404 monitoring
* Open 404 count badge on the admin menu
* Summary cards for today, this week and all-time 404 totals
* Broken link monitoring across the whole site
* 404 email alerts with a configurable hit threshold
* Scheduled email reports (daily, weekly, monthly) with CSV attached
* Bulk redirect import from CSV, with a preview and duplicate detection
* Migration from other redirect plugins
* Logs exporter with filter-aware CSV download
* Settings export and import as JSON for reuse across sites
* Log cleanup by age, row count or schedule, plus a purge action
* Pruning that keeps the 404s you have already linked to a redirect
* IP address masking for GDPR
* Path exclusions to keep noise out of the logs
* Skip search engine bot 404s so crawlers don't fill the log
* Admin-area 404 tracking, on or off
* Control over WordPress URL guessing, from untouched to fully blocked
* Slug change monitoring with automatic redirect creation
* REST API for every admin operation
* WP-CLI commands for logs, redirects and settings
* Multisite compatible, per-site redirects and logs
* Site Health checks covering logging, log size, cron jobs and plugin conflicts

= 🏆 Built by the All in One SEO Team =

404 to 301 has been running on WordPress sites since 2014 and is active on more than 100,000 of them. It is maintained by the team behind All in One SEO, Broken Link Checker and more, used on over 3 million sites.

= A Redirection Alternative Without the Bloat =

If you have been comparing redirect plugins you have probably looked at Redirection, 301 Redirects, Safe Redirect Manager and the redirect modules inside Yoast SEO Premium and Rank Math. They are capable tools and some of them are very widely used.

The difference here is scope. 404 to 301 does redirect management and 404 error logging, and it does not ask you to install an SEO suite to get them or pay for a license to unlock the importer. Every feature listed above is free, the log table keeps itself trimmed, and the plugin stays out of the request path on pages that are not 404ing.

= Branding Guidelines =

404 to 301 is a product of All in One SEO. When writing about the plugin, please use the correct branding:

* 404 to 301 (correct)
* 404to301 (incorrect)
* 404 To 301 (incorrect)
* Redirect Manager 404 (incorrect)

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install **404 to 301** directly from the WordPress.org plugin directory.
2. Activate it from the **Plugins** screen.
3. Open **404 to 301** in the admin sidebar. Redirects, 404 Logs and Settings all live there.
4. Add your first custom redirect, or set a global 404 fallback under **Settings → Redirects**, and you are done.

== Frequently Asked Questions ==

= Can I create my own custom redirects? =

Yes. The Redirects page lets you create unlimited custom redirects with exact, prefix or regex matching and your choice of redirect type (301, 302, 307 and more). Each redirect can be toggled active/inactive and shows a hit counter.

= What happens to 404 errors I don't have a redirect for? =

You can set a global fallback that automatically redirects every remaining 404 error to your homepage, a chosen page, or any URL, using the redirect type you prefer. If you'd rather leave them, every 404 is still logged so you can review and fix it.

= Does this slow down my site? =

No. The plugin only does work on a 404 request, so normal, healthy page loads aren't touched at all. Custom redirects use a hashed, indexed lookup for near-instant matching.

= How do I tell which 404 errors are worth fixing? =

Sort the log by hits, then use the referrer source filter. A 404 with traffic and a referrer from your own site is a broken link in your content. One with a referrer from another site is an inbound link worth redirecting. One with no referrer at all is usually a bot or a typed URL, and often safe to ignore.

= Can I import or export my redirects and logs? =

Yes, and it's all built in with nothing extra to install. **Settings → Import/Export** does a bulk redirect import from a CSV file or another redirect plugin, and exports your settings as JSON. The Logs page exports your 404 error log as a filter-aware CSV, and Email Reports can send that CSV to you on a schedule.

= Where do I find the 404 log and redirect settings? =

Everything lives under **404 to 301 → Settings**, split into four tabs. Redirects holds the default 404 redirect and the URL-guessing controls, 404 Logs holds logging, exclusions and log cleanup, Notifications holds email alerts and scheduled reports, and Import/Export handles CSV and JSON.

= Do I need to enable any features first? =

No. Every capability ships switched on and ready, including the redirects importer, the logs exporter, log cleanup and email reports. Earlier versions sold or shipped some of these as separate add-on plugins; if you still have those installed, you can delete them.

= Is it GDPR friendly? =

Yes. IP addresses can be left out of the 404 logs entirely, and you can exclude specific paths from being logged. Bot traffic can be skipped too, so search engine crawlers don't fill the log.

= Does it support multisite? =

Yes. Each site in the network keeps its own redirects and 404 logs.

= Where can I get help? =

Post on the [support forum](https://wordpress.org/support/plugin/404-to-301/) and we'll help you out.

== Screenshots ==

1. 404 error logs with referrer and request-type filters, bulk actions, hit counts and lifecycle status.
2. Custom redirects manager with exact / prefix / regex matching and redirect types.
3. Redirects settings — default 404 redirect, URL guessing and slug change monitoring.
4. 404 Logs settings — logging options, path exclusions and log cleanup.
5. Notifications settings — instant email alerts and scheduled reports.
6. Import/Export — bulk redirect import from CSV and settings export.
7. Recent 404s dashboard widget.

== Changelog ==

= 4.0.4 =
* New: MCP tab in Settings that connects an AI client (Claude, Gemini, Cursor, VS Code) to your site. The plugin registers 11 abilities with the WordPress Abilities API, so an assistant can read your 404 logs, create and repoint redirects, check where a URL lands and change settings from chat.
* Improve: Redirect matching is faster on sites with a lot of rules. Only the rule that actually matches is loaded now, rather than every candidate, and pattern rules are cached instead of being re-queried for every 404.
* Improve: The 404 logs list and the Recent 404s dashboard widget are much faster on large log tables. Both columns they sort on are now indexed.
* Improve: Log trimming is on by default on new installs, keeping 30 days of 404s so the table cannot grow without limit. Existing sites keep whatever they already have.
* Improve: New page header carrying the 404 to 301 logo, and the admin menu now uses the plugin's own icon instead of a generic WordPress one.
* Improve: The "Paths to ignore" setting accepts `*` as a wildcard, so one entry such as `/20*/` covers every year of date-based archive URLs instead of needing one entry per year.
* Fix: An entry in "Paths to ignore" typed with capital letters never matched anything, and an entry ending in a slash did not match a request that ended exactly there — `/feed/` skipped `/blog/feed/page/2` but not `/blog/feed/`. Entries are now matched the same way the request is, so an entry means what it looks like.
* Fix: On a server using MySQL's default date handling, any future change to the plugin's tables would have failed, because the tables were created with a zero-date default that MySQL re-validates whenever it rebuilds a table. The defaults are dropped on upgrade. Both timestamps have always been written by the plugin, so nothing depended on them.

= 4.0.3 =
* New: The Redirects Importer, Logs Exporter, Logs Cleaner and Email Reports add-ons are now built into the plugin and free. Nothing to install, nothing to enable, no licence key. If you have the separate add-on plugins, a notice lists the ones you can delete.
* New: Recent 404s dashboard widget listing your busiest broken URLs from the last 30 days.
* New: Open 404 count badge on the admin menu, so unresolved 404 errors are visible without opening the plugin.
* New: Referrer source filter on the Logs page — separate 404s linked from your own site from those arriving off other sites or with no referrer at all.
* New: Request type filter on the Logs page — split page and post requests from missing files and assets.
* New: Site Health checks for logging state, log table size, cron schedules, conflicting redirect plugins and broken link monitoring.
* New: Settings export and import as JSON, for reusing a configuration across sites.
* New: "Track admin 404s" setting, so 404s inside wp-admin can be logged or ignored.
* New: About Us page listing the free plugins from our team, installable in one click.
* New: The 404 path in the logs is now a link, so you can open it in a new tab and check that your redirect fires.
* Improve: Settings are reorganised into four tabs — Redirects, 404 Logs, Notifications and Import/Export — with every option filed under what it actually governs.
* Improve: "Block WordPress URL guessing" is now a choice of three modes rather than a single switch: leave core's guessing alone, block only its closest-post guesses, or block all of it.
* Improve: Save Changes now sits above and below the settings cards instead of in a sticky bar.
* Improve: Clearer setting names, descriptions and section titles throughout Settings.
* Improve: Fields that depend on a disabled toggle are now hidden rather than shown greyed out.
* Fix: Trashing a post could end in a fatal error. WordPress does not guarantee it passes a post object to the hook the slug monitor listens on, and a missing one is now skipped instead of stopping the request.
* Fix: On sites using ALTERNATE_WP_CRON, every 404 was counted twice. WordPress reloads the same URL to run its cron, and that second arrival is no longer counted as another hit.
* Fix: A redirect rule using regex captures could build a protocol-relative destination from the requested URL, sending the visitor off-site. Captures can no longer introduce a host.
* Fix: Hardened unserialisation in the v3 migration against object injection.
* Updated: Telegram Alerts is deprecated. It keeps working on sites that already have a live connection, but is hidden everywhere else and cannot be switched back on once disabled.
* Updated: The `404_to_301_redirects_importer_sources` filter is now `404_to_301_import_sources`.
* Updated: The bundled Portuguese (pt_PT) translation. Every string in it belonged to the version 3 admin, so it could no longer translate anything; translations now come from translate.wordpress.org.
* Updated: The Add-ons page and per-add-on licence management are gone, along with the Freemius integration. With every feature bundled and free there is nothing left to license, and updates come from WordPress.org. The `404_to_301_freemius_plugin_id` and `404_to_301_freemius_args` filters were removed with it.

= 4.0.2 =
* Improve: Add-ons catalogue now pulls each free add-on's icon and banner from the WordPress.org asset CDN and links to its WordPress.org plugin page.
* Improve: Clearer Redirects Importer description in the add-ons catalogue.
* Fix: Migration banner no longer shows on fresh installs running on SQLite (such as WordPress Playground).
* Fix: Restored the spacing below the migration banner so it no longer touches the logs summary cards.

= 4.0.1 =
* New: Summary card strip above the Logs and Redirects tables for an at-a-glance overview.
* New: "Purge all logs" action in Settings → Tools → Danger Zone with a confirmation modal.
* New: Custom redirect modal on the Logs page now also lets you edit an already-linked redirect.
* Improve: Redesigned log "View details" modal with a cleaner table layout and prominent 404 path.
* Improve: Log status is decoupled from custom redirect linkage — the redirect link is shown as a separate badge so the workflow status (open / ignored / fixed) stays meaningful.
* Improve: Linking a custom redirect now sets the log to Fixed only when the redirect is active; toggling a redirect's active state syncs every linked log automatically.
* Improve: Deleting a custom redirect now unlinks any logs that referenced it.
* Improve: Source field is locked when editing a redirect that is linked to a 404 log, so the link can't be broken accidentally.
* Fix: Stale per-row cache on logs after bulk status syncs.

= 4.0.0 =
* New: Custom redirect manager with exact, prefix and regex matching and per-redirect redirect type.
* New: Active/inactive toggle, hit counter and last-hit timestamp on every redirect.
* New: Dedicated, indexed database tables for 404 logs and custom redirects.
* New: Modern React-powered admin with full-featured Logs and Redirects tables (search, filters, bulk actions, pagination).
* New: Per-log lifecycle status (open / ignored / fixed) and date filters.
* New: Email notifications with a configurable hit threshold.
* New: REST API at `/404-to-301/v1/`.
* New: WP-CLI command set — `wp 404-to-301 logs|redirects|settings`.
* New: Add-ons catalogue for free and premium extensions.
* Improve: IP masking and path exclusions for GDPR-friendly logging.

For the full release history, see the [changelog](https://github.com/awesomemotive/404-to-301/releases).

== Upgrade Notice ==

= 4.0.4 =
Adds an MCP tab so an AI client can work on your redirects and 404 logs, plus a rotating menu item introducing another plugin from our team each week. Wildcards in Paths to ignore, faster redirect matching and log queries, log trimming on by default for new installs, and several fixes.
