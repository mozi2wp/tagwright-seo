# Tagwright SEO add-on developer guide

How to build add-ons for Tagwright SEO. It covers everything an add-on needs from the core: how it starts up, settings, the editor box, data storage, front end output, the dashboard, the UI kit and how to release.

Core version this guide matches: **1.5.0**. The core source in `wp-content/plugins/tagwright-seo` is the final reference if anything here disagrees with it.

---

## 1. Principles

1. **The core stays lean.** Free features ship inside the core as switchable modules (section 2.1). Paid features live in one separate plugin, Tagwright SEO Pro. Third-party add-ons are their own WordPress plugins. All of them require the core and never copy core code.
2. **Fast by default.**
   - No post meta.
   - Small per-object values go in the core objects table.
   - Data that grows on its own gets its own indexed table.
   - Nothing loads while the module is switched off.
3. **One look.** Every screen uses the core design system: indigo #4F46E5, teal #14B8A6, `.tagwright-*` components and `TagwrightSEO.*` JavaScript. Add-ons don't ship CSS frameworks, icon fonts or their own form styles.
4. **One settings store.** Add-on settings live in the core option `tagwright_seo_settings`, under keys prefixed with the add-on id. The core shows them, validates them, exports them and imports them.
5. **WordPress.org quality.**
   - WordPress Coding Standards, PHP 8.0+ and WP 6.5+.
   - Comments written by a person, for people.
   - Everything escaped, sanitized, nonce-checked and capability-checked.
   - Fully translatable.

---

## 2. Where new code goes

### 2.1 Built-in free features

The free features (`redirects`, `blocks`, `indexing`, `image_seo`, `technical_tools`, `integrations`) live in the core under `includes/features/{folder}/`, namespace `TagwrightSEO\Features\{Name}`, assets in `assets/features/{folder}/`, text domain `tagwright-seo`. `TagwrightSEO\Features\Registry` wires them up:

- `Settings::defaults()`, `Settings::module()` and `Settings::tab()` are always available, so a switched-off feature still shows its card and tab.
- `Feature` is only constructed while the module is on. Features with tables also have static `tables()`, `install()`, `maybe_install()` and optionally `uninstall()`.
- REST routes use the core namespace with the feature id as prefix (`tagwright-seo/v1/redirects`).
- Add a new free feature by adding one line to `Registry::FEATURES` and a folder that follows the same contract.

### 2.2 Tagwright SEO Pro

One separate plugin, sold from the website (Freemius), holding every paid module. It registers with `Addons::register( array( 'id' => 'pro', … ) )` and adds its modules with the same filters an add-on uses. Module ids match `TagwrightSEO\Addons::catalogue()`: `analysis`, `links`, `schema_pro`, `social_plus`, `search_console`, `audit`, `ai`, `woocommerce`, `local`, `video`, `news`, `podcast` and `agency` (Agency plan only). The core's Add-ons screen marks a module "Included" once Pro registers it, and the Pro screen shows the licence through the `tagwright_seo_pro_page_status` action. Set the store link with the `tagwright_seo_pro_url` filter.

Pro's own structure (`tagwright-seo-pro`): `TagwrightSEO_Pro\Registry::MODULES` lists modules as id => [namespace, default on, lowest plan], and modules live in `includes/modules/{folder}/` with the same Settings/Feature contract as section 2.1. `TagwrightSEO_Pro\License` is the only class that talks to Freemius (`License::is_valid()`, `plan()`, `allows( $plan )`). Without the Freemius IDs and SDK it runs in development mode: unlocked on local sites, locked elsewhere.

### 2.3 Third-party add-ons

The starter lives in `wp-content/plugins/tagwright-seo-addon-starter`. Create a new add-on from it:

```bash
cd wp-content/plugins
php tagwright-seo-addon-starter/bin/create-addon.php events "Events"
```

This creates `tagwright-seo-events/` with every name changed for you (the table uses `events` as an example id):

| What | Starter | New add-on |
|---|---|---|
| Folder, main file, text domain | `tagwright-seo-addon-starter` | `tagwright-seo-events` |
| PHP namespace | `TagwrightSEO_Addons\Starter` | `TagwrightSEO_Addons\Events` |
| Constants | `TAGWRIGHT_SEO_STARTER_*` | `TAGWRIGHT_SEO_REDIRECTS_*` |
| Add-on id, module id, settings tab, stats source | `starter` | `events` |
| Settings keys | `starter_*` | `events_*` |
| Tables | `{prefix}tagwright_starter_*` | `{prefix}tagwright_events_*` |
| Options, hooks, functions | `tagwright_seo_starter_*` | `tagwright_seo_events_*` |
| REST namespace | `tagwright-seo-starter/v1` | `tagwright-seo-events/v1` |

