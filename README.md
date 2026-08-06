# PostyCal

**Version:** 2.2.0  
**Requires WordPress:** 6.0+  
**Requires PHP:** 8.2+  
**License:** GPL v3 or later

Manages the full lifecycle of time-sensitive posts. Give a post a go-live date and an expiration date, and PostyCal publishes it, moves it through a set of taxonomy terms, and retires it — automatically. Built for events, promotions, announcements, and alerts.

No ACF required. PostyCal registers its own date fields, and can create the post types and taxonomies it needs.

## Features

- **Full lifecycle management**: draft → published → private, driven by two dates
- **Three-term model**: posts carry an Upcoming, Active, or Past term at all times
- **Built-in custom fields**: go-live and expiration date fields, no ACF or other dependency
- **Post type & taxonomy builder**: create the CPTs and taxonomies from the settings page, with seed terms created for you
- **Multiple schedules**: different rules for different post types, and more than one schedule per post type
- **Time-aware transitions**: optionally compare exact times instead of whole days
- **Immediate term assignment**: the correct term is applied the moment a post is saved
- **Per-post overrides**: take any single post out of its schedule without touching the schedule itself
- **Manual trigger**: run every schedule on demand from the settings page

## Requirements

- WordPress 6.0 or higher
- PHP 8.2 or higher

## Installation

1. Upload the `postycal` folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress admin
3. Navigate to **Settings → PostyCal**

## Setup

Work through the tabs in order: **Post Types → Taxonomies → Schedules**.

### 1. Post Types

