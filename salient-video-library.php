<?php
/**
 * Plugin Name: Salient - Video Library (WPBakery Element)
 * Description: Filterable, category-grouped video library for "video" CPT with Salient video lightbox, cached AJAX, dependent filters, schema, and LCP tuning.
 * Version: 1.0.0
 * Author: Giant Creative Inc
 *
 * CPT:
 * - video
 *
 * Taxonomies:
 * - video-category (video category taxonomy)
 * - market
 * - product
 * - project
 *
 * Fields:
 * - Post title: the_title()
 * - ACF: description (textarea)
 * - ACF: video_url (url)
 * - ACF: thumbnail (image) -> attachment ID recommended
 */

defined('ABSPATH') || exit;

final class Salient_Video_Library {

	const SHORTCODE     = 'svl_video_library';

	const CACHE_PREFIX  = 'svl_';
	const CACHE_TTL_QUERY = 10 * MINUTE_IN_SECONDS;
	const CACHE_TTL_TERMS = 60 * MINUTE_IN_SECONDS;

	const STYLE_HANDLE  = 'svl-video-library';
	const SCRIPT_HANDLE = 'svl-video-library';

	public static function init() {
		add_action('init', [__CLASS__, 'register_shortcode']);
		add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);

		add_action('wp_ajax_svl_filter', [__CLASS__, 'ajax_filter']);
		add_action('wp_ajax_nopriv_svl_filter', [__CLASS__, 'ajax_filter']);

		add_action('vc_before_init', [__CLASS__, 'register_vc_element']);

