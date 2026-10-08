<?php
/*
	Plugin Name: Stage File Proxy
	Description: Fetches missing uploads from a configured source site on non-production environments. This plugin does nothing on prod but should remain enabled so that it won't need to be re-enabled when development sites sync the database. To use this plugin in development environments, see the README.md. Other settings under Tools -> Stage File Proxy (once it is configured).
	Note: If you don't have an /uploads/ directory on your development site, it may take a few requests for the plugin to fully populate it.
	Version: 1.1.3
	Author: Affinity Bridge
	Author URI: mailto:info@affinitybridge.com
	Update URI: https://github.com/affinitybridge/stage-file-proxy/
	License: GPL-2.0-or-later
	License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

/**
 * This is a fork of alleyinteractive/stage-file-proxy (originally by Austin
 * Smith, Alley Interactive -- http://alleyinteractive.com/), substantially
 * modified by Affinity Bridge (info@affinitybridge.com). Licensed
 * GPL-2.0-or-later, same as the original.
 */

/**
 * Checks GitHub Releases on the repo below for newer tagged versions, so
 * that sites running this plugin see a normal wp-admin "update available"
 * notice -- see the README's "Releasing updates" section for how to cut one.
 */
require_once __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';

YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/affinitybridge/stage-file-proxy/',
	__FILE__,
	'stage-file-proxy'
);

/**
 * Errors must be suppressed on static-looking paths, or they'll corrupt the
 * header/download response -- so this plugin has to load first.
 *
 * Assumption: if a request for /wp-content/uploads/ reaches PHP at all, it's
 * a 404, and the file should be fetched from the remote server instead.
 *
 * The dynamic resizing portion was adapted from dynamic-image-resizer.
 * See: http://wordpress.org/plugins/dynamic-image-resizer/
 */

$sfp_url = false;

// The source ("origin") site to pull missing uploads from comes from
// wp-config.php's STAGE_FILE_PROXY_URL constant, set per-site. Production
// never defines this constant, so this bails out there without a separate
// environment check.
if ( defined( 'STAGE_FILE_PROXY_URL' ) && STAGE_FILE_PROXY_URL ) {
	$sfp_url = trailingslashit( STAGE_FILE_PROXY_URL ); // normalize: works whether or not a trailing slash was configured.
}

if ( ! $sfp_url ) {
	return;
}

/**
 * Tools -> Stage File Proxy: lets an admin pick the fetch mode (see
 * sfp_get_mode()) without touching WP-CLI. Only registered when
 * STAGE_FILE_PROXY_URL is configured (the early return above) -- consistent
 * with the rest of this plugin doing nothing at all when it's not.
 */
add_action( 'admin_menu', 'sfp_admin_menu' );
function sfp_admin_menu() {
	add_management_page(
		'Stage File Proxy',
		'Stage File Proxy',
		'manage_options',
		'stage-file-proxy',
		'sfp_render_settings_page'
	);
}

add_action( 'admin_init', 'sfp_register_settings' );
function sfp_register_settings() {
	register_setting( 'sfp_settings', 'sfp_mode', array(
		'type'              => 'string',
		'sanitize_callback' => 'sfp_sanitize_mode',
		'default'           => 'fetch_and_cache',
	) );
}

function sfp_sanitize_mode( $value ) {
	return 'do_not_cache' === $value ? 'do_not_cache' : 'fetch_and_cache';
}

function sfp_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$mode = sfp_get_mode();
	?>
	<div class="wrap">
		<h1>Stage File Proxy</h1>
		<?php settings_errors(); ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'sfp_settings' ); ?>
			<fieldset>
				<legend class="screen-reader-text"><span>Mode</span></legend>
				<p>
					<label>
						<input type="radio" name="sfp_mode" value="fetch_and_cache" <?php checked( $mode, 'fetch_and_cache' ); ?> />
						<strong>Fetch and cache</strong> &mdash; Fetch and cache a local copy of missing files from the proxy URL
					</label>
				</p>
				<p>
					<label>
						<input type="radio" name="sfp_mode" value="do_not_cache" <?php checked( $mode, 'do_not_cache' ); ?> />
						<strong>Do not cache</strong> &mdash; Always fetch missing files from the proxy URL. Do not cache them.
					</label>
				</p>
			</fieldset>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/**
 * Load SFP before other plugins so it can suppress their warnings too.
 * @see http://wordpress.org/support/topic/how-to-change-plugins-load-order
 */
