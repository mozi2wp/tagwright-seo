=== Tagwright SEO ===
Contributors: mozi2wp
Tags: seo, sitemap, schema, redirects, open graph
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.5.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fast, lightweight SEO: meta tags, social cards, schema, XML sitemaps and content analysis on dedicated database tables.

== Description ==

Tagwright SEO covers everything a site needs to be found, without slowing it down.

Most SEO plugins scatter their data across dozens of post meta rows. Tagwright SEO keeps every SEO field for a post, term or author in one row of its own indexed table. Reading a page's SEO data is a single lookup, sitemaps are built with one query, and the dashboard can summarize thousands of posts in milliseconds.

Features you do not use can be switched off, and switched-off modules load no code at all.

= Search appearance =

* Title and meta description templates for every post type, taxonomy, archive and special page
* 25+ template variables such as %title%, %sitename%, %sep%, %excerpt% and %category%, shown as chips: type @ or / to pick one with the keyboard
* Live search and social previews right next to every template in the settings
* Per post, term and author overrides for title, description and canonical URL
* Optional legacy meta keywords, off by default
* Full robots control: noindex, nofollow, noarchive, nosnippet, noimageindex, max-snippet, max-image-preview and max-video-preview
* Automatic canonical URLs, including paginated archives
* Live Google preview for desktop and mobile

= Social =

* Open Graph and X (Twitter) Card tags
* Default and per-post sharing images, titles and descriptions
* Live Facebook and X previews in the editor

= Schema =

* One connected JSON-LD graph per page: Organization or Person, WebSite, WebPage, Article and BreadcrumbList
* Choose page and article types per content type or per post

= XML sitemaps =

* Choose your own sitemap file name, such as sitemap.xml
* Sitemap index with posts, pages, custom post types, taxonomies, authors and monthly archives
* Last modified, change frequency and priority for every URL, taxonomies included
* Set change frequency and priority per content type, or override them on a single post or term
* Automatic priority based on comment count
* Add your own extra URLs with priority, frequency and date
* Optional gzip-compressed sitemaps
* Image entries, a readable stylesheet and automatic robots.txt reference
* Served before WordPress runs its main query, straight from the index table

= Content analysis =

* Focus keyphrase with SEO checks for title, description, URL, introduction, density, subheadings, images, links and length
* Readability checks: Flesch reading ease, sentence and paragraph length, subheadings, passive voice, transition words and sentence variety
* Warns when a keyphrase is already used elsewhere on the site
* Runs only in the editor, never on the front end

= Dashboard =

* Site SEO score, score trends, score distribution and issues over time
* A to-do list that links straight to the posts that need work
* Add-ons can plug in their own charts and widgets

= Technical =

* Attachment page redirects, ?replytocom removal and optional category base removal
* robots.txt editor and llms.txt generator
* Search engine verification for Google, Bing, Yandex, Baidu and Pinterest

= Redirects and 404 monitor =

* 301, 302, 307, 410 and 451 redirects with exact, prefix and regex matching
* Automatic redirects when you change a post's slug or move a page
* A 404 log with hit counts, so you can fix broken links in one click
* CSV import and export
* Import redirects from Rank Math, Yoast SEO Premium, Redirection and All in One SEO Pro
* Fast: most requests make no extra database query at all

= Content blocks =

* Breadcrumbs, table of contents, FAQ, How-To, reading time and related posts blocks
* Matching shortcodes for the classic editor and page builders, plus a breadcrumbs function for themes
* FAQ and How-To blocks add their schema to the page graph

= Instant indexing =

* Tell Bing, Yandex and other IndexNow search engines about new, updated and deleted posts
* Optional Google Indexing API support
* Submit any list of URLs by hand, or straight from the Posts screen

= Image SEO =

* Fill in missing alt and title text from templates such as %filename% and %title%
* Set alt text from the file name when you upload
* Fill every image in your media library in one go

= Technical tools =

* Remove clutter from the page head: shortlinks, RSD, generator tags, emoji scripts, feed links and more
* Add your own text before or after each item in your RSS feed
* HTML sitemap block and shortcode
* .htaccess editor with automatic backups

= Integrations =

* Works out of the box with AMP, Web Stories, bbPress, BuddyPress and page builders such as Elementor, Divi, Beaver Builder, Bricks and Oxygen

= Tools =

* Import from Yoast SEO, Rank Math and All in One SEO
* Settings export and import
* Setup wizard, WP-CLI commands and SEO data in the REST API for headless sites
* WPML and Polylang ready, multisite compatible

= Tagwright SEO Pro =

Everything above is free, and nothing in this plugin is locked. Tagwright SEO Pro will be a separate plugin with advanced analysis, a link manager, more schema types, Search Console data, site audits, an AI assistant and tools for WooCommerce, local businesses, video, news, podcasts and agencies. See Tagwright SEO > Tagwright SEO Pro inside the plugin.

= About the author =

Tagwright SEO is made by Mozayyan Abbas (pronounced Muzaiyan), known as Mozi.

== External services ==

Tagwright SEO only contacts outside services when you switch on the Instant Indexing module, and only with the public URLs of your own content. Nothing is sent while your site is set to discourage search engines.

= IndexNow =

IndexNow is an open protocol that lets a site tell search engines its content has changed. It is used so new, updated and deleted posts are found quickly.