		// Clear caches when videos change (keeps dropdowns accurate).
		add_action('save_post_video', [__CLASS__, 'clear_caches']);
		add_action('trashed_post', [__CLASS__, 'clear_caches']);
		add_action('deleted_post', [__CLASS__, 'clear_caches']);
	}

	public static function register_shortcode() {
		add_shortcode(self::SHORTCODE, [__CLASS__, 'render_shortcode']);
	}

	public static function register_assets() {
		$url = plugin_dir_url(__FILE__);

		wp_register_style(
			self::STYLE_HANDLE,
			$url . 'assets/video-library.css',
			[],
			'1.0.0'
		);

		wp_register_script(
			self::SCRIPT_HANDLE,
			$url . 'assets/video-library.js',
			['jquery'],
			'1.0.0',
			true
		);
	}

	public static function register_vc_element() {
		if (!function_exists('vc_map')) return;

		vc_map([
			'name'        => 'Video Library (Grouped)',
			'base'        => self::SHORTCODE,
			'category'    => 'Content',
			'description' => 'Grouped video library for the "video" CPT with filters + Salient lightbox.',
			'params'      => [
				[
					'type'        => 'textfield',
					'heading'     => 'Max categories (optional)',
					'param_name'  => 'max_categories',
					'description' => 'Leave blank for all categories.',
				],
				[
					'type'        => 'textfield',
					'heading'     => 'Videos per category',
					'param_name'  => 'per_category',
					'description' => 'Default 3.',
				],
				[
					'type'        => 'textfield',
					'heading'     => 'Eager-load first N thumbnails',
					'param_name'  => 'eager_first',
					'description' => 'Default 3 (first row). Helps LCP.',
				],
				[
					'type'        => 'textfield',
					'heading'     => 'Preload first N thumbnails',
					'param_name'  => 'preload_first',
					'description' => 'Default 1. Adds <link rel="preload"> for the first thumbnails.',
				],
			],
		]);
	}

	public static function clear_caches() {
		// Terms (scoped to post type video)
		delete_transient('svl_terms_market_video');
		delete_transient('svl_terms_product_video');
		delete_transient('svl_terms_project_video');
		delete_transient('svl_terms_video-category_video');

		// Queries (unknown keys) — cheap sweep: delete option-based transients is heavy.
		// Keep it simple: bump a version key to invalidate all query transients.
		$ver = (int) get_option('svl_cache_ver', 1);
		update_option('svl_cache_ver', $ver + 1, false);
	}

	/**
	 * Shortcode renderer.
	 */
	public static function render_shortcode($atts) {
		$atts = shortcode_atts([
			'max_categories' => '',
			'per_category'   => '3',
			'eager_first'    => '3',
			'preload_first'  => '1',
		], $atts, self::SHORTCODE);

		$max_categories = self::sanitize_int_or_empty($atts['max_categories']);
		$per_category   = max(1, absint($atts['per_category']));
		$eager_first    = max(0, absint($atts['eager_first']));
		$preload_first  = max(0, absint($atts['preload_first']));

		// Enqueue only when element exists on page.
		wp_enqueue_style(self::STYLE_HANDLE);
		wp_enqueue_script(self::SCRIPT_HANDLE);

		wp_localize_script(self::SCRIPT_HANDLE, 'SVL', [
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce'   => wp_create_nonce('svl_nonce'),
			'strings' => [
				'loading'   => 'Loading videos…',
				'noResults' => 'No videos found for those filters.',
			],
			'config' => [
				'perCategory'  => $per_category,
				'maxCategories'=> $max_categories,
				'eagerFirst'   => $eager_first,
				'preloadFirst' => $preload_first,
			],
		]);

		// Initial filter values (none).
		$filters = [
			'market'        => 0,
			'product'       => 0,
			'project'       => 0,
			'video-category'=> 0,
		];

		// Initial terms (must be scoped to "video" post type, not other CPTs).
		$terms = self::get_filter_terms_cached($filters);

		// Initial grouped results.
		$grouped = self::get_grouped_videos_cached($filters, $per_category, $max_categories);

		// Optional LCP preload tags (first N thumbs).
		$preloads = self::render_preload_links($grouped, $preload_first);

		ob_start();
		?>
		<div class="svl" data-svl>
			<?php echo $preloads; ?>

			<div class="svl__filters" aria-label="Video filters">
				<div class="svl__filters-label" aria-hidden="true">Filter by:</div>

				<label class="svl__sr-only" for="svl-market">Market</label>
				<select id="svl-market" class="svl__select" data-svl-filter="market">
					<option value="0">Market</option>
					<?php foreach ($terms['market'] as $t) : ?>
						<option value="<?php echo esc_attr($t->term_id); ?>"><?php echo esc_html($t->name); ?></option>
					<?php endforeach; ?>
				</select>

				<label class="svl__sr-only" for="svl-product">Product</label>
				<select id="svl-product" class="svl__select" data-svl-filter="product">
					<option value="0">Product</option>
					<?php foreach ($terms['product'] as $t) : ?>
						<option value="<?php echo esc_attr($t->term_id); ?>"><?php echo esc_html($t->name); ?></option>
					<?php endforeach; ?>
				</select>

				<label class="svl__sr-only" for="svl-project">Project</label>
				<select id="svl-project" class="svl__select" data-svl-filter="project">
					<option value="0">Project</option>
					<?php foreach ($terms['project'] as $t) : ?>
						<option value="<?php echo esc_attr($t->term_id); ?>"><?php echo esc_html($t->name); ?></option>
					<?php endforeach; ?>
				</select>

				<label class="svl__sr-only" for="svl-category">Category</label>
				<select id="svl-category" class="svl__select" data-svl-filter="video-category">
					<option value="0">Category</option>
					<?php foreach ($terms['video-category'] as $t) : ?>
						<option value="<?php echo esc_attr($t->term_id); ?>"><?php echo esc_html($t->name); ?></option>
					<?php endforeach; ?>
				</select>

				<button type="button" class="svl__clear" data-svl-clear hidden>
					Clear Filters <span aria-hidden="true">×</span>
				</button>

				<div class="svl__status" role="status" aria-live="polite" aria-busy="false" data-svl-status>
					<span class="svl__loader" data-svl-loader hidden>
						<span class="svl__spinner" aria-hidden="true"></span>
						<span class="svl__loader-text">Loading videos…</span>
					</span>
				</div>
			</div>

			<div class="svl__results" data-svl-results>
				<?php echo self::render_grouped_sections_html($grouped, $eager_first); ?>
			</div>

			<?php echo self::render_schema_jsonld($grouped); ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * AJAX handler: returns updated dropdown options + updated grouped HTML + schema.
	 */
	public static function ajax_filter() {
		check_ajax_referer('svl_nonce', 'nonce');

		$filters = [
			'market'         => isset($_POST['market']) ? absint($_POST['market']) : 0,
			'product'        => isset($_POST['product']) ? absint($_POST['product']) : 0,
			'project'        => isset($_POST['project']) ? absint($_POST['project']) : 0,
			'video-category' => isset($_POST['videoCategory']) ? absint($_POST['videoCategory']) : 0,
		];

		$per_category   = isset($_POST['perCategory']) ? max(1, absint($_POST['perCategory'])) : 3;
		$max_categories = isset($_POST['maxCategories']) ? self::sanitize_int_or_empty($_POST['maxCategories']) : '';
		$eager_first    = isset($_POST['eagerFirst']) ? max(0, absint($_POST['eagerFirst'])) : 3;

		$terms   = self::get_filter_terms_cached($filters);
		$grouped = self::get_grouped_videos_cached($filters, $per_category, $max_categories);

		wp_send_json_success([
			'terms' => [
				'market'        => self::terms_to_options($terms['market']),
				'product'       => self::terms_to_options($terms['product']),
				'project'       => self::terms_to_options($terms['project']),
				'videoCategory' => self::terms_to_options($terms['video-category']),
			],
			'html'   => self::render_grouped_sections_html($grouped, $eager_first),
			'schema' => self::render_schema_jsonld($grouped),
			'countCategories' => count($grouped),
		]);
	}

	/* =========================
	 * Data / Caching
	 * ========================= */

	private static function get_cache_ver() {
		return (int) get_option('svl_cache_ver', 1);
	}

	/**
	 * Cached grouped results for current filters.
	 */
	private static function get_grouped_videos_cached($filters, $per_category, $max_categories) {
		$key = self::CACHE_PREFIX . 'grouped_' . md5(wp_json_encode([
			'ver' => self::get_cache_ver(),
			'f'   => $filters,
			'per' => $per_category,
			'max' => $max_categories,
		]));

		$cached = get_transient($key);
		if ($cached !== false) return $cached;

		$grouped = self::query_grouped_videos($filters, $per_category, $max_categories);
		set_transient($key, $grouped, self::CACHE_TTL_QUERY);

		return $grouped;
	}

	/**
	 * Cached terms for each filter (dependent dropdowns).
	 * Returns ONLY terms that have at least 1 matching VIDEO given the current filter context.
	 */
	private static function get_filter_terms_cached($filters) {
		$key = self::CACHE_PREFIX . 'filter_terms_' . md5(wp_json_encode([
			'ver' => self::get_cache_ver(),
			'f'   => $filters,
		]));

		$cached = get_transient($key);
		if ($cached !== false) return $cached;

		$out = [
			'market'        => self::get_terms_for_post_type_with_filters('market', 'video', $filters),
			'product'       => self::get_terms_for_post_type_with_filters('product', 'video', $filters),
			'project'       => self::get_terms_for_post_type_with_filters('project', 'video', $filters),
			'video-category'=> self::get_terms_for_post_type_with_filters('video-category', 'video', $filters),
		];

		set_transient($key, $out, self::CACHE_TTL_TERMS);
		return $out;
	}

	/**
	 * Query grouped sections:
	 * - Sections are video-category terms (respecting filter if selected).
	 * - Each section shows first N videos (per_category), ordered newest first.
	 */
	private static function query_grouped_videos($filters, $per_category, $max_categories) {
		$category_terms = self::get_terms_for_post_type_with_filters('video-category', 'video', $filters);

		if (!empty($filters['video-category'])) {
			// If a category is selected, reduce to that one (if it exists in scoped terms).
			$category_terms = array_values(array_filter($category_terms, function($t) use ($filters) {
				return (int) $t->term_id === (int) $filters['video-category'];
			}));
		}

		if (!empty($max_categories)) {
			$category_terms = array_slice($category_terms, 0, (int) $max_categories);
		}

		if (empty($category_terms)) return [];

		$grouped = [];

		foreach ($category_terms as $term) {
			$videos = self::query_videos_for_section($term->term_id, $filters, $per_category);
			if (empty($videos)) continue;

			$grouped[] = [
				'term_id'   => (int) $term->term_id,
				'term_name' => (string) $term->name,
				'term_link' => (string) get_term_link($term),
				'items'     => $videos,
			];
		}

		return $grouped;
	}

	/**
	 * Query N videos for a section category + other filters.
	 */
	private static function query_videos_for_section($video_category_term_id, $filters, $limit) {
		$tax_query = ['relation' => 'AND'];

		// Always scope to the section term.
		$tax_query[] = [
			'taxonomy' => 'video-category',
			'field'    => 'term_id',
			'terms'    => (int) $video_category_term_id,
		];

		// Apply other filters if selected.
		if (!empty($filters['market'])) {
			$tax_query[] = [
				'taxonomy' => 'market',
				'field'    => 'term_id',
				'terms'    => (int) $filters['market'],
			];
		}
		if (!empty($filters['product'])) {
			$tax_query[] = [
				'taxonomy' => 'product',
				'field'    => 'term_id',
				'terms'    => (int) $filters['product'],
			];
		}
		if (!empty($filters['project'])) {
			$tax_query[] = [
				'taxonomy' => 'project',
				'field'    => 'term_id',
				'terms'    => (int) $filters['project'],
			];
		}

		$q = new WP_Query([
			'post_type'      => 'video',
			'post_status'    => 'publish',
			'posts_per_page' => (int) $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'fields'         => 'ids',
			'tax_query'      => $tax_query,
		]);

		if (empty($q->posts)) return [];

		$out = [];
		foreach ($q->posts as $post_id) {
			$title = get_the_title($post_id);

			$desc = function_exists('get_field') ? (string) get_field('description', $post_id) : '';
			$video_url = function_exists('get_field') ? (string) get_field('video_url', $post_id) : '';

			$thumb_id = function_exists('get_field') ? (int) get_field('thumbnail', $post_id) : 0;
			if (!$thumb_id) {
				// Optional fallback: featured image if thumbnail field not set.
				$thumb_id = (int) get_post_thumbnail_id($post_id);
			}

			$thumb_src = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'medium_large') : '';
			$thumb_srcset = $thumb_id ? wp_get_attachment_image_srcset($thumb_id, 'medium_large') : '';
			$thumb_sizes = $thumb_id ? wp_get_attachment_image_sizes($thumb_id, 'medium_large') : '';

			$alt = '';
			if ($thumb_id) {
				$alt = trim((string) get_post_meta($thumb_id, '_wp_attachment_image_alt', true));
			}
			if ($alt === '') $alt = $title;

			$out[] = [
				'id'           => (int) $post_id,
				'permalink'    => (string) get_permalink($post_id),
				'title'        => (string) $title,
				'description'  => (string) $desc,
				'video_url'    => (string) $video_url,
				'thumb_id'     => (int) $thumb_id,
				'thumb_src'    => (string) $thumb_src,
				'thumb_srcset' => (string) $thumb_srcset,
				'thumb_sizes'  => (string) $thumb_sizes,
				'alt'          => (string) $alt,
				'date'         => (string) get_the_date('c', $post_id),
			];
		}

		return $out;
	}

	/**
	 * Term query that only returns terms used by post_type "video",
	 * AND only those that still have matches under current filter context.
	 *
	 * This avoids showing terms that only have posts in other CPTs.
	 */
	private static function get_terms_for_post_type_with_filters($taxonomy, $post_type, $filters) {
		global $wpdb;

		$taxonomy = sanitize_key($taxonomy);
		$post_type = sanitize_key($post_type);

		// Build SQL constraints for the other filters.
		// We do this by requiring the candidate posts to match the selected term IDs.
		$joins = "";
		$wheres = "";
		$params = [];

		// Base: taxonomy we are retrieving.
		$params[] = $taxonomy;
		$params[] = $post_type;

		// Filter helpers: join term relationships for each selected filter taxonomy.
		// Only add these when a filter has a value.
		$filter_tax_map = [
			'market'         => 'market',
			'product'        => 'product',
			'project'        => 'project',
			'video-category' => 'video-category',
		];

		$alias_i = 0;
		foreach ($filter_tax_map as $filter_key => $tax) {
			$selected = isset($filters[$filter_key]) ? (int) $filters[$filter_key] : 0;
			if ($selected <= 0) continue;

			// Important: when building options for taxonomy X, we still keep X’s filter applied.
			// That matches your requirement: dropdowns should only show terms that still produce results.
			$alias_i++;

			$tt = "ttf{$alias_i}";
			$tr = "trf{$alias_i}";

			$joins .= " INNER JOIN {$wpdb->term_relationships} {$tr} ON {$tr}.object_id = p.ID ";
			$joins .= " INNER JOIN {$wpdb->term_taxonomy} {$tt} ON {$tt}.term_taxonomy_id = {$tr}.term_taxonomy_id ";

			$wheres .= " AND {$tt}.taxonomy = %s AND {$tt}.term_id = %d ";

			$params[] = sanitize_key($tax);
			$params[] = $selected;
		}

		// Term IDs that are attached to published posts of the target post type AND match filters.
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

		$term_ids = $wpdb->get_col($wpdb->prepare($sql, $params));
		if (empty($term_ids)) return [];

		$terms = get_terms([
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'include'    => $term_ids,
			'orderby'    => 'name',
			'order'      => 'ASC',
		]);

		if (is_wp_error($terms)) return [];
		return $terms;
	}

	private static function terms_to_options($terms) {
		$out = [];
		foreach ($terms as $t) {
			$out[] = [
				'id'   => (int) $t->term_id,
				'name' => (string) $t->name,
			];
		}
		return $out;
	}

	/* =========================
	 * Rendering
	 * ========================= */

	private static function render_grouped_sections_html($grouped, $eager_first) {
		if (empty($grouped)) {
			return '<div class="svl__empty" role="status">No videos found for those filters.</div>';
		}

		$html = '';
		$globalIndex = 0;

		foreach ($grouped as $section) {
			$term_name = $section['term_name'];
			$term_link = $section['term_link'];
			if (is_wp_error($term_link)) $term_link = '';

			$html .= '<section class="svl__section" data-svl-section>';
			$html .= '<header class="svl__section-header">';
			$html .= '<h2 class="svl__section-title">' . esc_html($term_name) . '</h2>';

			if (!empty($term_link)) {
				$html .= '<a class="svl__viewall" href="' . esc_url($term_link) . '">View All</a>';
			}
			$html .= '</header>';

			$html .= '<div class="svl__grid" role="list">';

			foreach ($section['items'] as $item) {
				$is_eager = ($globalIndex < $eager_first);
				$globalIndex++;

				$html .= self::render_video_card($item, $is_eager);
			}

			$html .= '</div>';
			$html .= '</section>';
		}

		return $html;
	}

	/**
	 * Renders one video card using Salient's nectar_video_lightbox shortcode.
	 * Using do_shortcode keeps it consistent with Salient styling/JS.
	 */
	private static function render_video_card($it, $is_eager) {
		$thumb_id = (int) $it['thumb_id'];
		$thumb_src = (string) $it['thumb_src'];

		// LCP tuning for the first cards:
		// - eager loading + fetchpriority
		$loading = $is_eager ? 'eager' : 'lazy';
		$fetchpriority = $is_eager ? 'high' : 'auto';

		// Build the Salient lightbox shortcode.
		// Note: hover_effect attribute name in your example has a typo ("defaut").
		// We'll keep "default" for safety. If your install expects "defaut", swap it.
		$shortcode = sprintf(
			'[nectar_video_lightbox link_style="play_button_2" nectar_play_button_color="Default-Accent-Color" image_url="%1$d" hover_effect="default" box_shadow="none" border_radius="none" play_button_size="default" video_url="%2$s"]',
			$thumb_id,
			esc_url($it['video_url'])
		);

		// Title + description are plain text under the thumbnail.
		$title = esc_html($it['title']);
		$desc  = esc_html($it['description']);

		// We also output an img preload-friendly tag wrapper that Salient will render internally,
		// but the shortcode controls the actual thumbnail output.
		// If thumb is missing, show a simple fallback link.
		$thumb_html = '';
		if ($thumb_id > 0 && $thumb_src !== '') {
			$thumb_html = do_shortcode($shortcode);

			// Add a hidden, semantic preview image for SEO/a11y fallbacks (doesn't show).
			// This helps if the shortcode output is JS-heavy on some setups.
			$thumb_html .= sprintf(
				'<img class="svl__sr-only" src="%1$s" alt="%2$s" loading="%3$s" fetchpriority="%4$s" />',
				esc_url($thumb_src),
				esc_attr($it['alt']),
				esc_attr($loading),
				esc_attr($fetchpriority)
			);
		} else {
			$thumb_html = '<a class="svl__thumb-fallback" href="' . esc_url($it['video_url']) . '" target="_blank" rel="noopener">Watch video</a>';
		}

		// Card markup
		$html  = '<article class="svl__card" role="listitem">';
		$html .= '<div class="svl__thumb" aria-label="Play video: ' . esc_attr($it['title']) . '">';
		$html .= $thumb_html;
		$html .= '</div>';
		$html .= '<h3 class="svl__name">' . $title . '</h3>';
		if ($desc !== '') {
			$html .= '<p class="svl__desc">' . $desc . '</p>';
		}
		$html .= '</article>';

		return $html;
	}

	/**
	 * Preload first N thumbnails to help LCP.
	 * Only preloads if we have direct thumbnail URLs.
	 */
	private static function render_preload_links($grouped, $preload_first) {
		if ($preload_first <= 0) return '';
		if (empty($grouped)) return '';

		$urls = [];
		foreach ($grouped as $section) {
			foreach ($section['items'] as $it) {
				if (!empty($it['thumb_src'])) {
					$urls[] = $it['thumb_src'];
				}
				if (count($urls) >= $preload_first) break 2;
			}
		}

		if (empty($urls)) return '';

		$out = '';
		foreach ($urls as $u) {
			$out .= '<link rel="preload" as="image" href="' . esc_url($u) . '" fetchpriority="high" />' . "\n";
		}
		return $out;
	}

	/**
	 * SEO JSON-LD:
	 * - One ItemList containing VideoObject entries for the currently visible videos.
	 */
	private static function render_schema_jsonld($grouped) {
		if (empty($grouped)) return '';

		$items = [];
		$pos = 1;

		foreach ($grouped as $section) {
			foreach ($section['items'] as $it) {
				$items[] = [
					'@type' => 'ListItem',
					'position' => $pos++,
					'url' => $it['permalink'],
					'item' => [
						'@type' => 'VideoObject',
						'name' => $it['title'],
						'description' => (trim($it['description']) !== '' ? $it['description'] : $it['title']),
						'thumbnailUrl' => (!empty($it['thumb_src']) ? $it['thumb_src'] : null),
						'uploadDate' => (!empty($it['date']) ? $it['date'] : null),
						'contentUrl' => (!empty($it['video_url']) ? $it['video_url'] : null),
					],
				];
			}
		}

		$schema = [
			'@context' => 'https://schema.org',
			'@type' => 'ItemList',
			'itemListElement' => $items,
		];

		return '<script type="application/ld+json">' .
			wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) .
			'</script>';
	}

	private static function sanitize_int_or_empty($val) {
		$val = trim((string) $val);
		if ($val === '') return '';
		return (string) absint($val);
	}
}

Salient_Video_Library::init();