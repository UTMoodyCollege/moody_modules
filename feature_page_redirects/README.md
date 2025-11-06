# Feature Page Redirects

## Overview

This module implements automatic redirects for `moody_feature_page` content type nodes when they have matching redirects configured via the Redirect module.

## Purpose

The module allows content editors to:
- Create `moody_feature_page` nodes that appear in Views
- Configure redirects for those nodes using the Redirect module
- Have the redirect honored when users view the node directly
- Continue to edit the node normally (edit pages are not affected)
- Remove redirects to restore normal node viewing

## How It Works

When a user attempts to view a `moody_feature_page` node in full display mode:
1. The module checks if a redirect exists for the node's path
2. If a redirect is found, it honors that redirect and sends the user to the configured destination
3. If no redirect exists, the node displays normally
4. Edit pages (`/node/{nid}/edit`) are never redirected, allowing normal content management

## Use Cases

This is particularly useful for:
- Feature pages that should redirect to external articles or resources
- Content that needs to be included in listing views but redirect when clicked
- Temporary redirects that can be easily removed to restore normal viewing

## Dependencies

- Drupal Core Node module
- Redirect module
- Moody Feature Page module

## Installation

1. Enable the module: `drush en feature_page_redirects -y`
2. The module works automatically once enabled

## Usage

1. Create or edit a `moody_feature_page` node
2. Create a redirect (admin/config/search/redirect) with:
   - **From**: The path to your node (e.g., `/node/123` or the alias)
   - **To**: Your desired destination
   - **Status code**: Choose the appropriate redirect type (301, 302, etc.)
3. View the node - it will redirect to your configured destination
4. Edit the node - edit pages work normally without redirecting
5. To stop redirecting, simply delete the redirect

## Testing

The module includes comprehensive functional tests that verify:
- Redirects are honored when viewing nodes
- Edit pages continue to work without redirecting
- Nodes without redirects display normally
- Removed redirects restore normal viewing

Run tests with:
```bash
vendor/bin/phpunit modules/custom/moody_modules/feature_page_redirects/tests
```