function sfp_first() {
	$plugin_path = 'stage-file-proxy/stage-file-proxy.php';
	$active_plugins = get_option( 'active_plugins' );
	$plugin_key = array_search( $plugin_path, $active_plugins );
	if ( $plugin_key ) { // if it's 0 it's the first plugin already, no need to continue
		array_splice( $active_plugins, $plugin_key, 1 );
		array_unshift( $active_plugins, $plugin_path );
		update_option( 'active_plugins', $active_plugins );
	}
}
add_action( 'activated_plugin', 'sfp_first' );

if ( stripos( $_SERVER['REQUEST_URI'], '/wp-content/uploads/' ) !== false ) sfp_expect();

/**
 * This function, triggered above, sets the chain in motion.
 */
function sfp_expect() {
	ob_start();
	ini_set( 'display_errors', 'off' );
	add_action( 'init', 'sfp_dispatch' );
}

/**
 * Handle a missing upload request. Only engages for a recognized extension
 * (see the regex below) -- anything else falls through to the normal 404.
 *
 * The configured mode (see sfp_get_mode()) decides what happens next:
 * - 'do_not_cache' -- 302 redirect the browser straight to the file's URL on
 *   the source site. Nothing is ever saved locally.
 * - 'fetch_and_cache' (default) -- fetch the exact file from the source site,
 *   write it to disk under the exact requested filename (bypassing
 *   sanitize_file_name()/wp_unique_filename(), which would otherwise mangle
 *   "dirty" production filenames -- e.g. U+202F, the narrow no-break space
 *   macOS puts in formatted times like "10.33.02 AM" -- and cause an endless
 *   404-and-refetch loop when the saved name no longer matches the URL
 *   referenced in the content), then serve it directly.
 *
 * If the exact file isn't on the source either, and the requested name looks
 * like an on-demand thumbnail (e.g. photo-300x200.jpg), fetch the original
 * and resize it locally instead of failing outright.
 */
function sfp_dispatch() {
	$relative_path = sfp_get_relative_path();
	if ( '' === $relative_path || false !== strpos( $relative_path, '..' ) ) {
		return; // not an uploads-relative request, or a path-traversal attempt.
	}
	if ( ! preg_match( '#\.(jpe?g|png|gif|webp|avif|svg|ico|pdf|mp4|webm|mov|mp3|docx?|xlsx?|zip|css|js|json|woff2?|ttf|otf|eot)$#i', $relative_path ) ) {
		return; // not a recognized upload type -- let the normal 404 happen.
	}

	if ( 'do_not_cache' === sfp_get_mode() ) {
		header( 'Location: ' . sfp_get_base_url() . sfp_encode_relative_path( $relative_path ) );
		exit;
	}

	// fetch_and_cache (default): serve the exact file locally if we already
	// have it -- e.g. a concurrent request finished saving it after the
	// webserver missed it for this one. Returning here instead would hand
	// the request to WordPress's 404 page.
	$dest = trailingslashit( wp_get_upload_dir()['basedir'] ) . $relative_path;
	if ( file_exists( $dest ) ) {
		sfp_serve_requested_file( $dest );
	}

	$result = sfp_fetch_and_save( $relative_path, $dest );
	if ( true === $result ) {
		sfp_serve_requested_file( $dest );
	}
	$transient = 'transient' === $result;

	// The exact file isn't on the source. If the name encodes a thumbnail
	// size, fetch the original and resize it rather than failing outright.
	if ( preg_match( '/(.+)(-r)?-([0-9]+)x([0-9]+)(c)?\.(jpe?g|png|gif)/iU', $relative_path, $matches ) ) {
		$resize = array(
			'filename' => $matches[1] . '.' . $matches[6],
			'width'    => $matches[3],
			'height'   => $matches[4],
			'crop'     => ! empty( $matches[5] ),
			'mode'     => substr( $matches[2], 1 ),
		);

		$original_dest = trailingslashit( wp_get_upload_dir()['basedir'] ) . $resize['filename'];
		if ( ! file_exists( $original_dest ) ) {
			$transient = 'transient' === sfp_fetch_and_save( $resize['filename'], $original_dest );
		}
		sfp_resize_image( $original_dest, $resize ); // exits on success.
	}

	// A transient origin failure (rate limit, overload, timeout) gets a
	// non-cacheable 503 so a reload retries it; anything else is a real 404.
	sfp_error( $transient ? 503 : 404 );
}

/**
 * Fetch $relative_path from the configured source site and write it to
 * $dest under its exact name. Returns true on success, 'transient' if the
 * origin failed in a way a later retry might not (rate limit, overload,
 * timeout), or false if the origin doesn't have it.
 *
 * Pages with many images fire many of these at once, so: concurrent
 * requests for the same file (e.g. several thumbnails of one original)
 * wait on a lock and reuse the first one's result instead of fetching it
 * again, and the file is written to a temp name and renamed into place so
 * nothing -- the webserver or a concurrent resize -- ever reads it half
 * written.
 */
