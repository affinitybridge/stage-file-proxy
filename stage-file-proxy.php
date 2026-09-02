<?php
/*
	Plugin Name: Stage File Proxy
	Description: Fetches missing uploads from a configured source site on non-production environments. This plugin does nothing on prod but should remain enabled so that it won't need to be re-enabled when development sites sync the database. To use this plugin in development environments, see the README.md. Other settings under Settings -> Stage File Proxy (once it is configured).
	Version: 1.0
	Author: Affinity Bridge
	Author URI: mailto:info@affinitybridge.com
	License: GPL-2.0-or-later
	License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

/**
 * This is a fork of alleyinteractive/stage-file-proxy (originally by Austin
 * Smith, Alley Interactive — http://alleyinteractive.com/), substantially
 * modified by Affinity Bridge (info@affinitybridge.com). Licensed
 * GPL-2.0-or-later, same as the original.
 */

/**
 * Errors must be suppressed on static-looking paths, or they'll corrupt the
 * header/download response — so this plugin has to load first.
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
 * Settings → Stage File Proxy: lets an admin pick the fetch mode (see
 * sfp_get_mode()) without touching WP-CLI. Only registered when
 * STAGE_FILE_PROXY_URL is configured (the early return above) — consistent
 * with the rest of this plugin doing nothing at all when it's not.
 */
add_action( 'admin_menu', 'sfp_admin_menu' );
function sfp_admin_menu() {
	add_options_page(
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
 * (see the regex below) — anything else falls through to the normal 404.
 *
 * The configured mode (see sfp_get_mode()) decides what happens next:
 * - 'do_not_cache' — 302 redirect the browser straight to the file's URL on
 *   the source site. Nothing is ever saved locally.
 * - 'fetch_and_cache' (default) — fetch the exact file from the source site,
 *   write it to disk under the exact requested filename (bypassing
 *   sanitize_file_name()/wp_unique_filename(), which would otherwise mangle
 *   "dirty" production filenames — e.g. U+202F, the narrow no-break space
 *   macOS puts in formatted times like "10.33.02 AM" — and cause an endless
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
	if ( ! preg_match( '#\.(jpe?g|png|gif|webp|avif|svg|ico|pdf|mp4|webm|mov|mp3|docx?|xlsx?|zip)$#i', $relative_path ) ) {
		return; // not a recognized upload type — let the normal 404 happen.
	}

	if ( 'do_not_cache' === sfp_get_mode() ) {
		header( 'Location: ' . sfp_get_base_url() . sfp_encode_relative_path( $relative_path ) );
		exit;
	}

	// fetch_and_cache (default): serve the exact file locally if we already have it.
	$dest = trailingslashit( wp_get_upload_dir()['basedir'] ) . $relative_path;
	if ( file_exists( $dest ) ) {
		return; // the webserver will serve it on the next hit.
	}

	if ( sfp_fetch_and_save( $relative_path, $dest ) ) {
		sfp_serve_requested_file( $dest );
	}

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
			sfp_fetch_and_save( $resize['filename'], $original_dest );
		}
		sfp_resize_image( $original_dest, $resize ); // exits on success.
	}

	sfp_error();
}

/**
 * Fetch $relative_path from the configured source site and write it to
 * $dest under its exact name. Returns whether the fetch succeeded.
 */
function sfp_fetch_and_save( $relative_path, $dest ) {
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
	$resp = wp_remote_get( $remote, apply_filters( 'sfp_http_remote_args', $default_args ) );
	if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
		return false; // origin doesn't have it.
	}

	if ( ! wp_mkdir_p( dirname( $dest ) ) ) {
		return false;
	}
	file_put_contents( $dest, wp_remote_retrieve_body( $resp ) ); // exact name, no sanitize.
	return true;
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
		$img = wp_get_image_editor( $basefile );

		// wp_get_image_editor can return a WP_Error if the file exists but is corrupted.
		if ( is_wp_error( $img ) ) {
			sfp_error();
		}

		$img->resize( $resize['width'], $resize['height'], $resize['crop'] );
		$info = pathinfo( $basefile );
		$path_to_new_file = $info['dirname'] . '/' . $info['filename'] . '-' . $suffix . '.' .$info['extension'];
		$img->save( $path_to_new_file );
		sfp_serve_requested_file( $path_to_new_file );
	}
}

/**
 * Serve the file directly.
 */
function sfp_serve_requested_file( $filename ) {
	// find the mime type
	$finfo = finfo_open( FILEINFO_MIME_TYPE );
	$type = finfo_file( $finfo, $filename );
	// serve the image this one time (next time the webserver will do it for us)
	ob_end_clean();
	header( 'Content-Type: '. $type );
	header( 'Content-Length: ' . filesize( $filename ) );
	readfile( $filename );
	exit;
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
 * sfp_get_relative_path()) so it can be used to build a URL again — the
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

function sfp_error() {
	die( 'SFP tried to load, but encountered an error' );
}
