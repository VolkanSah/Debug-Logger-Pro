
# Debug Logger Pro
###### Enhanced Interaction & Debug Logger 


![License](https://img.shields.io/badge/license-DBAD-blue.svg)
![Version](https://img.shields.io/badge/version-3.0-green.svg)
![WordPress](https://img.shields.io/badge/WordPress-Compatible-blue.svg)
![AI Enhanced](https://img.shields.io/badge/AI%20Enhanced-Claude%204-purple.svg)
![AI Enhanced](https://img.shields.io/badge/AI%20Enhanced-Gemini%202.5-purple.svg)



A small WordPress plugin that shows you what's going wrong on your site, live, without leaving wp-admin. PHP warnings, fatal errors (yes, also the ones that kill your site while you install a plugin) and page requests all end up in one log with filters, search and a copy button.

I built it because every debug plugin I tried was either a heavy toolbar or a wall of settings. This one does one thing: show the log.

## What you get

- **Fatal errors included.** A shutdown handler catches crashes that normal error handlers never see.
- **One viewer for everything.** Plugin log and WordPress' own `debug.log` side by side or merged, newest entry on top.
- **Filter chips** for Fatal, Error, Warning, Notice, Deprecated and Request, each with a live counter.
- **Search, copy, download, clear.** Copy gives you plain text you can paste into a bug report.
- **Floating button** on the site and in wp-admin (admins only). It shows how many issues were logged and opens the viewer in a panel, so you can check a page without switching tabs.
- **Live or paused.** Updates every couple of seconds, pause when you want to read in peace.
- No jQuery, no external requests, nothing phones home.

## Install

1. Download the repo as ZIP (or use the release ZIP).
2. In WordPress go to **Plugins → Add New → Upload Plugin**, upload, activate.
3. Open **Tools → Debug Logger → Settings**, tick *Record PHP errors…* and save.
4. Switch to the **Logs** tab and reload any page to see the first entries.

Needs WordPress 6.0+ and PHP 8.1+. Built for PHP 8.1 up to 8.5.

## Settings

| Setting | What it does | Default |
|---|---|---|
| Record PHP errors | Master switch. Nothing is recorded while it's off. | off |
| Record every page request | Method and path only, no query string. | on |
| Include AJAX requests | Adds `admin-ajax.php` calls (the logger's own polling is always excluded). | off |
| Store visitor IP addresses | Only turn this on if you really need it. IPs are personal data. | off |
| Show floating button | Admins only, on the site and in wp-admin. | on |
| Refresh every | Seconds between updates while the viewer is open. | 2 |
| Rotate the log at | Max size in MB. The old file is kept once as `.old`. | 5 |

## The WordPress debug log

The plugin can also read WordPress' own log. For that, `WP_DEBUG_LOG` has to be on. Add this to `wp-config.php` (the plugin does **not** edit that file anymore, see below):

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

Heads-up: with `WP_DEBUG_LOG` set to `true`, WordPress writes to `wp-content/debug.log`, and that file is often downloadable by anyone. You can point it to a path outside the web root instead, the plugin follows whatever path you set.

## Where the logs live

`wp-content/edl-logs/edl-<random>.log`, one JSON object per line. The folder gets an `.htaccess` that blocks direct access, and the file name is random, so even without Apache rules nobody can guess it. On **nginx** add a rule that denies the `edl-logs` folder, because `.htaccess` is ignored there.

Query strings are never stored, because they can contain things like password-reset keys.

## Upgrading from 3.x

Version 4 is a rewrite of the core, so a few things changed:

- The setting that edited `wp-config.php` is gone. Editing that file from a plugin is risky; copy the snippet above instead.
- The log moved to `wp-content/edl-logs/`. The old `wp-content/enhanced-debug-log.txt` is not used anymore and may be publicly readable, **delete it**. (The plugin shows a warning while it exists.)
- Settings are new, so open **Tools → Debug Logger → Settings** once and save.
- The German translation file is out of date, see below.

## Security

Every AJAX call checks that you're an administrator and carries a nonce. Log content is only ever put into the page as text, never as HTML, so a nasty User-Agent or URL can't run code in your browser. Details of what changed in 4.0 are in the changelog.

If you find a hole anyway, please open an issue or write to me directly instead of posting an exploit.

## Translations

Strings use the `enhanced-debug-logger` text domain. The files in `languages/` are from version 3 and miss most new strings. To regenerate the template:

```bash
wp i18n make-pot . languages/enhanced-debug-logger.pot
```

Translations welcome.

## Changelog

### 4.0.0

**Security**
- All AJAX endpoints now require `manage_options` and a nonce. Before, any logged-in user (even a subscriber) could read and clear the logs.
- Fixed stored XSS in the floating console: log lines are rendered as text now.
- Removed the code that rewrote `wp-config.php`. It ran on `admin_init`, which also fires for visitors who aren't logged in.
- Logs moved to a protected folder with a random file name. Query strings and IPs are no longer stored by default.

**Fixed**
- The PHP error and fatal error handlers were defined but never registered, so fatal errors weren't actually caught. They are now.
- Max log size is enforced (rotation). Only the end of a log is read, not the whole file.
- WordPress' real debug log path is used (`debug.log`, or whatever `WP_DEBUG_LOG` points to).
- PHP 8.4/8.5: no more use of the deprecated `E_STRICT` constant.

**New**
- One log viewer instead of three tabs, with level filters, search, copy, download and clear.
- Floating button with issue counter, plain JavaScript instead of jQuery.
- Uninstall removes options and log files.

### 3.1
- Internationalization (`.pot` / `.po`), `debug-log.txt` as default WP log name.

### 3.0
- Rewrite with fatal error detection, WP debug log integration, copy to clipboard, file locking.

### 2.5
- Newest entries first, AJAX filter, "Clear log" button.

## Contributing

Bug reports, translations and PRs are welcome. Especially helpful: testing on odd hosting setups and different PHP versions.

## License

AGPL3, see [LICENSE](LICENSE).