function sfp_fetch_and_save( $relative_path, $dest ) {
	$lock = sfp_lock( $dest );
	if ( file_exists( $dest ) ) {
		sfp_unlock( $lock );
		return true; // a concurrent request fetched it while we waited.
	}

	$remote = sfp_get_base_url() . sfp_encode_relative_path( $relative_path );

	/**
	 * Filter: sfp_http_remote_args
	 *
	 * Alter the args of the GET request.
	 *
	 * The default 'user-agent' below overrides wp_remote_get()'s own default
	 * (something like "WordPress/7.x; https://yoursite.tld"), which bot
	 * protection on the source site (fail2ban, a WAF, etc.) can flag and
	 * block on sight. Override this filter if the source site's protection
	 * blocks this default too, or needs something else entirely (a specific
	 * UA it allow-lists, auth headers, a longer timeout for large files).
	 *
	 * @param array $remote_http_request_args The request arguments.
	 */
	$default_args = array(
		'timeout'    => 30,
		'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
	);
	$args = apply_filters( 'sfp_http_remote_args', $default_args );
	$resp = sfp_remote_get( $remote, $args );

	// Other bot protection (e.g. Anubis) does the opposite: it lets plain
	// clients through but answers browser-like UAs with a 200 HTML challenge
	// page. None of the proxied types are HTML, so an HTML response means
	// we got such a page -- retry once with a non-browser UA.
	if ( sfp_is_html_response( $resp ) ) {
		$args['user-agent'] = 'stage-file-proxy (+https://github.com/affinitybridge/stage-file-proxy)';
		$resp = sfp_remote_get( $remote, $args );
	}

	$result = false; // origin doesn't have it.
	if ( sfp_is_transient_failure( $resp ) ) {
		$result = 'transient';
	} elseif ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
		$result = false;
	} elseif ( sfp_is_html_response( $resp ) ) {
		$result = 'transient'; // still a block/challenge page -- never cache it as the file.
	} elseif ( wp_mkdir_p( dirname( $dest ) ) ) {
		// exact name, no sanitize. The temp name sits in the same directory
		// so rename() is atomic.
		$tmp = dirname( $dest ) . '/.sfp-' . uniqid( '', true ) . '.tmp';
		if ( false !== file_put_contents( $tmp, wp_remote_retrieve_body( $resp ) ) && rename( $tmp, $dest ) ) {
			$result = true;
		} else {
			@unlink( $tmp );
		}
	}

	sfp_unlock( $lock );
	return $result;
}

/**
 * wp_remote_get(), retried a couple of times on transient failures -- a
 * page full of images can trip the origin's rate limiting or briefly
 * overload it. Honors a short Retry-After, and gives up early rather than
 * run into PHP's max_execution_time.
 */
function sfp_remote_get( $url, $args ) {
	$start = microtime( true );
	for ( $attempt = 1; ; $attempt++ ) {
		$resp = wp_remote_get( $url, $args );
		if ( $attempt >= 3 || ! sfp_is_transient_failure( $resp ) ) {
			return $resp;
		}
		$wait = $attempt; // 1s, then 2s.
		$retry_after = is_wp_error( $resp ) ? '' : wp_remote_retrieve_header( $resp, 'retry-after' );
		if ( is_numeric( $retry_after ) ) {
			$wait = min( max( (int) $retry_after, 1 ), 5 );
		}
		if ( microtime( true ) - $start + $wait > 15 ) {
			return $resp;
		}
		sleep( $wait );
	}
}

/**
 * Whether $resp is a failure that might succeed if tried again: a
 * connection error/timeout, 429 Too Many Requests, or a 5xx.
 */
function sfp_is_transient_failure( $resp ) {
	if ( is_wp_error( $resp ) ) {
		return true;
	}
	$code = (int) wp_remote_retrieve_response_code( $resp );
	return 429 === $code || $code >= 500;
}

/**
 * Take an exclusive lock for writing $path, blocking until any concurrent
 * request holding it is done. Lock files live in the temp dir, not uploads.
 */
function sfp_lock( $path ) {
	$handle = @fopen( trailingslashit( get_temp_dir() ) . 'sfp-' . md5( $path ) . '.lock', 'c' );
	if ( $handle ) {
		flock( $handle, LOCK_EX );
	}
	return $handle; // false if the lock file couldn't be opened -- carry on unlocked.
}

