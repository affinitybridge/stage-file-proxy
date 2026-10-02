# Stage File Proxy

Fetches missing uploads (images, PDFs, etc.) from a source site on demand,
meaning you don't need to sync the entire `wp-content/uploads` directory to every development environment.

**This plugin does nothing on production.** It should stay active everywhere
— including prod — so that it doesn't need to be re-enabled every time a
development site's database is refreshed from a production export (plugin
activation state comes along with the DB sync; the plugin itself never runs
unless configured).

The source site is configured through a constant in code (see
Configuration below); the fetch mode is configured through **Settings →
Stage File Proxy** in wp-admin (see Modes below).

## How it works

When a request comes in for a file under `/wp-content/uploads/` that doesn't
exist locally (a 404) and looks like a recognized upload type (image, PDF,
video, etc. — see the extension list in the Modes section), the plugin steps
in. What happens next depends on the configured mode — see Modes below —
but by default (fetch-and-cache):

1. Requests the same relative path from the configured source site.
2. If the source has the file, saves it to disk under the *exact* requested
   filename and serves it directly (same-origin, no redirect).
3. If the source doesn't have it either, and the requested filename encodes
   a thumbnail size (e.g. `photo-300x200.jpg`), fetches the original instead
   and resizes it locally.
4. Otherwise, falls through to a normal 404.

Saving under the exact filename (rather than running it through WordPress's
`sanitize_file_name()`/`wp_unique_filename()`) matters because production
filenames sometimes contain characters WordPress would otherwise rewrite
(e.g. the narrow no-break space macOS puts in formatted times like
"10.33.02 AM"). If the saved name doesn't match the URL referenced in the
content, the request just keeps 404ing and re-fetching forever.

Everything runs on the `init` hook and only engages for requests that look
like an upload path — normal page loads are untouched.

## Installation

1. Copy (or symlink) this directory to `wp-content/plugins/stage-file-proxy`.
2. Activate it — on every environment, including production.

## Configuration

Define `STAGE_FILE_PROXY_URL` as a constant, pointed at the uploads directory
of the site you want to pull files from. Do this in `wp-config.php` before the 
lines that includes `wp-settings.php`. 

**DO NOT DEFINE THIS CONSTANT ON A PRODUCTION SITE**

Example:

```php
// Must be defined before including wp-settings.php
// DO NOT DEFINE THIS CONSTANT ON A PRODUCTION SITE
if (!defined("STAGE_FILE_PROXY_URL")) define("STAGE_FILE_PROXY_URL", "https://example.com/wp-content/uploads/");

/** Include wp-settings.php */
if (file_exists(ABSPATH . "/wp-settings.php")) {
    require_once ABSPATH . "/wp-settings.php";
}
```

If `STAGE_FILE_PROXY_URL` isn't defined (or is empty), the plugin exits
immediately and does nothing — this is the expected state on production.

Notes on the value:
- A trailing slash is optional — the plugin normalizes it either way.
- Point it at the *uploads* directory (`.../wp-content/uploads/`), not the
  site root.

## Modes

The plugin has two mutually exclusive modes.

Once the module is configured with the proxy URL, the mode can be set in wp-admin under **Settings → Stage File Proxy**, or with WP-CLI:

```bash
wp option update sfp_mode fetch_and_cache
# or
wp option update sfp_mode do_not_cache
```

- **Fetch and cache** (default) — *"Fetch and cache a local copy of missing
  files from the proxy URL."* Fetches the file from the source site, saves
  it to disk under the exact requested filename, and serves it directly
  (same-origin, no redirect). Subsequent requests are served straight from
  disk without hitting the source site again. Also handles on-demand
  thumbnail resizing (see 'How it works' above).