A slug with dashes becomes underscores in ids, for example `local-events` → `local_events`. Don't reuse an id that the core or Pro already uses (section 2.1 and 2.2).

Then:

1. Activate the new add-on.
2. Check that its tab shows under **Tagwright SEO › Settings › Add-ons**, its card under **Modules**, and a "Events" tab in the editor box.
3. Replace the example code. Delete the parts you don't need (`Installer`, `Rest`, `Frontend`, `Dashboard_Widget`).
4. Fill in `CLAUDE.md` (scope, done, next) and `readme.txt`.

---

## 3. Anatomy

```
tagwright-seo-{slug}/
├── tagwright-seo-{slug}.php       Header, constants, early filters, boot hook
├── uninstall.php                Removes data when the site owner opted in
├── readme.txt                   WordPress.org readme
├── CLAUDE.md                    Scope and status, read by Claude
├── assets/css/admin.css         Only what the core design system lacks
├── assets/js/admin.js           Builds on TagwrightSEO.* helpers
└── includes/
    ├── class-autoloader.php     TagwrightSEO_Addons\{Name}\Foo_Bar → class-foo-bar.php
    ├── class-plugin.php         Registers with the core, loads components
    ├── class-settings.php       Defaults, module card, settings tab
    ├── class-editor-panel.php   Tab in the SEO box (posts and terms)
    ├── class-dashboard-widget.php
    ├── class-frontend.php       <head> tags, schema, sitemap
    ├── class-rest.php           REST routes for admin screens
    └── class-installer.php      Own table, if needed
```

Classes that belong together go in sub-folders that match a sub-namespace: `TagwrightSEO_Addons\Events\Admin\Events_Table` lives in `includes/admin/class-rules-table.php`.

---

## 4. Boot sequence

```
WordPress includes plugin files
  └─ add-on main file
       ├─ add_filter( 'tagwright_seo_default_settings', … )   ← must be here, before the core reads settings
       ├─ add_filter( 'tagwright_seo_modules', … )            ← must be here
       └─ add_action( 'tagwright_seo_loaded', Plugin::boot )
plugins_loaded (priority 5)
  └─ core boots, then fires tagwright_seo_loaded
       └─ Plugin::boot()
            ├─ Addons::register( id, name, version, requires )  → false = core too old, stop
            ├─ add_filter( 'tagwright_seo_settings_tabs', … )     ← always, even with the module off
            ├─ Modules::is_active( id ) ?                       → off = stop, nothing else loads
            └─ load components (admin or front end, REST)
```

- **Dependency:** the `Requires Plugins: tagwright-seo` header stops activation without the core. `Addons::register()` with `requires` (minimum core version) shows an admin notice and returns `false` when the core is older.
- **Early filters:** the core reads its settings while it boots. Defaults and module cards registered later would be missed, which is why they're added at file load. The core also clears its caches after `tagwright_seo_loaded` and on `init`, as a safety net.
- **Admin or front end:** use `is_admin()` to split. REST requests count as the front end.

---

## 5. Naming rules

| Item | Pattern | Example |
|---|---|---|
| Plugin folder and slug | `tagwright-seo-{slug}` | `tagwright-seo-image-seo` |
| Add-on, module, tab id | `{id}` (slug, `_` for `-`) | `image_seo` |
| Settings keys | `{id}_{name}` | `image_seo_alt_template` |
| Objects `extra` keys | `{id}_{name}` | `image_seo_alt` |
| Tables | `{$wpdb->prefix}tagwright_{id}_{thing}` | `wp_tagwright_events_rules` |
| Options (non-settings) | `tagwright_seo_{id}_{name}`, autoload off | `tagwright_seo_events_db_version` |
| Hooks the add-on fires | `tagwright_seo_{id}_{event}` | `tagwright_seo_events_hit` |
| REST namespace | `tagwright-seo-{slug}/v1` | `tagwright-seo-image-seo/v1` |
| Script and style handles | `tagwright-seo-{slug}` | `tagwright-seo-events` |
| Cron hooks | `tagwright_seo_{id}_{task}` | `tagwright_seo_events_prune` |
| Text domain | `tagwright-seo-{slug}` | `tagwright-seo-events` |
| CSS classes | `.tagwright-{id}-{thing}`, only when the core has nothing suitable | `.tagwright-events-hits` |

---

## 6. Settings

### 6.1 Defaults

```php
public static function defaults( $defaults ) {
	$defaults['events_show_past']          = true;
	$defaults['events_days_ahead']   = 30;
	$defaults['modules']['events']       = true;   // module on by default
	return $defaults;
}
```

