# Tagwright SEO

Fast, lightweight SEO for WordPress — meta tags, social cards, schema, XML sitemaps, redirects and content analysis, built on dedicated database tables so your site stays quick.

![Version](https://img.shields.io/badge/version-1.5.0-2271b1)
![WordPress](https://img.shields.io/badge/WordPress-6.5%2B-21759b)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4)
![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)

> This repository holds the source of the free plugin. The release on WordPress.org is built from it. The user-facing description, FAQ and changelog live in [`readme.txt`](readme.txt).

## Why Tagwright SEO

Most SEO plugins scatter their data across dozens of post meta rows. Tagwright SEO keeps every SEO field for a post, term or author in **one row of its own indexed table**: reading a page's SEO data is a single lookup, sitemaps are built with one query, and the dashboard can summarise thousands of posts in milliseconds.

Every feature is a module. Switched-off modules load no code at all, and nothing in the free plugin is locked.

## Features

| Area | Highlights |
| --- | --- |
| **Search appearance** | Title and description templates for every content type, 25+ variables, per post/term/author overrides, full robots control, automatic canonicals, live Google preview |
| **Social** | Open Graph and X (Twitter) Cards, default and per-post images, live Facebook and X previews |
| **Schema** | One connected JSON-LD graph per page: Organization/Person, WebSite, WebPage, Article, BreadcrumbList |
| **XML sitemaps** | Custom file name, posts, terms, authors and archives, per-type priority and frequency, image entries, gzip, served before WordPress runs its main query |
| **Content analysis** | Focus keyphrase checks, readability checks, duplicate keyphrase warning — editor only, never on the front end |
| **Dashboard** | Site SEO score, trends, distribution and a to-do list linking to the posts that need work |
| **Redirects & 404 monitor** | 301/302/307/410/451, exact/prefix/regex matching, automatic slug-change redirects, 404 log, CSV import/export |
| **Content blocks** | Breadcrumbs, table of contents, FAQ, How-To, reading time and related posts (blocks + shortcodes) |
| **Instant indexing** | IndexNow (Bing, Yandex and others) and optional Google Indexing API |
| **Image SEO** | Alt and title text from templates, bulk fill for the media library |
| **Technical** | robots.txt editor, llms.txt generator, head cleanup, RSS additions, HTML sitemap, .htaccess editor with backups, category base removal |
| **Integrations** | AMP, Web Stories, bbPress, BuddyPress, Elementor, Divi, Beaver Builder, Bricks, Oxygen |
| **Tools** | Import from Yoast SEO, Rank Math and All in One SEO, settings export/import, setup wizard, WP-CLI, REST API fields for headless sites, WPML/Polylang, multisite |

Paid modules ship in the separate **Tagwright SEO Pro** plugin.

## Requirements

- WordPress 6.5 or later
- PHP 8.0 or later

## Installation

**From WordPress.org** — search for *Tagwright SEO* under Plugins → Add New.

**From this repository**

```bash
cd wp-content/plugins
git clone https://github.com/mozi2wp/tagwright-seo.git
```

Then activate **Tagwright SEO** in WordPress and follow the setup wizard (or go to *Tagwright SEO → Settings*). The plugin has no build step and no Composer or npm dependencies.

## Project structure

```
tagwright-seo/
├── tagwright-seo.php            Plugin bootstrap and constants
├── uninstall.php           Clean removal of tables and options
├── readme.txt              WordPress.org readme (description, FAQ, changelog)
├── wpml-config.xml         WPML / Polylang string config
├── assets/                 Admin and editor CSS/JS, plus per-feature assets
├── includes/
│   ├── admin/              Settings, metabox, dashboard, wizard, tools screens
│   ├── api/                REST controller and REST fields
│   ├── cli/                WP-CLI commands
│   ├── data/               Custom tables: objects, indexer, sync, stats
│   ├── features/           Built-in switchable modules (redirects, blocks, indexing,
│   │                       image-seo, technical-tools, integrations)
│   ├── frontend/           <head> output, variables, social tags, breadcrumbs
│   ├── importers/          Yoast SEO, Rank Math, All in One SEO
│   ├── schema/             JSON-LD graph
│   ├── sitemaps/           XML sitemap router, builder and stylesheet
│   └── technical/          robots.txt, llms.txt, cleanup, category base
├── languages/              tagwright-seo.pot
├── docs/                   Add-on developer guide (not shipped)
└── bin/                    Dev scripts: make-pot, rename-brand (not shipped)
```

## Development

- **Coding standards:** [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/), PHP 8.0+.
- **Data:** custom tables only — no post meta. All settings live in one option.
- **Modules:** each feature has a `Settings` class (defaults, module, tab) and a `Feature` class (hooks), and is only built when its module is on. See `includes/features/class-registry.php`.
- **External requests** are opt-in and must be listed under *External services* in `readme.txt`.
- **Translations:** regenerate the template with `php bin/make-pot.php`.

### Building a release zip

Files listed in `.distignore` (and marked `export-ignore` in `.gitattributes`) stay out of the release:

```bash
wp dist-archive .          # WP-CLI dist-archive command
# or
git archive --format=zip --prefix=tagwright-seo/ -o tagwright-seo.zip HEAD
```

### Building add-ons

Third-party add-ons hook into `tagwright_seo_loaded` and register modules, settings tabs, dashboard widgets and REST routes through the core APIs. The full guide is in [`docs/addon-developer-guide.md`](docs/addon-developer-guide.md).

## External services

Tagwright SEO only contacts outside services when you switch on **Instant Indexing**, and only with the public URLs of your own content (IndexNow and, optionally, the Google Indexing API). Nothing is sent while the site discourages search engines. Details are in `readme.txt`.

## Contributing

Bug reports and pull requests are welcome. Please open an issue first for larger changes, follow the WordPress Coding Standards, and keep new features switchable as modules.

## License

[GPL-2.0-or-later](LICENSE) © Mozayyan Abbas (Mozi) — [Mozi2WP](https://github.com/mozi2wp)
