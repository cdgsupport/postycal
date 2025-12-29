# PostyCal

**Version:** 2.0.0  
**Requires WordPress:** 6.0+  
**Requires PHP:** 8.2+  
**License:** GPL v3 or later

Automatically manages post category transitions based on ACF date fields. Perfect for events, promotions, announcements, and any content that needs to move between "upcoming" and "past" categories.

## Features

- **Automatic Category Transitions**: Posts automatically move from a "pre-date" category to a "post-date" category when their date passes
- **Multiple Schedules**: Configure different transition rules for different post types
- **ACF Repeater Support**: Works with both single date fields and repeater fields containing multiple dates
- **Flexible Date Logic**: For repeaters, choose between earliest date, latest date, or "any date passed" logic
- **Real-time Updates**: Category is assigned immediately when a post is saved
- **Daily Cron**: Background process runs daily to catch any posts that need transitioning
- **Manual Trigger**: Run all schedules on-demand from the admin panel

## Requirements

- WordPress 6.0 or higher
- PHP 8.2 or higher
- Advanced Custom Fields (ACF) or ACF Pro

## Installation

1. Upload the `postycal` folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress admin
3. Navigate to **Settings → PostyCal** to configure schedules

## Configuration

### Creating a Schedule

1. Go to **Settings → PostyCal**
2. Click **Add New Schedule**
3. Configure the following:

| Field | Description |
|-------|-------------|
| **Schedule Name** | A descriptive name for this schedule |
| **Post Type** | The post type to monitor (e.g., `event`, `post`) |
| **Taxonomy** | The taxonomy to use for categorization |
| **Field Type** | Single Date Field or Repeater Field |
| **ACF Date Field Name** | The ACF field name containing the date |
| **Date Sub-field Name** | (Repeaters only) The date field within the repeater |
| **Date Logic** | (Repeaters only) How to handle multiple dates |
| **Pre-Date Category Slug** | Term slug for future-dated posts |
| **Post-Date Category Slug** | Term slug for past-dated posts |
| **Time-Aware Transitions** | Enable to use time component (for Date/Time Picker fields) |

### Date Logic Options (Repeaters)

- **Use earliest date**: Transition based on the first date in the repeater
- **Use latest date**: Transition based on the last date in the repeater  
- **Transition when any date has passed**: Move to post-date category as soon as any date passes

### Time-Aware Transitions

By default, PostyCal compares dates only and transitions posts at midnight (with a 24-hour buffer). This works well for ACF **Date Picker** fields.

When **Time-Aware Transitions** is enabled:
- Posts transition immediately when the datetime passes
- No buffer period is applied
- Perfect for ACF **Date/Time Picker** fields

**Use Cases for Time-Aware:**
- Flash sales ending at a specific time (e.g., "Sale ends at 11:59 PM")
- Webinars that should move to "past" right after they finish
- Time-sensitive promotions
- Events with specific start/end times

## How It Works

1. **On Post Save**: When a post is saved, PostyCal checks its date field and assigns the appropriate category (pre-date or post-date)

2. **Daily Cron**: Every night at midnight, PostyCal checks all posts in pre-date categories. If their date has passed (plus a 24-hour buffer), they're moved to the post-date category.

3. **Manual Trigger**: Administrators can run all schedules immediately from the settings page.

## Example Use Cases

### Events
- Post Type: `event`
- Taxonomy: `event_status`
- Pre-Date Term: `upcoming`
- Post-Date Term: `past`

### Promotions
- Post Type: `promotion`
- Taxonomy: `promotion_status`
- Pre-Date Term: `active`
- Post-Date Term: `expired`

### Multi-Day Events (Repeater)
- Field Type: Repeater
- Sub-field: `event_date`
- Date Logic: Latest (event stays "upcoming" until final date passes)

## Debugging

Enable debugging by adding to `wp-config.php`:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'POSTYCAL_DEBUG', true ); // Enables verbose logging
```

Logs are written to `wp-content/debug.log`.

## Hooks & Filters

### Constants

| Constant | Default | Description |
|----------|---------|-------------|
| `POSTYCAL_TRANSITION_BUFFER` | `DAY_IN_SECONDS` | Buffer period before transitioning (prevents same-day transitions) |
| `POSTYCAL_DEBUG` | `false` | Enable verbose debug logging |

## Uninstallation

When the plugin is deleted through WordPress:
- All schedule configurations are removed
- Scheduled cron events are cleared
- Posts retain their current category assignments

## Changelog

### 2.0.0
- Complete rewrite with modern PHP practices
- Added proper class structure (Core, Schedule, Schedule_Manager, Date_Handler, Cron_Handler, Admin, Logger)
- Added strict type declarations (PHP 8.2+)
- **Added time-aware transitions** - supports ACF Date/Time Picker fields
- Improved date handling with DateTimeImmutable
- Added comprehensive error logging
- Fixed race condition in term assignment
- Added nonce verification to all AJAX handlers
- Moved JavaScript to external file
- Added proper uninstall handling
- Added export/import capability for schedules
- Improved WordPress coding standards compliance

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