Read a setting anywhere with `TagwrightSEO\Options::get( 'events_show_past' )` (or `tagwright_seo_get_option()`).

### 6.2 The settings tab

Register it with `tagwright_seo_settings_tabs`. The core renders it, saves it over REST, validates every value by field type and adds it to export and import.

```php
$tabs['events'] = array(
	'title'       => __( 'Events', 'tagwright-seo-events' ),
	'description' => __( 'Event listings and schema.', 'tagwright-seo-events' ),
	'icon'        => 'clock',      // see the icon list in section 12
	'group'       => 'addons',        // side navigation group
	'module'      => 'events',     // shows a "module is off" notice when switched off
	'sections'    => array(
		array(
			'title'     => __( 'Logging', 'tagwright-seo-events' ),
			'icon'      => 'database',
			'accordion' => false,      // true = collapsible card, 'open' => true to start open
			'preview'   => array(),    // optional, see 6.4
			'fields'    => array( /* fields, see 6.3 */ ),
		),
	),
);
```

Groups: `appearance`, `features`, `advanced` and `addons`. Add your own with `tagwright_seo_settings_groups`.

### 6.3 Field types

Every field takes `id`, `type`, `label`, `description` (basic HTML allowed), `default`, `placeholder`, `class`, `wide` (full width), `badge` and `badge_type`, `show_if` and `sanitize` (a callable that replaces the type-based cleaning).

| type | Extra keys | Saved as |
|---|---|---|
| `text`, `url`, `email`, `password` | `suffix`, `prefix_text`, `attrs` | string (URL and email cleaned) |
| `number` | `min`, `max`, `step`, `suffix` | int, clamped |
| `date` | — | `Y-m-d` or `''` |
| `template` | — | string with `%variables%` (variable chips, `@` and `/` autocomplete) |
| `template_textarea` | `rows` | same, multi-line field |
| `textarea`, `code` | `rows` | string |
| `toggle` | `toggle_label` | bool |
| `select` | `options` (value => label) | one of the option keys (custom dropdown; searchable when long) |
| `segmented` | `options` | one of the option keys |
| `radio_cards` | `options` (value => `label`, `description`, `icon`) | one of the option keys |
| `checkboxes` | `options` | array of option keys |
| `image` | `recommended` | attachment id |
| `group` | `fields` (several controls on one row) | each child saved under its own id |
| `repeater` | `columns` (fields), `add_label`, `max_rows` | list of rows; rows with an empty first column are dropped |
| `heading` | — | nothing (section label) |
| `html` | `render` (callable) | nothing (your own markup) |

Template fields must never be cleaned with `sanitize_text_field()`. It deletes `%da`/`%ca` and so breaks `%date%` and `%category%`. Use `TagwrightSEO\Frontend\Replacer::sanitize_template()`. The `template` field types already do this.

Conditional rows: `'show_if' => array( 'field' => 'events_show_past', 'checked' => true )`. Other forms:
- `'value' => 'x'`
- `'value' => array( 'a', 'b' )`
- `'not' => 'x'`
- `'prefix' => 'tagwright_seo'` for fields in the editor box.

### 6.4 Live previews

A section can show a live search or social preview of its templates:

```php
'preview' => array(
	'type'        => 'serp',                                     // or 'social'
	'context'     => array( 'kind' => 'post_type', 'name' => 'product' ),
	'title'       => array( 'woocommerce_title', 'pt_product_title' ), // first non-empty setting wins
	'description' => array( 'pt_product_description' ),
	'image'       => array( 'og_default_image_id' ),            // social only
),
```

Context kinds: `home`, `post_type`, `post_type_archive`, `taxonomy`, `author`, `date`, `search` and `not_found`. The preview uses a real item from the site as the example.

---

## 7. Modules

```php
$modules['events'] = array(
	'title'       => __( 'Events', 'tagwright-seo-events' ),
	'description' => __( 'One sentence about what it does.', 'tagwright-seo-events' ),
	'icon'        => 'clock',
	'settings'    => 'events',                       // tab id the "Settings" link opens
	'addon'       => __( 'Add-on', 'tagwright-seo-events' ), // badge on the card
);
```

- `TagwrightSEO\Modules::is_active( 'events' )` tells you whether it's switched on.
- `tagwright_seo_module_toggled` fires on change with `( $id, $active )`.
- Switching any module on or off flushes rewrite rules on the next request.

---

## 8. Editor box (posts and terms)

