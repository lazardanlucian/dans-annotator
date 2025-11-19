# Dan's Annotator

Contributors: danl  
Requires at least: 6.0  
Tested up to: 6.8  
Requires PHP: 7.4  
Stable tag: 1.0.1
License: GPLv2 or later  
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight front-end annotation tool with threads, tagging, and collaborator sessions.

## Description

Dan's Annotator lets logged-in users (and invited collaborators) highlight elements on any page and discuss them in threaded comments. It adds a floating UI to create, browse, and close annotation threads, plus @-mentions with email notifications.

### Features

- Custom database tables for threads, comments, tags, and collaborators (created on activation).
- Admin bar toggle to enable/disable annotation mode for logged-in users.
- Front-end badges showing counts and a side panel UI for reading/posting comments.
- @username tagging with autocomplete and email/admin-notice notifications.
- REST API endpoints used by the front-end JavaScript.
- Optional outside-collaborator links with scoped sessions.

## Installation

1. Upload the `dans-annotator` directory to your `wp-content/plugins` folder.
2. Activate the plugin in WP Admin (tables are created on activation).
3. While logged in, use the admin bar toggle to enable annotation mode on the front-end.

## License

This plugin is licensed under the GNU General Public License v2.0 or later.