Create the post type your time-sensitive content lives in (or skip this if you'll use an existing one). The slug is fixed once created — it keys every post of that type.

### 2. Taxonomies

Create a taxonomy and assign it to one or more post types. On creation PostyCal seeds three terms (Upcoming, Active, Past by default; the names are yours to change) so the taxonomy is immediately usable by a schedule.

### 3. Schedules

| Field | Description |
|-------|-------------|
| **Schedule Name** | A descriptive name for this schedule |
| **Post Type** | The post type to monitor |
| **Taxonomy** | The taxonomy holding the three lifecycle terms |
| **Upcoming Term** | Assigned while the post is waiting for its go-live date |
| **Active Term** | Assigned once the post is live |
| **Past Term** | Assigned once the post has expired |
| **Time-Aware** | Compare exact times rather than whole days |

Saving a schedule adds a **Publication Schedule** meta box to that post type's editor, with the go-live and expiration date fields.

## How it works

1. **Before go-live** — the post stays a draft and holds the Upcoming term.
2. **On the go-live date** — PostyCal publishes the post, assigns the Active term, and stamps the post date with the go-live date.
3. **After the expiration date** — PostyCal sets the post to private and assigns the Past term.

Dates are interpreted in the site's configured timezone (**Settings → General**), not the server's.

**Day boundaries.** With Time-Aware off, a go-live date is *inclusive* — a post dated today goes live today. An expiration date is *exclusive* — a post stays live for the whole of its expiration day and retires at the end of it.

**On save**, only the term is updated, never the post status. That keeps an editor in control of publishing during an editing session; status changes are left to the scheduled run.

**Scheduled runs** happen daily at local midnight. If any schedule is time-aware, the event automatically switches to hourly, since a midnight-only run cannot honour a configured time of day. The recurrence is reconciled whenever schedules change.

**Manual trigger**: **Run All Schedules Now** on the Schedules tab processes every schedule immediately.

## Per-post overrides

The **Publication Schedule** box on the post editor carries a **Schedule Override** dropdown. It applies to that post only, and to one schedule only — a post covered by two schedules gets an override for each.

| Option | Effect |
|--------|--------|
| **Automatic** | Follow the schedule. The default. |
| **Hold** | PostyCal makes no changes to this post at all — no term, no status. Use this to pull a post back to draft and have it stay there. |
| **Force upcoming** | Pin to draft + the Upcoming term, whatever the dates say. |
| **Force active** | Pin to published + the Active term. Keeps a post live past its expiration date. |
| **Force past** | Pin to private + the Past term. Retires a post ahead of its expiration date. |

Anything other than Automatic ignores the post's dates. The dates stay saved, so clearing the override returns the post to normal lifecycle handling from wherever its dates place it.

The term is applied as soon as you save; as with automatic posts, the status change is left to the next scheduled run.

## Example configurations

### Events
- Post Type: `event`
- Taxonomy: `event_status`
- Terms: `upcoming` / `active` / `past`

### Promotions
- Post Type: `promotion`
- Taxonomy: `promotion_status`
- Terms: `scheduled` / `running` / `expired`
- Time-Aware: on, for sales that end at a specific time

## Debugging

Add to `wp-config.php`:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'POSTYCAL_DEBUG', true ); // Verbose logging
```

Logs are written to `wp-content/debug.log`. Errors and warnings are logged whenever `WP_DEBUG` and `WP_DEBUG_LOG` are on; `POSTYCAL_DEBUG` adds per-post DEBUG lines.

## Constants

| Constant | Default | Description |
|----------|---------|-------------|
| `POSTYCAL_DEBUG` | `false` | Enable verbose debug logging |
| `POSTYCAL_CRON_HOOK` | `pc_daily_category_check` | Name of the recurring transition event |

## Uninstallation

Deleting the plugin through WordPress removes all schedule, post type, and taxonomy configurations, PostyCal's date meta, and the scheduled event — on every site in a multisite network. Posts, taxonomies, and terms are left in place, since they hold your content.

## Releasing

Releases are built and published by GitHub Actions when a `v*` tag is pushed.

```bash
bin/bump-version.sh 2.3.0     # sets the version in postycal.php and README.md
# add a "### 2.3.0" entry to the changelog below, then commit
git tag v2.3.0
git push origin main v2.3.0
```

The workflow lints, builds `postycal-2.3.0.zip`, and publishes it as a GitHub Release with the notes taken from that version's changelog entry. The zip contains a single `postycal/` directory and is installable through **Plugins → Add New → Upload Plugin**.

The build **fails rather than publishes** if the tag and the versions declared in `postycal.php` and `README.md` disagree. Versions are read from the tagged commit, not your working tree, so an uncommitted bump can't ship a mislabelled zip.

To build a zip locally without releasing:

```bash
bin/build-release.sh
```

## Changelog

### 2.2.0
- Added a per-post **Schedule Override** dropdown: hold a post untouched, or pin it to Upcoming / Active / Past regardless of its dates
- Overridden posts are skipped by the date-driven passes and reconciled by their own pass
- The editor notice now confirms an active override instead of warning about unused dates
- Go-live and expiration fields are no longer marked `required`, since an overridden post doesn't need them

### 2.1.0
- Added a post type builder and a taxonomy builder with automatic seed terms
- Replaced the ACF dependency with PostyCal's own date fields — ACF is no longer required
- Moved to a three-term lifecycle (Upcoming / Active / Past) with automatic publish and expire
- Go-live dates are now inclusive: a post dated today goes live today rather than a day late
- Time-aware schedules now run hourly instead of only at midnight
- Published posts are stamped with their go-live date rather than the date the draft was created
- Posts published manually ahead of their go-live date are no longer stranded outside the lifecycle
- A post with only one of the two dates set now still receives a term
- Post type and taxonomy slugs are locked after creation, so existing posts and terms can't be orphaned
- Uninstall now covers every site in a multisite network
- Removed the unused `POSTYCAL_TRANSITION_BUFFER` constant

### 2.0.0
- Complete rewrite with modern PHP practices
- Added proper class structure (Core, Schedule, Schedule_Manager, Date_Handler, Cron_Handler, Admin, Logger)
- Added strict type declarations (PHP 8.2+)
- Added time-aware transitions
- Improved date handling with DateTimeImmutable
- Added comprehensive error logging
- Fixed race condition in term assignment
- Added nonce verification to all AJAX handlers
- Moved JavaScript to external file
- Added proper uninstall handling
- Added export/import capability for schedules

### 1.5.0
- Initial public release

## Credits

Developed by [Crawford Design Group](https://crawforddesigngroup.com/)

## License

This plugin is licensed under the GPL v3 or later.

```
This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
```