```php
add_filter( 'tagwright_seo_metabox_tabs', function ( $tabs, $args ) {
	// $args['object_type'] is 'post' or 'term'; $args['post_type'] for posts.
	$tabs['events'] = __( 'Events', 'tagwright-seo-events' );
	return $tabs;
}, 10, 2 );

add_action( 'tagwright_seo_metabox_panel_events', function ( $row, $args ) {
	TagwrightSEO\Admin\UI::field( array(
		'id'     => 'events_date',
		'prefix' => TagwrightSEO\Admin\Metabox::PREFIX,   // → name="tagwright_seo[events_date]"
		'type'   => 'url',
		'label'  => __( 'Event date', 'tagwright-seo-events' ),
		'value'  => $row['extra']['events_date'] ?? '',
	) );
}, 10, 2 );

// The core has already checked the nonce and edit permission before these fire.
add_action( 'tagwright_seo_metabox_saved', function ( $post_id, $input ) { /* save */ }, 10, 2 );
add_action( 'tagwright_seo_term_saved', function ( $term_id, $taxonomy, $input ) { /* save */ }, 10, 3 );
```

`$input` is the unslashed `tagwright_seo[...]` array. Sanitize your own keys. The box is the same in the block editor, the classic editor and on term screens.

---

## 9. Data

### 9.1 Where data goes

| Data | Store | API |
|---|---|---|
| A few values per post, term or author | Core objects table, JSON `extra` column | `Objects::get_extra()` / `set_extra()` |
| Site-wide settings | Core settings option | `Options::get()`, settings tab |
| Lists that grow (logs, rules, indexes, queues) | Own table `{prefix}tagwright_{id}_*` | `Installer`, `$wpdb` with `prepare()` |
| Daily numbers for charts | Core stats table, your `source` | `Stats::record()` / `Stats::series()` |
| Temporary state (job progress) | Option with autoload off | `update_option( …, false )` |
| Never | Post meta, term meta, transients for permanent data | — |

### 9.2 Objects API (`TagwrightSEO\Data\Objects`)

- `get( $type, $id )` returns the row (cached) or `null`. `$type` is `post`, `term` or `user`.
- `get_many( $type, $ids )` loads rows in one query and primes the cache. Use it in list tables.
- `save( $type, $id, $fields )` writes only the fields you pass.
- `get_extra( $type, $id, $key, $default )` / `set_extra( $type, $id, $key, $value )`. `null` removes the key.
- Hooks: `tagwright_seo_object_saved` `( $type, $id, $fields )` and `tagwright_seo_object_deleted` `( $type, $id )`.

Useful row columns:
- `permalink`, `is_public`, `post_status`, `object_sub_type` (post type or taxonomy)
- `title`, `description`, `focus_keyphrase`, `canonical`, `robots_*`
- `seo_score`, `readability_score`
- `sitemap_exclude`, `sitemap_priority`, `sitemap_changefreq`
- `images` (array), `comment_count`, `published_at`, `modified_at` (GMT), `extra` (array)

Don't add columns to the core table from an add-on. If `extra` isn't enough, the data belongs in your own table.

### 9.3 Own tables

Use the starter's `Installer`:
- `dbDelta`, with two spaces after `PRIMARY KEY`.
- A version option, plus `maybe_upgrade()` on `init`.
- Network-wide activation and `wpmu_drop_tables`.

Index every column you filter or sort by. Remove old rows on a schedule with a retention setting. Drop the table in `uninstall.php`, only when the core setting `delete_data_on_uninstall` is on.

### 9.4 Stats

```php
add_action( 'tagwright_seo_daily_stats', function () {
	TagwrightSEO\Data\Stats::record( 'hits', $count, '', 'events' );   // metric, value, dimension, source
} );
$series = TagwrightSEO\Data\Stats::series( 'hits', 30, 'events' );   // [ ['date' => 'Y-m-d', 'value' => float|null], … ]
```

The core keeps 400 days of stats (filter `tagwright_seo_stats_retention_days`).

---

## 10. Front end

### 10.1 Context

Every front end hook receives a `TagwrightSEO\Frontend\Context` for the current page (`Context::current()`):

- `$context->type` is one of `singular`, `front_page`, `home`, `posts_page`, `term`, `author`, `post_type_archive`, `date`, `search` or `not_found`.
- `$context->object` is the `WP_Post` / `WP_Term` / `WP_User` / `WP_Post_Type`. `$context->row` is its objects row, with `extra`.
- `get_title()`, `get_description()`, `get_keywords()`, `get_canonical()`, `get_robots()`, `is_noindex()`, `is_front()`, `paged`.

Use the context instead of calling conditional tags yourself.

### 10.2 Tags in `<head>`

