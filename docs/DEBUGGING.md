# Debugging & Crash Recovery (Operator-safe)

This plugin is **frontend-first** and must remain stable. When a crash happens, follow this playbook.

## 1) Emergency recovery (site is white screen)
1. Go to `/wp-content/plugins/`
2. Rename the plugin folder to disable it, e.g. `gttom-phase_DISABLED`
3. Reload the site

## 2) Enable safe WordPress debug logging
Edit `wp-config.php`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
@ini_set('display_errors', 0);
```

Logs are written to:
- `/wp-content/debug.log`

## 3) The #1 rule (prevents constant conflicts)
**Never keep two copies of GT TourOps Manager installed at the same time.**

If you see warnings like:
- `Constant GTTOM_VERSION already defined`

…then multiple copies of the plugin are present. Remove/disable the older folders.

## 4) What to paste into chat when reporting a crash
Paste:
- the **first** `PHP Fatal error:` block from `/wp-content/debug.log`
- the file path + line number shown in that block
