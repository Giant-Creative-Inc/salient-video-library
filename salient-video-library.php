<?php
/**
 * Plugin Name: Salient - Video Library (WPBakery Element)
 * Description: Filterable, category-grouped video library for "video" CPT with Nectar video lightbox, cached AJAX, dependent filters, schema, and LCP tuning.
 * Version: 1.0.2
 * Author: Giant Creative Inc
 *
 * CPT:
 * - video
 *
 * Taxonomies:
 * - video-category
 * - market
 * - product
 * - project
 *
 * Fields (stored as post meta; no get_field()):
 * - Post title: the_title()
 * - description (textarea)  -> meta key: description
 * - video_url (url)         -> meta key: video_url
 * - thumbnail (image)       -> meta key: thumbnail (BEST: store attachment ID)
 *
 * Notes:
 * - We avoid ACF get_field() for speed. We read raw meta via get_post_meta().
 * - Nectar Video Lightbox assets are often conditionally enqueued by Salient only when its
 *   element is in builder content. Since we render via PHP/AJAX, we force-enqueue common
 *   Nectar lightbox deps when this shortcode is present.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Salient_Video_Library {

	const SHORTCODE       = 'svl_video_library';
	const CACHE_PREFIX    = 'svl_';
	const CACHE_TTL_QUERY = 10 * MINUTE_IN_SECONDS;
	const CACHE_TTL_TERMS = 60 * MINUTE_IN_SECONDS;

	const STYLE_HANDLE  = 'svl-video-library';
	const SCRIPT_HANDLE = 'svl-video-library';

	/**
	 * Bootstrap plugin hooks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );

		// Force-load Nectar/Salient lightbox scripts when this element is on the page.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_nectar_lightbox_assets' ), 20 );

		add_action( 'wp_ajax_svl_filter', array( __CLASS__, 'ajax_filter' ) );
		add_action( 'wp_ajax_nopriv_svl_filter', array( __CLASS__, 'ajax_filter' ) );

		add_action( 'vc_before_init', array( __CLASS__, 'register_vc_element' ) );

		// Cache invalidation when videos change (keeps dropdowns accurate).
		add_action( 'save_post_video', array( __CLASS__, 'clear_caches' ) );
		add_action( 'trashed_post', array( __CLASS__, 'clear_caches' ) );
		add_action( 'deleted_post', array( __CLASS__, 'clear_caches' ) );
	}

	/**
	 * Register the plugin shortcode.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register_shortcode() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );
	}

	/**
	 * Register plugin CSS and JS assets.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register_assets() {
		$url = plugin_dir_url( __FILE__ );

		wp_register_style(
			self::STYLE_HANDLE,
			$url . 'assets/video-library.css',
			array(),
			'1.0.2'
		);

		wp_register_script(
			self::SCRIPT_HANDLE,
			$url . 'assets/video-library.js',
			array( 'jquery' ),
			'1.0.2',
			true
		);
	}

	/**
	 * Register the WPBakery Page Builder element.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register_vc_element() {
		if ( ! function_exists( 'vc_map' ) ) {
			return;
		}

		vc_map( array(
			'name'        => 'Video Library (Grouped)',
			'base'        => self::SHORTCODE,
			'category'    => 'Content',
			'description' => 'Grouped video library for the "video" CPT with filters + Nectar lightbox.',
			'params'      => array(
				array(
					'type'        => 'textfield',
					'heading'     => 'Max categories (optional)',
					'param_name'  => 'max_categories',
					'description' => 'Leave blank for all categories.',
				),
				array(
					'type'        => 'textfield',
					'heading'     => 'Videos per category',
					'param_name'  => 'per_category',
					'description' => 'Default 3.',
				),
				array(
					'type'        => 'textfield',
					'heading'     => 'Eager-load first N thumbnails',
					'param_name'  => 'eager_first',
					'description' => 'Default 3. Helps LCP.',
				),
				array(
					'type'        => 'textfield',
					'heading'     => 'Preload first N thumbnails',
					'param_name'  => 'preload_first',
					'description' => 'Default 1. Adds preload links for the first thumbnails.',
				),
			),
		) );
	}

	/* =========================================================
	 * Cache invalidation
	 * ========================================================= */

	/**
	 * Clear all plugin transient caches and bump the cache version.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function clear_caches() {
		delete_transient( 'svl_terms_market_video' );
		delete_transient( 'svl_terms_product_video' );
		delete_transient( 'svl_terms_project_video' );
		delete_transient( 'svl_terms_video-category_video' );

		$ver = (int) get_option( 'svl_cache_ver', 1 );
		update_option( 'svl_cache_ver', $ver + 1, false );
	}

	/**
	 * Get the current cache version number.
	 *
	 * @since 1.0.0
	 * @return int
	 */
	private static function get_cache_ver() {
		return (int) get_option( 'svl_cache_ver', 1 );
	}

	/* =========================================================
	 * Speed helpers (NO get_field)
	 * ========================================================= */

	/**
	 * Fast post meta getter — bypasses ACF formatting overhead.
	 *
	 * @since 1.0.0
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @return mixed          Raw meta value.
	 */
	private static function get_raw_meta( $post_id, $key ) {
		return get_post_meta( (int) $post_id, (string) $key, true );
	}

	/**
	 * Resolve a thumbnail attachment ID from stored meta value.
	 *
	 * Best case: meta stores attachment ID (numeric).
	 * Supported fallbacks:
	 * - Numeric string.
	 * - ACF array with ['ID'] / ['id'] / ['url'].
	 * - URL string (uses attachment_url_to_postid, slower).
	 * - Featured image as last resort.
	 *
	 * @since 1.0.0
	 * @param mixed $raw     Raw meta value.
	 * @param int   $post_id Post ID for featured image fallback.
	 * @return int           Attachment ID, or 0 if not found.
	 */
	private static function resolve_thumb_id( $raw, $post_id = 0 ) {
		if ( is_numeric( $raw ) ) {
			return (int) $raw;
		}

		if ( is_array( $raw ) ) {
			if ( ! empty( $raw['ID'] ) && is_numeric( $raw['ID'] ) ) {
				return (int) $raw['ID'];
			}
			if ( ! empty( $raw['id'] ) && is_numeric( $raw['id'] ) ) {
				return (int) $raw['id'];
			}
			if ( ! empty( $raw['url'] ) && is_string( $raw['url'] ) ) {
				$id = attachment_url_to_postid( $raw['url'] );
				return $id ? (int) $id : 0;
			}
			return 0;
		}

		if ( is_string( $raw ) && '' !== $raw && preg_match( '#^https?://#i', $raw ) ) {
			$id = attachment_url_to_postid( $raw );
			return $id ? (int) $id : 0;
		}

		if ( $post_id ) {
			$fid = (int) get_post_thumbnail_id( (int) $post_id );
			if ( $fid ) {
				return $fid;
			}
		}

		return 0;
	}

	/* =========================================================
	 * Nectar/Salient lightbox enqueues
	 * ========================================================= */

	/**
	 * Force-enqueue Salient/Nectar lightbox assets when our shortcode is present.
	 *
	 * Only enqueues handles that are already registered to avoid hard coupling
	 * across Salient versions.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function maybe_enqueue_nectar_lightbox_assets() {
		if ( empty( $GLOBALS['svl_needs_nectar_lightbox'] ) ) {
			return;
		}

		$script_handles = array(
			'nectar-frontend',
			'nectar-frontend-js',
			'nectar-init',
			'prettyPhoto',
			'prettyphoto',
			'jquery.prettyPhoto',
			'magnific',
			'magnific-popup',
			'jquery-magnific-popup',
		);

		foreach ( $script_handles as $handle ) {
			if ( wp_script_is( $handle, 'registered' ) ) {
				wp_enqueue_script( $handle );
			}
		}

		$style_handles = array(
			'nectar-frontend',
			'prettyPhoto',
			'prettyphoto',
			'jquery.prettyPhoto',
			'magnific',
			'magnific-popup',
			'jquery-magnific-popup',
		);

		foreach ( $style_handles as $handle ) {
			if ( wp_style_is( $handle, 'registered' ) ) {
				wp_enqueue_style( $handle );
			}
		}
	}

	/* =========================================================
	 * Shortcode render
	 * ========================================================= */

	/**
	 * Render the video library shortcode.
	 *
	 * @since 1.0.0
	 * @param array $atts Shortcode attributes.
	 * @return string     HTML output.
	 */
	public static function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'max_categories' => '',
				'per_category'   => '3',
				'eager_first'    => '3',
				'preload_first'  => '1',
			),
			$atts,
			self::SHORTCODE
		);

		$max_categories = self::sanitize_int_or_empty( $atts['max_categories'] );
		$per_raw        = isset( $atts['per_category'] ) ? (int) $atts['per_category'] : 3;
		$per_category   = ( -1 === $per_raw ) ? -1 : max( 1, $per_raw );
		$eager_first    = max( 0, absint( $atts['eager_first'] ) );
		$preload_first  = max( 0, absint( $atts['preload_first'] ) );

		// Flag page for Nectar/Salient lightbox asset enqueue.
		$GLOBALS['svl_needs_nectar_lightbox'] = true;

		// Enqueue our assets only when shortcode exists on page.
		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );

		$filters = array(
			'market'         => 0,
			'product'        => 0,
			'project'        => 0,
			'video-category' => 0,
		);

		// If we're on a video-category archive, lock the category filter to that term
		// and show ALL videos from that category.
		$locked_category_id = 0;
		if ( is_tax( 'video-category' ) ) {
			$term = get_queried_object();
			if ( $term && ! is_wp_error( $term ) && ! empty( $term->term_id ) ) {
				$locked_category_id        = (int) $term->term_id;
				$filters['video-category'] = $locked_category_id;
				$per_category              = -1;
				$max_categories            = '1';
			}
		}

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'SVL',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'svl_nonce' ),
				'strings' => array(
					'loading'   => 'Loading videos',
					'noResults' => 'No videos found for those filters.',
				),
				'config'  => array(
					'perCategory'      => $per_category,
					'maxCategories'    => $max_categories,
					'eagerFirst'       => $eager_first,
					'preloadFirst'     => $preload_first,
					'lockedCategoryId' => $locked_category_id,
				),
			)
		);

		$terms    = self::get_filter_terms_cached( $filters );
		$grouped  = self::get_grouped_videos_cached( $filters, $per_category, $max_categories );
		$preloads = self::render_preload_links( $grouped, $preload_first );

		ob_start();
		?>
		<div class="svl" data-svl>
			<?php echo $preloads; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped HTML. ?>

			<div class="svl__filters" aria-label="Video filters">
				<div class="svl__filters-label" aria-hidden="true">Filter by:</div>

				<label class="svl__sr-only" for="svl-market">Market</label>
				<select id="svl-market" class="svl__select" data-svl-filter="market">
					<option value="0">Market</option>
					<?php foreach ( $terms['market'] as $t ) : ?>
						<option value="<?php echo esc_attr( $t->term_id ); ?>"><?php echo esc_html( $t->name ); ?></option>
					<?php endforeach; ?>
				</select>

				<label class="svl__sr-only" for="svl-product">Product</label>
				<select id="svl-product" class="svl__select" data-svl-filter="product">
					<option value="0">Product</option>
					<?php foreach ( $terms['product'] as $t ) : ?>
						<option value="<?php echo esc_attr( $t->term_id ); ?>"><?php echo esc_html( $t->name ); ?></option>
					<?php endforeach; ?>
				</select>

				<label class="svl__sr-only" for="svl-project">Project</label>
				<select id="svl-project" class="svl__select" data-svl-filter="project">
					<option value="0">Project</option>
					<?php foreach ( $terms['project'] as $t ) : ?>
						<option value="<?php echo esc_attr( $t->term_id ); ?>"><?php echo esc_html( $t->name ); ?></option>
					<?php endforeach; ?>
				</select>

				<div class="svl__category-wrap" <?php echo ( $locked_category_id ? 'hidden' : '' ); ?>>
					<label class="svl__sr-only" for="svl-category">Category</label>
					<select
						id="svl-category"
						class="svl__select"
						data-svl-filter="video-category"
						<?php echo ( $locked_category_id ? 'disabled' : '' ); ?>
					>
						<option value="0">Category</option>
						<?php foreach ( $terms['video-category'] as $t ) : ?>
							<option value="<?php echo esc_attr( $t->term_id ); ?>" <?php selected( (int) $t->term_id, (int) $locked_category_id ); ?>>
								<?php echo esc_html( $t->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<button type="button" class="svl__clear" data-svl-clear hidden>
					Clear Filters <span aria-hidden="true">&times;</span>
				</button>

				<div class="svl__status" role="status" aria-live="polite" aria-busy="false" data-svl-status>
					<span class="svl__loader" data-svl-loader hidden>
						<span class="svl__spinner" aria-hidden="true"></span>
						<span class="svl__loader-text">Loading videos...</span>
					</span>
				</div>
			</div>

			<div class="svl__results" data-svl-results>
				<?php echo self::render_grouped_sections_html( $grouped, $eager_first ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped HTML. ?>
			</div>

			<?php echo self::render_schema_jsonld( $grouped ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped JSON-LD. ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/* =========================================================
	 * AJAX
	 * ========================================================= */

	/**
	 * Handle the AJAX filter request.
	 *
	 * @since 1.0.0
	 * @return void Sends JSON and exits.
	 */
	public static function ajax_filter() {
		check_ajax_referer( 'svl_nonce', 'nonce' );

		$filters = array(
			'market'         => isset( $_POST['market'] ) ? absint( $_POST['market'] ) : 0,
			'product'        => isset( $_POST['product'] ) ? absint( $_POST['product'] ) : 0,
			'project'        => isset( $_POST['project'] ) ? absint( $_POST['project'] ) : 0,
			'video-category' => isset( $_POST['videoCategory'] ) ? absint( $_POST['videoCategory'] ) : 0,
		);

		// Allow perCategory = -1 (meaning "all").
		$per_raw      = isset( $_POST['perCategory'] ) ? (int) $_POST['perCategory'] : 3;
		$per_category = ( -1 === $per_raw ) ? -1 : max( 1, (int) $per_raw );

		// maxCategories can stay as string/int; empty means "no cap".
		$max_categories = isset( $_POST['maxCategories'] ) ? self::sanitize_int_or_empty( $_POST['maxCategories'] ) : '';

		$eager_first = isset( $_POST['eagerFirst'] ) ? max( 0, absint( $_POST['eagerFirst'] ) ) : 3;

		$terms   = self::get_filter_terms_cached( $filters );
		$grouped = self::get_grouped_videos_cached( $filters, $per_category, $max_categories );

		wp_send_json_success( array(
			'terms'           => array(
				'market'        => self::terms_to_options( $terms['market'] ),
				'product'       => self::terms_to_options( $terms['product'] ),
				'project'       => self::terms_to_options( $terms['project'] ),
				'videoCategory' => self::terms_to_options( $terms['video-category'] ),
			),
			'html'            => self::render_grouped_sections_html( $grouped, $eager_first ),
			'schema'          => self::render_schema_jsonld( $grouped ),
			'countCategories' => count( $grouped ),
		) );
	}

	/* =========================================================
	 * Caching wrappers
	 * ========================================================= */

	/**
	 * Get grouped video data with transient caching.
	 *
	 * @since 1.0.0
	 * @param array      $filters        Active filter term IDs.
	 * @param int        $per_category   Videos per section (-1 for all).
	 * @param string|int $max_categories Maximum sections to show ('' for all).
	 * @return array
	 */
	private static function get_grouped_videos_cached( $filters, $per_category, $max_categories ) {
		$key = self::CACHE_PREFIX . 'grouped_' . md5( wp_json_encode( array(
			'ver' => self::get_cache_ver(),
			'f'   => $filters,
			'per' => $per_category,
			'max' => $max_categories,
		) ) );

		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $cached;
		}

		$grouped = self::query_grouped_videos( $filters, $per_category, $max_categories );
		set_transient( $key, $grouped, self::CACHE_TTL_QUERY );

		return $grouped;
	}

	/**
	 * Get filter term lists with transient caching.
	 *
	 * @since 1.0.0
	 * @param array $filters Active filter term IDs.
	 * @return array
	 */
	private static function get_filter_terms_cached( $filters ) {
		$key = self::CACHE_PREFIX . 'filter_terms_' . md5( wp_json_encode( array(
			'ver' => self::get_cache_ver(),
			'f'   => $filters,
		) ) );

		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $cached;
		}

		$out = self::get_all_filter_terms( 'video', $filters );

		set_transient( $key, $out, self::CACHE_TTL_TERMS );

		return $out;
	}

	/**
	 * Fetch available filter terms for all four taxonomies in a single SQL query.
	 *
	 * Replaces four separate calls to get_terms_for_post_type_with_filters() with
	 * one round-trip to the database, then groups results by taxonomy in PHP.
	 *
	 * @since 1.0.2
	 * @param string $post_type Post type slug.
	 * @param array  $filters   Active filter term IDs keyed by taxonomy slug.
	 * @return array {
	 *     @type WP_Term[] $market         Terms for the market taxonomy.
	 *     @type WP_Term[] $product        Terms for the product taxonomy.
	 *     @type WP_Term[] $project        Terms for the project taxonomy.
	 *     @type WP_Term[] $video-category Terms for the video-category taxonomy.
	 * }
	 */
	private static function get_all_filter_terms( $post_type, $filters ) {
		global $wpdb;

		$post_type  = sanitize_key( $post_type );
		$taxonomies = array( 'market', 'product', 'project', 'video-category' );

		$joins  = '';
		$wheres = '';
		$params = array();

		// Build a JOIN + WHERE clause for each active filter.
		$alias_i = 0;
		foreach ( $filters as $filter_key => $selected ) {
			$selected = (int) $selected;
			if ( $selected <= 0 ) {
				continue;
			}

			$alias_i++;
			$tt = "ttf{$alias_i}";
			$tr = "trf{$alias_i}";

			$joins  .= " INNER JOIN {$wpdb->term_relationships} {$tr} ON {$tr}.object_id = p.ID ";
			$joins  .= " INNER JOIN {$wpdb->term_taxonomy} {$tt} ON {$tt}.term_taxonomy_id = {$tr}.term_taxonomy_id ";
			$wheres .= " AND {$tt}.taxonomy = %s AND {$tt}.term_id = %d ";

			$params[] = sanitize_key( $filter_key );
			$params[] = $selected;
		}

		// Placeholders for the IN clause — one per taxonomy.
		$in_placeholders = implode( ', ', array_fill( 0, count( $taxonomies ), '%s' ) );

		// Param order must match placeholder order in the SQL string:
		// 1. taxonomy slugs for IN(), 2. post_type, 3. filter join pairs.
		$query_params = array_merge( $taxonomies, array( $post_type ), $params );

		$sql = "
			SELECT DISTINCT tt.taxonomy, tt.term_id
			FROM {$wpdb->term_taxonomy} tt
			INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
			INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
			{$joins}
			WHERE tt.taxonomy IN ({$in_placeholders})
			  AND p.post_type = %s
			  AND p.post_status = 'publish'
			  {$wheres}
		";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is fully prepared above.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $query_params ) );

		// Group term IDs by taxonomy.
		$ids_by_taxonomy = array_fill_keys( $taxonomies, array() );
		foreach ( $rows as $row ) {
			if ( isset( $ids_by_taxonomy[ $row->taxonomy ] ) ) {
				$ids_by_taxonomy[ $row->taxonomy ][] = $row->term_id;
			}
		}

		// Fetch WP_Term objects per taxonomy (hits WP object cache when warm).
		$out = array_fill_keys( $taxonomies, array() );
		foreach ( $taxonomies as $taxonomy ) {
			$term_ids = $ids_by_taxonomy[ $taxonomy ];
			if ( empty( $term_ids ) ) {
				continue;
			}

			$terms = get_terms( array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'include'    => $term_ids,
				'orderby'    => 'name',
				'order'      => 'ASC',
			) );

			if ( ! is_wp_error( $terms ) ) {
				$out[ $taxonomy ] = $terms;
			}
		}

		return $out;
	}

	/* =========================================================
	 * Queries
	 * ========================================================= */

	/**
	 * Build grouped video data by category.
	 *
	 * @since 1.0.0
	 * @param array      $filters        Active filter term IDs.
	 * @param int        $per_category   Videos per section (-1 for all).
	 * @param string|int $max_categories Maximum sections to show ('' for all).
	 * @return array
	 */
	private static function query_grouped_videos( $filters, $per_category, $max_categories ) {
		$category_terms = self::get_terms_for_post_type_with_filters( 'video-category', 'video', $filters );

		if ( ! empty( $filters['video-category'] ) ) {
			$category_terms = array_values( array_filter( $category_terms, function( $t ) use ( $filters ) {
				return (int) $t->term_id === (int) $filters['video-category'];
			} ) );
		}

		if ( ! empty( $max_categories ) ) {
			$category_terms = array_slice( $category_terms, 0, (int) $max_categories );
		}

		if ( empty( $category_terms ) ) {
			return array();
		}

		$grouped = array();

		foreach ( $category_terms as $term ) {
			$videos = self::query_videos_for_section( $term->term_id, $filters, $per_category );
			if ( empty( $videos ) ) {
				continue;
			}

			$link      = get_term_link( $term );
			$grouped[] = array(
				'term_id'   => (int) $term->term_id,
				'term_name' => (string) $term->name,
				'term_link' => is_wp_error( $link ) ? '' : (string) $link,
				'items'     => $videos,
			);
		}

		return $grouped;
	}

	/**
	 * Query videos for a single category section.
	 *
	 * @since 1.0.0
	 * @param int   $video_category_term_id Video category term ID.
	 * @param array $filters               Active filter term IDs.
	 * @param int   $limit                 Max posts (-1 for all).
	 * @return array
	 */
	private static function query_videos_for_section( $video_category_term_id, $filters, $limit ) {
		$tax_query = array( 'relation' => 'AND' );

		$tax_query[] = array(
			'taxonomy' => 'video-category',
			'field'    => 'term_id',
			'terms'    => (int) $video_category_term_id,
		);

		if ( ! empty( $filters['market'] ) ) {
			$tax_query[] = array(
				'taxonomy' => 'market',
				'field'    => 'term_id',
				'terms'    => (int) $filters['market'],
			);
		}

		if ( ! empty( $filters['product'] ) ) {
			$tax_query[] = array(
				'taxonomy' => 'product',
				'field'    => 'term_id',
				'terms'    => (int) $filters['product'],
			);
		}

		if ( ! empty( $filters['project'] ) ) {
			$tax_query[] = array(
				'taxonomy' => 'project',
				'field'    => 'term_id',
				'terms'    => (int) $filters['project'],
			);
		}

		$q = new WP_Query( array(
			'post_type'      => 'video',
			'post_status'    => 'publish',
			'posts_per_page' => ( -1 === (int) $limit ? -1 : (int) $limit ),
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'fields'         => 'ids',
			'tax_query'      => $tax_query,
		) );

		if ( empty( $q->posts ) ) {
			return array();
		}

		$out = array();

		foreach ( $q->posts as $post_id ) {
			$title     = get_the_title( $post_id );
			$desc      = (string) self::get_raw_meta( $post_id, 'description' );
			$video_url = (string) self::get_raw_meta( $post_id, 'video_url' );

			$thumb_raw = self::get_raw_meta( $post_id, 'thumbnail' );
			$thumb_id  = self::resolve_thumb_id( $thumb_raw, $post_id );

			if ( ! $thumb_id ) {
				$thumb_id = (int) get_post_thumbnail_id( $post_id );
			}

			$thumb_src    = $thumb_id ? (string) wp_get_attachment_image_url( $thumb_id, 'medium_large' ) : '';
			$thumb_srcset = $thumb_id ? (string) wp_get_attachment_image_srcset( $thumb_id, 'medium_large' ) : '';
			$thumb_sizes  = $thumb_id ? (string) wp_get_attachment_image_sizes( $thumb_id, 'medium_large' ) : '';

			$alt = '';
			if ( $thumb_id ) {
				$alt = trim( (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) );
			}
			if ( '' === $alt ) {
				$alt = (string) $title;
			}

			$out[] = array(
				'id'           => (int) $post_id,
				'permalink'    => (string) get_permalink( $post_id ),
				'title'        => (string) $title,
				'description'  => (string) $desc,
				'video_url'    => (string) $video_url,
				'thumb_id'     => (int) $thumb_id,
				'thumb_src'    => $thumb_src,
				'thumb_srcset' => $thumb_srcset,
				'thumb_sizes'  => $thumb_sizes,
				'alt'          => (string) $alt,
				'date'         => (string) get_post_time( 'c', true, $post_id ),
			);
		}

		return $out;
	}

	/**
	 * Query terms for a taxonomy scoped to the video CPT and current filter context.
	 *
	 * Returns only terms that have at least one published video matching all active filters.
	 *
	 * @since 1.0.0
	 * @param string $taxonomy  Taxonomy slug.
	 * @param string $post_type Post type slug.
	 * @param array  $filters   Active filter term IDs.
	 * @return array            Array of WP_Term objects.
	 */
	private static function get_terms_for_post_type_with_filters( $taxonomy, $post_type, $filters ) {
		global $wpdb;

		$taxonomy  = sanitize_key( $taxonomy );
		$post_type = sanitize_key( $post_type );

		$joins  = '';
		$wheres = '';
		$params = array();

		$params[] = $taxonomy;
		$params[] = $post_type;

		$filter_tax_map = array(
			'market'         => 'market',
			'product'        => 'product',
			'project'        => 'project',
			'video-category' => 'video-category',
		);

		$alias_i = 0;

		foreach ( $filter_tax_map as $filter_key => $tax ) {
			$selected = isset( $filters[ $filter_key ] ) ? (int) $filters[ $filter_key ] : 0;
			if ( $selected <= 0 ) {
				continue;
			}

			$alias_i++;
			$tt = "ttf{$alias_i}";
			$tr = "trf{$alias_i}";

			$joins  .= " INNER JOIN {$wpdb->term_relationships} {$tr} ON {$tr}.object_id = p.ID ";
			$joins  .= " INNER JOIN {$wpdb->term_taxonomy} {$tt} ON {$tt}.term_taxonomy_id = {$tr}.term_taxonomy_id ";
			$wheres .= " AND {$tt}.taxonomy = %s AND {$tt}.term_id = %d ";

			$params[] = sanitize_key( $tax );
			$params[] = $selected;
		}

		$sql = "
			SELECT DISTINCT tt.term_id
			FROM {$wpdb->term_taxonomy} tt
			INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
			INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
			{$joins}
			WHERE tt.taxonomy = %s
			  AND p.post_type = %s
			  AND p.post_status = 'publish'
			  {$wheres}
		";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is fully prepared above.
		$term_ids = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );

		if ( empty( $term_ids ) ) {
			return array();
		}

		$terms = get_terms( array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'include'    => $term_ids,
			'orderby'    => 'name',
			'order'      => 'ASC',
		) );

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		return $terms;
	}

	/**
	 * Convert an array of WP_Term objects to a simple id/name array for JSON output.
	 *
	 * @since 1.0.0
	 * @param array $terms Array of WP_Term objects.
	 * @return array
	 */
	private static function terms_to_options( $terms ) {
		$out = array();

		foreach ( $terms as $t ) {
			$out[] = array(
				'id'   => (int) $t->term_id,
				'name' => (string) $t->name,
			);
		}

		return $out;
	}

	/* =========================================================
	 * Rendering
	 * ========================================================= */

	/**
	 * Render all category section groups as HTML.
	 *
	 * @since 1.0.0
	 * @param array $grouped     Grouped video data.
	 * @param int   $eager_first Number of thumbnails to eager-load.
	 * @return string
	 */
	private static function render_grouped_sections_html( $grouped, $eager_first ) {
		if ( empty( $grouped ) ) {
			return '<div class="svl__empty" role="status">No videos found for those filters.</div>';
		}

		$html         = '';
		$global_index = 0;

		foreach ( $grouped as $section ) {
			$term_name = $section['term_name'];
			$term_link = $section['term_link'];

			$html .= '<section class="svl__section" data-svl-section>';
			$html .= '<header class="svl__section-header">';
			$html .= '<h2 class="svl__section-title">' . esc_html( $term_name ) . '</h2>';

			if ( ! empty( $term_link ) ) {
				$html .= '<a class="svl__viewall" href="' . esc_url( $term_link ) . '">View All</a>';
			}

			$html .= '</header>';
			$html .= '<div class="svl__grid" role="list">';

			foreach ( $section['items'] as $item ) {
				$is_eager = ( $global_index < $eager_first );
				$global_index++;
				$html .= self::render_video_card( $item, $is_eager );
			}

			$html .= '</div>';
			$html .= '</section>';
		}

		return $html;
	}

	/**
	 * Render a single video card.
	 *
	 * Tries Nectar shortcode first, falls back to FancyBox-compatible markup,
	 * then a plain new-tab link, and finally image-only if no video URL exists.
	 *
	 * @since 1.0.0
	 * @param array $it       Video item data.
	 * @param bool  $is_eager Whether to eager-load the thumbnail.
	 * @return string
	 */
	private static function render_video_card( $it, $is_eager ) {
		$title     = esc_html( $it['title'] );
		$desc      = esc_html( $it['description'] );
		$thumb_id  = (int) $it['thumb_id'];
		$thumb_src = (string) $it['thumb_src'];
		$video_url = trim( (string) $it['video_url'] );

		$loading       = $is_eager ? 'eager' : 'lazy';
		$fetchpriority = $is_eager ? 'high' : 'auto';

		// Build the thumbnail image tag.
		if ( $thumb_src ) {
			$img = sprintf(
				'<img class="svl__img" src="%1$s" %2$s %3$s alt="%4$s" loading="%5$s" fetchpriority="%6$s" decoding="async" />',
				esc_url( $thumb_src ),
				( ! empty( $it['thumb_srcset'] ) ? 'srcset="' . esc_attr( $it['thumb_srcset'] ) . '"' : '' ),
				( ! empty( $it['thumb_sizes'] ) ? 'sizes="' . esc_attr( $it['thumb_sizes'] ) . '"' : '' ),
				esc_attr( $it['alt'] ),
				esc_attr( $loading ),
				esc_attr( $fetchpriority )
			);
		} else {
			$img = '<div class="svl__img-fallback" aria-hidden="true"></div>';
		}

		$thumb_html = '';

		// Primary: Nectar shortcode (uses attachment ID only).
		if ( '' !== $video_url && $thumb_id > 0 && shortcode_exists( 'nectar_video_lightbox' ) ) {
			$shortcode = sprintf(
				'[nectar_video_lightbox link_style="play_button_2" nectar_play_button_color="Default-Accent-Color" image_url="%1$d" hover_effect="default" box_shadow="none" border_radius="none" play_button_size="default" video_url="%2$s"]',
				(int) $thumb_id,
				esc_url( $video_url )
			);
			$thumb_html = do_shortcode( $shortcode );
		}

		// Secondary: FancyBox-compatible fallback if Nectar outputs nothing.
		if ( '' === trim( $thumb_html ) && '' !== $video_url ) {
			$thumb_html = sprintf(
				'<div class="nectar-video-box" data-color="default-accent-color" data-play-button-size="default" data-border-radius="none" data-hover="default" data-shadow="none">
<div class="inner-wrap img-loaded">
<a href="%1$s" class="full-link" target="_blank" rel="noopener" data-fancybox=""><span class="screen-reader-text">Play Video</span></a>
%3$s
<a href="%1$s" data-style="default" data-parent-hover="" data-font-style="p" data-color="default" class="play_button_2 large nectar_video_lightbox" target="_blank" rel="noopener" data-fancybox="">
<span>
<span class="screen-reader-text">Play Video %2$s</span>
<span class="play"><span class="inner-wrap inner">
<svg role="none" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="600px" height="800px" x="0px" y="0px" viewBox="0 0 600 800" enable-background="new 0 0 600 800" xml:space="preserve">
<path fill="none" d="M0-1.79v800L600,395L0-1.79z"></path>
</svg>
</span></span>
</span>
</a>
</div>
</div>',
				esc_url( $video_url ),
				esc_attr( $it['title'] ),
				$img
			);
		}

		// Tertiary: Open video in new tab.
		if ( '' === trim( $thumb_html ) && '' !== $video_url ) {
			$thumb_html = sprintf(
				'<a class="svl__lightbox" href="%1$s" target="_blank" rel="noopener" aria-label="Open video in a new tab: %2$s">%3$s<span class="svl__play" aria-hidden="true"></span></a>',
				esc_url( $video_url ),
				esc_attr( $it['title'] ),
				$img
			);
		}

		// Image-only fallback: no video URL.
		if ( '' === trim( $thumb_html ) ) {
			$thumb_html = $img;
		}

		$html  = '<article class="svl__card" role="listitem">';
		$html .= '<div class="svl__thumb">' . $thumb_html . '</div>';
		$html .= '<h3 class="svl__name">' . $title . '</h3>';
		if ( '' !== $desc ) {
			$html .= '<p class="svl__desc">' . $desc . '</p>';
		}
		$html .= '</article>';

		return $html;
	}

	/**
	 * Render <link rel="preload"> tags for the first N thumbnails.
	 *
	 * @since 1.0.0
	 * @param array $grouped       Grouped video data.
	 * @param int   $preload_first Number of thumbnails to preload.
	 * @return string
	 */
	private static function render_preload_links( $grouped, $preload_first ) {
		if ( $preload_first <= 0 || empty( $grouped ) ) {
			return '';
		}

		$urls = array();

		foreach ( $grouped as $section ) {
			foreach ( $section['items'] as $it ) {
				if ( ! empty( $it['thumb_src'] ) ) {
					$urls[] = $it['thumb_src'];
				}
				if ( count( $urls ) >= $preload_first ) {
					break 2;
				}
			}
		}

		if ( empty( $urls ) ) {
			return '';
		}

		$out = '';
		foreach ( $urls as $url ) {
			$out .= '<link rel="preload" as="image" href="' . esc_url( $url ) . '" fetchpriority="high" />' . "\n";
		}

		return $out;
	}

	/**
	 * Render a JSON-LD ItemList schema block for visible videos.
	 *
	 * @since 1.0.0
	 * @param array $grouped Grouped video data.
	 * @return string
	 */
	private static function render_schema_jsonld( $grouped ) {
		if ( empty( $grouped ) ) {
			return '';
		}

		$items = array();
		$pos   = 1;

		foreach ( $grouped as $section ) {
			foreach ( $section['items'] as $it ) {
				$items[] = array(
					'@type'    => 'ListItem',
					'position' => $pos++,
					'url'      => $it['permalink'],
					'item'     => array(
						'@type'        => 'VideoObject',
						'name'         => $it['title'],
						'description'  => ( '' !== trim( $it['description'] ) ? $it['description'] : $it['title'] ),
						'thumbnailUrl' => ( ! empty( $it['thumb_src'] ) ? $it['thumb_src'] : null ),
						'uploadDate'   => ( ! empty( $it['date'] ) ? $it['date'] : null ),
						'contentUrl'   => ( ! empty( $it['video_url'] ) ? $it['video_url'] : null ),
					),
				);
			}
		}

		$schema = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'ItemList',
			'itemListElement' => $items,
		);

		return '<script type="application/ld+json">' .
			wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) .
			'</script>';
	}

	/**
	 * Sanitize a value to a positive integer string, or empty string.
	 *
	 * @since 1.0.0
	 * @param mixed $val Input value.
	 * @return string    Positive integer as string, or ''.
	 */
	private static function sanitize_int_or_empty( $val ) {
		$val = trim( (string) $val );
		if ( '' === $val ) {
			return '';
		}
		return (string) absint( $val );
	}
}

Salient_Video_Library::init();