`add_action( 'tagwright_seo_head', $callback, 40 )` prints inside the Tagwright SEO block. Core priorities:
- 5 description
- 6 keywords
- 8 canonical
- 9 verification
- 20 social
- 30 schema

Add-ons use 40 or later. Escape every value.

Filters to change core output:
- `tagwright_seo_title`, `tagwright_seo_description`, `tagwright_seo_keywords`, `tagwright_seo_canonical`, `tagwright_seo_robots`
- `tagwright_seo_social_tags`, `tagwright_seo_og_type`, `tagwright_seo_social_image_candidates`

### 10.3 Schema

`add_filter( 'tagwright_seo_schema_graph', function ( $graph, $context ) { … }, 10, 2 )` appends pieces to the one JSON-LD graph. Link to core pieces by `@id`:
- `{page url}#webpage`
- `{page url}#article`
- `{page url}#primaryimage`
- `{page url}#breadcrumb`
- `{home}#organization`, or the person id when the site represents a person
- `{home}#website`

More filters:
- `tagwright_seo_schema_page_types` and `tagwright_seo_schema_article_types` add choices to the dropdowns.
- `tagwright_seo_breadcrumbs` changes the trail; `tagwright_seo_get_breadcrumbs()` reads it for display.

### 10.4 Sitemaps

```php
add_filter( 'tagwright_seo_sitemap_providers', function ( $providers ) {
	$providers['video'] = array(
		'kind'     => 'custom',
		'name'     => 'video',
		'callback' => function () {
			return array(
				'count' => $total,
				'urls'  => function ( $page, $per_page ) {
					return array( array( 'loc' => …, 'lastmod' => 'Y-m-d H:i:s (GMT)', 'changefreq' => 'weekly', 'priority' => '0.6', 'images' => array() ) );
				},
			);
		},
	);
	return $providers;
} );
```

The core handles everything else:
- the URL (`/video-sitemap.xml`, `/video-sitemap2.xml` and so on)
- the index entry
- the lastmod, changefreq and priority switches
- the XSL view and gzip

Change core URLs with `tagwright_seo_sitemap_urls` and image lists with `tagwright_seo_post_images`.

### 10.5 Template variables

Add a variable with three filters:
- `tagwright_seo_replace_variable` returns the value. Return `null` for variables that aren't yours.
- `tagwright_seo_variable_descriptions` sets the text in the picker.
- `tagwright_seo_variable_labels` sets the chip label.

Custom fields come for free with `%customfield(key)%`. Themes that store data elsewhere use `tagwright_seo_custom_field_value`.

---

## 11. Admin pages and the dashboard

### 11.1 Own admin page

Only add one when a settings tab isn't enough, for example a events table or a 404 log.

```php
add_filter( 'tagwright_seo_admin_pages', function ( $pages ) {
	$pages['tagwright-seo-events'] = array(
		'title'      => __( 'Events', 'tagwright-seo-events' ),
		'menu_title' => __( 'Events', 'tagwright-seo-events' ),
		'icon'       => 'clock',
		'callback'   => array( Events_Page::class, 'render' ),
	);
	return $pages;
} );
```

The page shows in the Tagwright SEO menu and the top navigation. Render it like the core pages:

```php
echo '<div class="tagwright-app">';
TagwrightSEO\Admin\UI::header( 'tagwright-seo-events' );
echo '<div class="tagwright-page"> … cards … </div></div>';
```

### 11.2 Dashboard

- **Widget:** `tagwright_seo_dashboard_widgets`, with `title`, `subtitle`, `icon`, `area` (`full`, `main` or `side`), `priority` and `render`. The id `search_performance` replaces the Search Console placeholder; only the Search Console add-on uses it.
- **Data:** `tagwright_seo_dashboard_data` `( $data, $days )`. Add your series under your id, and extra KPI tiles to `$data['kpis']` (`label`, `icon`, `value`, `spark`, `url`).
- **Drawing:** listen for `tagwright-seo:dashboard` in JavaScript. `event.detail` is the whole response. It fires again whenever the date range changes.
- **Quick links:** `tagwright_seo_dashboard_links`.

---

## 12. UI kit

### PHP (`TagwrightSEO\Admin`)
- `UI::field( $args )` prints a full row; `UI::control( $args )` prints just the control. Same field types as section 6.3.
- `UI::notice( 'info'|'success'|'warning'|'error', $message, $title )`.
- `UI::score_badge( $score )` and `UI::header( $page_slug )`.
- `Icons::render( $name, $size )` / `Icons::get()`. Add icons with `tagwright_seo_icons`.

