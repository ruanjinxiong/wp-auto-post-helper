# WP Auto Post Helper

WP Auto Post Helper adds a usage counter to every media item so you can easily discover your most frequently used assets via the WordPress REST API. The plugin registers a `wpaph_usage_count` meta field on attachments, exposes it through REST responses, and lets you order media queries by that value.

## Features

- 📈 Tracks how many times each attachment is used by storing the count as post meta.
- 🔄 Exposes a `usage_count` field in the media REST API so external tools can read or update the value.
- 🗂️ Enables sorting media library REST requests by usage count using `orderby=usage_count`.

## Installation

1. Upload the plugin files to your `/wp-content/plugins/wp-auto-post-helper` directory.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Make REST requests to `/wp-json/wp/v2/media` as usual and include `orderby=usage_count` to fetch the most used assets first.

## Updating Usage Counts

The `usage_count` field is available when creating or updating media items over the REST API. Increase the value whenever your automation publishes content that uses a given attachment to keep the counts in sync.

## Requirements

- WordPress 6.0 or later
- PHP 7.4 or later
- An account with the `upload_files` capability to modify usage counts via REST

## Development

This repository intentionally keeps the plugin self-contained. Run `composer install` or `npm install` only if you add new dependencies in the future. For linting and testing, follow your project's preferred WordPress development standards.

## License

Distributed under the GPL-2.0-or-later license. See the [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html) file for details.