function sfp_unlock( $handle ) {
	if ( $handle ) {
		flock( $handle, LOCK_UN );
		fclose( $handle );
	}
}

/**
 * Whether $resp is a successful response with an HTML body -- for the file
 * types this plugin proxies, that's a bot-protection page, not the file.
 */
function sfp_is_html_response( $resp ) {
	if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
		return false;
	}
	$type = wp_remote_retrieve_header( $resp, 'content-type' );
	if ( is_array( $type ) ) {
		$type = reset( $type );
	}
	return 0 === stripos( (string) $type, 'text/html' );
}

/**
 * Resizes $basefile based on parameters in $resize
 */
function sfp_resize_image( $basefile, $resize ) {
	if ( file_exists( $basefile ) ) {
		$suffix = $resize['width'] . 'x' . $resize['height'];
		if ( $resize['crop'] ) {
			$suffix .= 'c';
		}
		if ( 'r' == $resize['mode'] ) {
			$suffix = 'r-' . $suffix;
		}
		$info = pathinfo( $basefile );
		$path_to_new_file = $info['dirname'] . '/' . $info['filename'] . '-' . $suffix . '.' .$info['extension'];

		// Same lock-then-recheck and temp-then-rename as sfp_fetch_and_save().
		$lock = sfp_lock( $path_to_new_file );
		if ( ! file_exists( $path_to_new_file ) ) {
			$img = wp_get_image_editor( $basefile );

			// wp_get_image_editor can return a WP_Error if the file exists but is corrupted.
			if ( is_wp_error( $img ) ) {
				sfp_unlock( $lock );
				sfp_error();
			}

			$img->resize( $resize['width'], $resize['height'], $resize['crop'] );
			// Keep the extension on the temp name -- the editor picks the output format from it.
			$saved = $img->save( $info['dirname'] . '/.sfp-' . uniqid( '', true ) . '.' . $info['extension'] );
			if ( is_wp_error( $saved ) || ! rename( $saved['path'], $path_to_new_file ) ) {
				sfp_unlock( $lock );
				sfp_error();
			}
		}
		sfp_unlock( $lock );
		sfp_serve_requested_file( $path_to_new_file );
	}
}

/**
 * Serve the file directly.
 */
function sfp_serve_requested_file( $filename ) {
	// find the mime type. finfo sniffs text formats like CSS and JS as
	// text/plain, which browsers reject under X-Content-Type-Options: nosniff,
	// so map those by extension first.
	$text_types = array(
		'css'  => 'text/css',
		'js'   => 'application/javascript',
		'json' => 'application/json',
		'svg'  => 'image/svg+xml',
	);
	$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
	if ( isset( $text_types[ $ext ] ) ) {
		$type = $text_types[ $ext ];
	} else {
		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		$type = finfo_file( $finfo, $filename );
	}
	// serve the image this one time (next time the webserver will do it for us)
	ob_end_clean();
	header( 'Content-Type: '. $type );
	header( 'Content-Length: ' . filesize( $filename ) );
	readfile( $filename );
	exit;
}

/**
 * Some code resizes images server-side from the local original instead of
 * linking to a thumbnail URL -- e.g. Toolset Views' [wpv-post-featured-image
 * size="custom"] calls wp_get_image_editor() on the original's path. When
 * the original isn't on disk that just fails, and no URL for this plugin to
 * catch ever reaches the browser. So fetch a missing original right before
 * WordPress tries to load it into an image editor.
 *
 * WordPress doesn't filter the path it's about to load, but it does pass it
 * to each editor implementation's test() while choosing one -- so put a
 * stand-in "editor" first in line that fetches the file and then declines,
 * leaving the real editors to load it.
 */
class SFP_Fetch_Missing_Original {
	public static function test( $args = array() ) {
		if ( ! empty( $args['path'] ) ) {
			sfp_fetch_missing_original( $args['path'] );
		}
		return false;
	}

	public static function supports_mime_type( $mime_type ) {
		return false;
	}
}

function sfp_image_editors( $editors ) {
	array_unshift( $editors, 'SFP_Fetch_Missing_Original' );
	return $editors;
}
add_filter( 'wp_image_editors', 'sfp_image_editors' );

/**
 * Fetch $path from the source site if it's a missing image under uploads.
 * Only in fetch_and_cache mode -- do_not_cache never saves anything locally.
 */