Icon names: `logo`, `dashboard`, `settings`, `tools`, `addons`, `search`, `title`, `share`, `code`, `sitemap`, `analysis`, `robot`, `globe`, `link`, `image`, `check`, `check-circle`, `x`, `alert`, `info`, `chevron-down`, `chevron-right`, `arrow-right`, `plus`, `external`, `refresh`, `download`, `upload`, `database`, `file`, `tag`, `user`, `home`, `eye`, `eye-off`, `sparkles`, `trend`, `layers`, `shield`, `zap`, `copy`, `trash`, `clock`, `key`, `pen`, `book`, `cart`, `pin`, `video`, `news`, `mic`, `redirect`, `blocks`, `keyword`.

### CSS classes
Scope everything under `.tagwright-app`, `.tagwright-metabox` or `.tagwright-scope`.

- Layout: `.tagwright-page`, `.tagwright-page-title`, `.tagwright-grid--2/3/4`, `.tagwright-dashboard-grid`
- Cards: `.tagwright-card`, `__header`, `__title`, `__subtitle`, `__body` (`--padded`), `__footer`
- Buttons: `.tagwright-btn` with `--primary`, `--accent`, `--secondary`, `--ghost`, `--danger`, `--light`; sizes `--sm`, `--lg`, `--icon`; state `.is-busy`
- Feedback: `.tagwright-badge--info/accent/good/ok/bad/muted`, `.tagwright-notice--*`, `.tagwright-empty`, `.tagwright-progress > span`, `.tagwright-skeleton`
- Data: `.tagwright-table` (`.is-num`), `.tagwright-kpis`/`.tagwright-kpi`, `.tagwright-issues`, `.tagwright-modules`/`.tagwright-module`
- Tokens: `var(--tagwright-primary)`, `--tagwright-accent`, `--tagwright-ink`, `--tagwright-muted`, `--tagwright-line`, `--tagwright-good`, `--tagwright-ok`, `--tagwright-bad`, `--tagwright-radius`, `--tagwright-shadow` and more. Use tokens, never raw colours.

### JavaScript (`window.TagwrightSEO`, handle `tagwright-seo-admin`)
- `TagwrightSEO.api( path, options )`: `'settings'` goes to the core, `'/tagwright-seo-events/v1/items'` goes to the add-on. The nonce is handled.
- `TagwrightSEO.toast( message, 'success'|'error' )`.
- `TagwrightSEO.ui.init( element )` enhances fields added after page load (dropdowns, chips, media, repeaters, tabs, `show_if`).
- `TagwrightSEO.charts.line / donut / bars / sparkline`. Colours follow the series in a fixed order: `TagwrightSEO.charts.PALETTE`, `STATUS`.
- `TagwrightSEO.replaceVariables( template, values, context )`, `TagwrightSEO.chips`, `TagwrightSEO.serializeSettings( form )`, `TagwrightSEO.icon( name, size )`, `TagwrightSEO.escapeHtml()`.
- Events: `tagwright-seo:ready`, `tagwright-seo:dashboard`, `tagwright-seo:analysis` (editor, `detail` is the analysis result), `tagwright-seo:custom-field`. Dispatch `tagwright-seo:reanalyze` on `document` to make the editor box run the analysis again.

Enqueue your script with `array( 'tagwright-seo-admin', 'wp-i18n' )` as dependencies, on `admin_enqueue_scripts` at priority 20 or later, and only where `tagwright-seo-admin` is enqueued.

### Analysis extensions (`window.TagwrightSEOAnalysis`, handle `tagwright-seo-analysis`, since 1.4.0)

The content analysis runs in the browser while people write. Extensions change how it matches keyphrases, add data to its input and add their own checks, without copying core code. With nothing registered, the analysis behaves exactly as in 1.3.0.

Load your script only where the core loads the analysis (post edit screens with analysis switched on), with `tagwright-seo-analysis` and `tagwright-seo-editor` as dependencies so it runs after the editor box is wired up. After registering, dispatch `tagwright-seo:reanalyze` so the first result includes your changes.