- **Do not cache** — *"Always fetch missing files from the proxy URL. Do not
  cache them."* Redirects (302) the browser straight to the file's URL on
  the source site, every time, instead of fetching and caching it locally.
  Nothing is ever saved locally, so:
  - every request for that file redirects again (no caching, unlike fetch
    and cache, which saves on first fetch and serves locally from then on).
  - WordPress itself still doesn't see the file as present — anything that
    checks the filesystem directly (thumbnail regeneration, the image
    editor, media library file-exists checks) still treats it as missing,
    even though it loads fine in a browser.
  - if the source site ever returns anything other than the file itself for
    that URL (a 404 page because it's missing there too, a hotlink-
    protection response, etc.), the browser gets an HTML response where it
    expected an image, which Chrome blocks (`net::ERR_BLOCKED_BY_ORB`).

Both modes apply to the same set of recognized extensions (`jpg`/`jpeg`/`png`/
`gif`/`webp`/`avif`/`svg`/`ico`/`pdf`/`mp4`/`webm`/`mov`/`mp3`/`doc(x)`/
`xls(x)`/`zip`/`css`/`js`/`json`/`woff(2)`/`ttf`/`otf`/`eot` — the text and
font types cover plugin-generated assets like Elementor's
`uploads/elementor/css/post-*.css`); anything else just 404s regardless of
mode.

## Updates

This plugin isn't distributed through wp.org — it's internal, hosted at
[affinitybridge/stage-file-proxy](https://github.com/affinitybridge/stage-file-proxy).
Sites running it (installed via git, not a wp.org zip) check that GitHub
repo for newer releases via the bundled
[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)
library, and will show a normal wp-admin "update available" notice on the
Plugins page and Dashboard → Updates when one is published. No separate
update-checker plugin or per-site configuration is needed.

### Releasing updates

To ship an update that maintainers will see:

1. Bump the `Version:` header in `stage-file-proxy.php`.
2. Commit, tag the commit with that version (matching the header exactly,
   e.g. `1.1` — no `v` prefix), and push the tag.
3. Publish a [GitHub Release](https://github.com/affinitybridge/stage-file-proxy/releases)
   from that tag with release notes. The release notes become the
   changelog shown in the "View version x.x details" popup in wp-admin.

## Developers

Two standard WordPress filters let you hook into this plugin's behavior from
your own theme or plugin code, without editing `stage-file-proxy.php`
directly. Most setups won't need either — they exist as an escape hatch for
source sites with unusual auth or path-layout quirks.

### `sfp_http_remote_args`

Filters the args array passed to `wp_remote_get()` when fetching a file from
the source site. Default:

```php
[
	'timeout'    => 30,
	'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
]
```

The default `user-agent` overrides `wp_remote_get()`'s own default (something
like `WordPress/7.x; https://yoursite.tld`) — bot protection on the source
site (fail2ban, a WAF, Cloudflare, etc.) can flag and block that on sight,
which shows up as fetches silently failing / images never loading in
`fetch_and_cache` mode. If the source site blocks the browser UA above too,
or needs something else entirely (a specific UA it allow-lists, HTTP basic
auth, a self-signed cert, a longer timeout for large files), override it
here:

```php
add_filter( 'sfp_http_remote_args', function ( $args ) {
	$args['timeout'] = 60;
	$args['headers']['Authorization'] = 'Basic ' . base64_encode( 'user:pass' );
	return $args;
} );
```

### `sfp_relative_path`

Filters the relative upload path computed from the request URI (e.g.
`2026/06/photo.jpg` — already stripped of `/wp-content/uploads/`, any
multisite `/sites/123/` prefix, and URL-decoded) before it's used to build
both the source fetch URL and the local save path.

Use this if you need to remap paths between the two environments — e.g. the
source site organizes uploads differently, or certain paths should be
redirected elsewhere:

```php
add_filter( 'sfp_relative_path', function ( $path ) {
	// e.g. the source site still uses an old 'legacy/' prefix for some paths.
	return str_replace( 'legacy/', '', $path );
} );
```