* What is sent: the changed post's URL, your site's host name, your IndexNow key and the address of the key file on your site.
* When: each time a post is published, updated or deleted, and when you submit URLs by hand on the Instant Indexing screen. Only while the IndexNow option is on.
* Where: https://api.indexnow.org/indexnow, which shares the URLs with the search engines taking part in IndexNow (such as Microsoft Bing, Yandex, Seznam and Naver).
* About the service: [IndexNow](https://www.indexnow.org/), [documentation](https://www.indexnow.org/documentation).
* Terms and privacy: [Microsoft Services Agreement](https://www.microsoft.com/servicesagreement), [Microsoft Privacy Statement](https://privacy.microsoft.com/privacystatement), [Yandex legal documents](https://yandex.com/legal/).

= Google Indexing API =

Used to tell Google about new, updated and deleted pages. It is off by default and only works after you add your own Google Cloud service account key.

* What is sent: a token request signed with your service account key to https://oauth2.googleapis.com/token, then the page URL and the type of change (updated or deleted) to https://indexing.googleapis.com/v3/urlNotifications:publish.
* When: each time a post is published, updated or deleted, and when you submit URLs by hand. Only while the Google option is on.
* Terms and privacy: [Google Terms of Service](https://policies.google.com/terms), [Google Privacy Policy](https://policies.google.com/privacy), [Google APIs Terms of Service](https://developers.google.com/terms), [API documentation](https://developers.google.com/search/apis/indexing-api/v3/quickstart).

== Installation ==

1. Upload the plugin to the `/wp-content/plugins/` directory, or install it from the Plugins screen.
2. Activate it.
3. Follow the setup wizard, or go to Tagwright SEO > Settings.

== Frequently Asked Questions ==

= Will it slow my site down? =

No. Every page needs one indexed lookup for its SEO data, and features you switch off load no code.

= Can I move from Yoast SEO, Rank Math or All in One SEO? =

Yes. Go to Tagwright SEO > Tools and click Import. The other plugin's data is copied, not moved, so you can always switch back.

= Where is my sitemap? =

At /sitemap_index.xml by default. You can rename it under Settings > Sitemaps. If it shows a 404, go to Tagwright SEO > Tools and click Refresh permalinks.

= Can I run the setup wizard again? =

Yes, any time. Click "Setup wizard" in the Tagwright SEO header or under Tools. Your current settings are filled in.

= What happens to my data if I delete the plugin? =

It stays, unless you switch on "Remove all data when the plugin is deleted" under Settings > Technical.

= Does it work with a headless front end? =

Yes. Posts and terms in the REST API include a `tagwright_seo` field with the resolved title, description, robots, canonical, social tags, schema and ready-made head HTML.

== Screenshots ==

1. The dashboard with score trends and the content to-do list.
2. Settings with live module switches.
3. The editor box with search preview and content analysis.

== Changelog ==

= 1.5.1 =
* Improved: new Tagwright SEO icon in the admin menu, the toolbar, the plugin header and the setup wizard (replaces the old "M" mark).
* Improved: with a white-label menu icon set, the toolbar shows that icon too.

= 1.5.0 =
* New: `tagwright_seo_metabox_schema_panel` and `tagwright_seo_metabox_social_panel` actions, so add-ons can add settings to the Schema and Social tabs of the editor box.
* New: custom sitemap providers can declare XML namespaces and add their own elements to each URL (used by video and news sitemaps).
* New: settings fields of type `html` with a `sanitize` callback store a value, so add-ons can draw their own editors in the settings screen.
* New: `tagwright_seo_brand` filter for the admin logo, menu icon, version, credits and Add-ons/Pro screens (white label).
* Improved: no more scrollbars in the plugin's menus. The header puts pages that don't fit under a "More" button, settings sections become a searchable dropdown on narrow screens, and tab rows that don't fit get arrows and a fade. All work with the keyboard and screen readers.
* Improved: the header menu keeps its height while the page loads (a shimmer stands in for the links), so nothing jumps.
* Improved: translation template (languages/tagwright-seo.pot) included, and every text with a number or name in it has a note for translators.
* Security: admin screens escape quotes in all shown values; redirect rules using $1 can no longer send visitors to another site; .htaccess editing needs an administrator; secret keys are no longer sent to non-admin users' browsers; the term keyphrase check respects term permissions.
* Fixed: removing the category base now works with permalink prefixes (such as /blog/) and non-Latin category names.
* Fixed: sitemaps and robots.txt work with plain permalinks.
* Fixed: image sitemaps and attachment settings update the sitemaps straight away; attachment pages with offloaded media redirect to the file; %excerpt_only% stays empty on password-protected posts; social titles no longer replace variables twice; a deleted "person" user no longer causes warnings; redirect CSV export quotes semicolons; Esc in the redirect type list only closes the list.
* Improved: llms.txt loads with far fewer database queries.
* Changed: the Social and Advanced tabs of the editor box follow the `tagwright_seo_metabox_tabs` list, so a tab removed there is no longer printed.

= 1.4.0 =
* New: analysis extension API for Tagwright SEO Pro (custom keyphrase matching, extra checks and adjustable thresholds).

= 1.3.0 =
* New: import redirects from Rank Math, Yoast SEO Premium, Redirection and All in One SEO Pro.
* New: support for Tagwright SEO Pro modules on the Add-ons and Pro screens.

= 1.2.0 =
* New: Redirects & 404 monitor, Content Blocks, Instant Indexing, Image SEO, Technical Tools and Integrations, built in and free. Switch each one on or off under Settings > Modules.
* New: Tagwright SEO Pro screen comparing the free plugin with the upcoming Pro plans.

= 1.1.0 =
* New: variable chips with @ and / autocomplete in every title and description field.
* New: live search and social previews on the settings screen.
* New: custom sitemap file name, change frequency, priority and last modified for every URL type, per-item overrides, comment-based priority, monthly archives, additional URLs and gzip sitemaps.
* New: run the setup wizard again from the header or the Tools screen.

= 1.0.0 =
* First release.