```js
const A = window.TagwrightSEOAnalysis;

// 1. Matching. Replaces how the keyphrase is counted in every keyphrase
//    check. Return a number; anything else (or an exception) falls back to
//    the core's exact matcher. `where` is the part of the page:
//    title, description, slug, intro, body, headings or alt.
A.setMatcher( ( text, keyphrase, { where, input, exact } ) => {
	if ( ! /^en/i.test( input.locale || '' ) ) {
		return exact( text, keyphrase );
	}
	return myStemmedCount( text, keyphrase );
} );

// 2. Input filters run on a copy of the input before any check. Change it
//    in place or return a new object.
A.addInputFilter( ( input ) => {
	input.cornerstone = isCornerstone();
	if ( input.cornerstone ) {
		input.thresholds = Object.assign( {}, input.thresholds, { textMin: 900, textGood: 900, internalLinksMin: 3 } );
	}
} );

// 3. Check providers. Checks count towards the score of their group:
//    { group: 'seo' } (default) or { group: 'readability' }.
A.addCheckProvider( ( input, h ) => {
	const found = findSomething( h.stripTags( input.content ) );
	return [ h.check( 'my_check', found ? 'good' : 'ok', h.__( 'Message', 'my-domain' ), 'content' ) ];
}, { group: 'readability' } );

// Providers can also return results of their own through `h.extra`,
// which comes back as `result.extra`.
A.addCheckProvider( ( input, h ) => {
	h.extra.myScores = [ h.score( h.seoChecks( Object.assign( {}, input, { keyphrase: 'other phrase' } ) ) ) ];
	return [];
} );
```

- **Checks** are `{ id, status, text, group }`. `status` is `good`, `ok` or `poor`; `group` is only a label (`basics`, `content`, `links`, `readability`). Invalid checks are dropped. Text is escaped when shown, so pass plain text.
- **Thresholds** are read from `input.thresholds` on every analysis, merged over the defaults in `TagwrightSEOAnalysis.thresholds`: `textMin` (300), `textGood` (600), `densityMin` (0.5), `densityMax` (3) and `internalLinksMin` (1). Set them in an input filter, so they only apply to the post being edited.
- **Helpers** passed to providers (`h`): `__`, `_n`, `sprintf`, `check`, `score`, `words`, `sentences`, `paragraphs`, `stripTags`, `normalize`, `syllables`, `escapeRegExp`, `countKeyphrase` (exact), `matchKeyphrase( text, keyphrase, where )` (through the matcher), `seoChecks( input )` (the core SEO checks for any input, e.g. another keyphrase), `thresholds` and `extra`.
- **Result:** `analyze()` returns `{ seo: { score, checks }, readability: { score, checks } }`, plus `extra` only when an extension wrote to it. The `tagwright-seo:analysis` event carries the same object.
- **Safety:** every extension call is wrapped. A matcher, filter or provider that throws is logged to the console and skipped; the core checks still run.
- `TagwrightSEOAnalysis.countKeyphrase()` stays the exact core matcher. Use `matchKeyphrase()` when you want the registered one.
- Only one matcher is active. Registering another replaces it, and `setMatcher( null )` restores the core one.

---

## 13. REST

- Namespace `tagwright-seo-{slug}/v1`.
- Every route has a `permission_callback`. Use `TagwrightSEO\Admin\Admin::capability()` for settings-level actions, and `edit_post` / `edit_term` checks for object-level ones.
- Declare `args` with `type`, `sanitize_callback` and `validate_callback`.
- Return `WP_Error` with a status code and a translated message. `TagwrightSEO.api()` shows it in a toast.
- Long jobs run in batches (`offset` in, `{status, offset, total, percent}` out). The browser keeps calling until `status` is `complete`, like the core importers do.

---

## 14. Hook reference (core)

**Actions:**
- `tagwright_seo_loaded`, `tagwright_seo_head`
- `tagwright_seo_settings_updated` `( $changed )`, `tagwright_seo_module_toggled` `( $id, $active )`
- `tagwright_seo_metabox_saved`, `tagwright_seo_term_saved`, `tagwright_seo_metabox_panel_{tab}`, `tagwright_seo_metabox_schema_panel` and `tagwright_seo_metabox_social_panel` (end of the Schema and Social tabs, 1.5.0)
- `tagwright_seo_object_saved`, `tagwright_seo_object_deleted`
- `tagwright_seo_daily`, `tagwright_seo_daily_stats`, `tagwright_seo_index_complete`

**Filters:**