function sfp_fetch_missing_original( $path ) {
	if ( 'fetch_and_cache' !== sfp_get_mode() || file_exists( $path ) ) {
		return;
	}
	if ( false !== strpos( $path, '..' ) || ! preg_match( '#\.(jpe?g|png|gif|webp|avif)$#i', $path ) ) {
		return;
	}
	$basedir = trailingslashit( wp_get_upload_dir()['basedir'] );
	if ( 0 !== strpos( $path, $basedir ) ) {
		return; // not in uploads -- nothing the source site would have.
	}

	// This runs during page renders, so remember a failed fetch for a while
	// rather than hitting the source site (with retries) on every render:
	// an hour if it doesn't have the file, a few minutes if it was just
	// struggling (rate limit, overload, timeout).
	$failed_key = 'sfp_missing_' . md5( $path );
	if ( get_transient( $failed_key ) ) {
		return;
	}
	$result = sfp_fetch_and_save( substr( $path, strlen( $basedir ) ), $path );
	if ( true !== $result ) {
		set_transient( $failed_key, 1, 'transient' === $result ? 5 * MINUTE_IN_SECONDS : HOUR_IN_SECONDS );
	}
}

/**
 * prevent WP from generating resized images on upload
 */
function sfp_image_sizes_advanced( $sizes ) {
	global $dynimg_image_sizes;

	// save the sizes to a global, because the next function needs them to lie to WP about what sizes were generated
	$dynimg_image_sizes = $sizes;

	// force WP to not make sizes by telling it there's no sizes to make
	return array();
}
add_filter( 'intermediate_image_sizes_advanced', 'sfp_image_sizes_advanced' );

/**
 * Trick WP into thinking the images were generated anyways.
 */
function sfp_generate_metadata( $meta ) {
	global $dynimg_image_sizes;

	if ( ! is_array( $dynimg_image_sizes ) ) {
		return $meta;
	}

	foreach ($dynimg_image_sizes as $sizename => $size) {
		// figure out what size WP would make this:
		$newsize = image_resize_dimensions( $meta['width'], $meta['height'], $size['width'], $size['height'], $size['crop'] );

		if ($newsize) {
			$info = pathinfo( $meta['file'] );
			$ext = $info['extension'];
			$name = wp_basename( $meta['file'], ".$ext" );

			$suffix = "r-{$newsize[4]}x{$newsize[5]}";
			if ( $size['crop'] ) $suffix .='c';

			// build the fake meta entry for the size in question
			$resized = array(
				'file' => "{$name}-{$suffix}.{$ext}",
				'width' => $newsize[4],
				'height' => $newsize[5],
			);

			$meta['sizes'][$sizename] = $resized;
		}
	}

	return $meta;
}
add_filter( 'wp_generate_attachment_metadata', 'sfp_generate_metadata' );

/**
 * Get the relative file path by stripping out the /wp-content/uploads/
 * business, decoding it, and dropping any query string.
 */
function sfp_get_relative_path() {
	static $path;
	if ( null === $path ) {
		$uri  = parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) ?: '';
		$path = rawurldecode( preg_replace( '#.*/wp-content/uploads(/sites/\d+)?/#i', '', $uri ) );
	}
	/**
	 * Filter: sfp_relative_path
	 *
	 * Alter the relative path of an image in SFP.
	 *
	 * @param string $path The relative path of the file.
	 */
	return apply_filters( 'sfp_relative_path', $path );
}

/**
 * Re-encode each segment of a decoded relative path (as returned by
 * sfp_get_relative_path()) so it can be used to build a URL again -- the
 * source fetch URL, or the do_not_cache redirect target.
 */
function sfp_encode_relative_path( $relative_path ) {
	return implode( '/', array_map( 'rawurlencode', explode( '/', $relative_path ) ) );
}

/**
 * Get the configured dispatch mode: 'fetch_and_cache' (default) or
 * 'do_not_cache'. See README.md for what each mode does. Any stored value
 * other than the literal 'do_not_cache' (including no value, or a value
 * left over from an older version of this plugin) resolves to the default.
 */
function sfp_get_mode() {
	static $mode;
	if ( ! $mode ) {
		$mode = 'do_not_cache' === get_option( 'sfp_mode' ) ? 'do_not_cache' : 'fetch_and_cache';
	}
	return $mode;
}

/**
 * Get the configured origin uploads URL (STAGE_FILE_PROXY_URL).
 */
function sfp_get_base_url() {
	global $sfp_url;
	return $sfp_url;
}

/**
 * Fail the request with a real error status (die() alone would send a 200)
 * and no-cache headers, so the browser doesn't hold on to the failure.
 */
function sfp_error( $status = 404 ) {
	if ( ob_get_level() ) {
		ob_end_clean();
	}
	status_header( $status );
	nocache_headers();
	if ( 503 === $status ) {
		header( 'Retry-After: 5' );
	}
	die( 'SFP tried to load, but encountered an error' );
}