| Area | Filters |
|---|---|
| Setup | `tagwright_seo_default_settings`, `tagwright_seo_modules`, `tagwright_seo_capability` |
| Settings | `tagwright_seo_settings_tabs`, `tagwright_seo_settings_groups` |
| Admin pages and assets | `tagwright_seo_admin_pages`, `tagwright_seo_icons`, `tagwright_seo_addon_catalogue`, `tagwright_seo_pro_url` |
| Editor | `tagwright_seo_metabox_tabs`, `tagwright_seo_metabox_supported` |
| Dashboard | `tagwright_seo_dashboard_widgets`, `tagwright_seo_dashboard_data`, `tagwright_seo_dashboard_links` |
| Head output | `tagwright_seo_context`, `tagwright_seo_title`, `tagwright_seo_description`, `tagwright_seo_keywords`, `tagwright_seo_canonical`, `tagwright_seo_robots` |
| Social | `tagwright_seo_og_type`, `tagwright_seo_social_tags`, `tagwright_seo_social_image_candidates` |
| Schema and breadcrumbs | `tagwright_seo_schema_graph`, `tagwright_seo_schema_page_types`, `tagwright_seo_schema_article_types`, `tagwright_seo_breadcrumbs` |
| Sitemaps | `tagwright_seo_sitemap_providers`, `tagwright_seo_sitemap_urls`, `tagwright_seo_sitemap_entries`, `tagwright_seo_post_images` |
| Variables | `tagwright_seo_replace_variable`, `tagwright_seo_variable_descriptions`, `tagwright_seo_variable_labels`, `tagwright_seo_custom_field_value` |
| Indexing | `tagwright_seo_indexable_post_types`, `tagwright_seo_index_batch_size` |
| Editor analysis | `tagwright_seo_builder_content` (page builder text for the analysis, Integrations feature) |
| Redirects feature | actions `tagwright_seo_redirects_added`, `tagwright_seo_redirects_hit`; API `TagwrightSEO\Features\Redirects\Repository::add( $source, $target, $status, $match_type )` |
| Other | `tagwright_seo_stats_retention_days`, `tagwright_seo_llms_txt`, `tagwright_seo_attachment_redirect`, `tagwright_seo_importers`, `tagwright_seo_rest_fields_enabled`, `tagwright_seo_person_limit` |

When an add-on needs a hook the core doesn't have yet, add it to the core in a small, documented change. Don't work around it.

---

## 15. Security and quality checklist

- [ ] Every file starts with `defined( 'ABSPATH' ) || exit;` (except `uninstall.php` and the CLI tool).
- [ ] Output escaped late: `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`, `wp_json_encode` for JSON.
- [ ] Input sanitized early, by type. Templates use `Replacer::sanitize_template()`.
- [ ] Every form has a nonce. Every action and route checks a capability.
- [ ] Every SQL value goes through `$wpdb->prepare()`. Table names come from code, never from input.
- [ ] Strings are translatable with the add-on's text domain. Strings with placeholders have `/* translators: */` comments.
- [ ] No inline `<script>` with data. Pass data with `wp_add_inline_script()` / `wp_localize_script()`.
- [ ] No external requests unless that's the add-on's purpose (and then disclosed in readme.txt).
- [ ] Nothing loads while the module is off. Admin assets load only on screens that use them.
- [ ] Works with multisite: tables per site, network activation, `wpmu_drop_tables`.

---

## 16. Testing and release

**Test on the dev site:**
1. Activate with the core active. Then try activating without the core (it should be blocked), and with an older core (a notice should appear).
2. Settings tab: save, reload, export and import. Check the "module is off" state.
3. Editor tab on a post, a page, a custom post type and a category. Save and reload.
4. Front end output with the module on and off. View the page source.
5. Dashboard widget with 7, 30, 90 and 365 days.
6. Uninstall with "Remove all data" off (data kept) and on (data removed).
7. Run the **Plugin Check** plugin. Fix all errors and most warnings.

**Release:**
- Bump the version in the header, the version constant and `Stable tag`. Bump the DB version when the schema changes.
- Set `TAGWRIGHT_SEO_{ID}_REQUIRES` to the lowest core version the add-on works with.
- Update `readme.txt`: description, FAQ, changelog, `Tested up to`.
- Make sure the release zip leaves out `CLAUDE.md`, `bin/`, `docs/` and tests (see `.distignore`).

---

## 17. Renaming the brand

`tagwright-seo/bin/rename-brand.php` rewrites every spelling of the name (display name, slug, text domain, namespace, constants, hooks, option, table and CSS prefixes) into renamed copies of the folders you pass, leaving "classic editor"-style words alone:

```bash
php tagwright-seo/bin/rename-brand.php "Tagwright SEO" tagwright "New Name SEO" nn tagwright-seo tagwright-seo-addon-starter
```

After a public release a rename also needs a migration for the option and table names.

## 18. Working with Claude

- One Claude project, **Tagwright SEO**, covers the core, Pro and every add-on. This guide and `claude/tagwright-seo-architecture.md` live there.
- For a session on an add-on, connect the add-on's folder (where the work happens) and `tagwright-seo` (read as the reference).
- Keep the add-on's `CLAUDE.md` current (scope, done, next). A new session reads it first.
- When an add-on needs a core change (a new hook, a field type), make it in the core first, bump the core version and raise the add-on's `requires`.
