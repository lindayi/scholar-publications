<?php
/**
 * The [scholar_publications] shortcode and its markup.
 *
 * The list is rendered server side so it is indexable and works without
 * JavaScript. The bundled script only layers search, filtering and sorting on
 * top of the existing DOM.
 *
 * @package scholar-publications
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders cached Google Scholar data.
 */
class SchPub_Shortcode {

	/**
	 * Human readable labels for the inferred publication types.
	 *
	 * @return array
	 */
	public static function type_labels() {
		return array(
			'journal'    => __( 'Journal', 'scholar-publications' ),
			'conference' => __( 'Conference', 'scholar-publications' ),
			'preprint'   => __( 'Preprint', 'scholar-publications' ),
			'patent'     => __( 'Patent', 'scholar-publications' ),
			'thesis'     => __( 'Thesis', 'scholar-publications' ),
			'other'      => __( 'Other', 'scholar-publications' ),
		);
	}

	/**
	 * Register the shortcode and its assets.
	 */
	public static function init() {
		add_shortcode( 'scholar_publications', array( __CLASS__, 'render' ) );
		add_action( 'init', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * Register the stylesheet and script.
	 */
	public static function register_assets() {
		wp_register_style( 'schpub', SCHPUB_URL . 'assets/css/app.css', array(), SCHPUB_VERSION );
		wp_register_script( 'schpub', SCHPUB_URL . 'assets/js/app.js', array(), SCHPUB_VERSION, true );
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'limit'    => 0,
				'type'     => '',
				'stats'    => null,
				'chart'    => null,
				'controls' => 1,
				'group'    => null,
				'sort'     => '',
				'layout'   => '',
			),
			$atts,
			'scholar_publications'
		);

		$snapshot = SchPub_Store::get_snapshot();
		$items    = (array) $snapshot['publications'];

		if ( ! $items ) {
			return self::render_empty( $snapshot );
		}

		if ( '' !== $atts['type'] ) {
			$wanted = array_map( 'trim', explode( ',', strtolower( $atts['type'] ) ) );
			$items  = array_values(
				array_filter(
					$items,
					static function ( $item ) use ( $wanted ) {
						return in_array( $item['type'], $wanted, true );
					}
				)
			);
		}

		$limit = absint( $atts['limit'] );
		if ( $limit > 0 ) {
			$items = array_slice( $items, 0, $limit );
		}

		$show_stats = null === $atts['stats'] ? (bool) schpub_setting( 'show_stats', 1 ) : (bool) intval( $atts['stats'] );
		$show_chart = null === $atts['chart'] ? (bool) schpub_setting( 'show_chart', 1 ) : (bool) intval( $atts['chart'] );
		$group      = null === $atts['group'] ? (bool) schpub_setting( 'group_by_year', 1 ) : (bool) intval( $atts['group'] );
		$controls   = (bool) intval( $atts['controls'] );
		$sort       = '' !== $atts['sort'] ? sanitize_key( $atts['sort'] ) : (string) schpub_setting( 'sort_default', 'year' );

		$layout = '' !== $atts['layout'] ? sanitize_key( $atts['layout'] ) : (string) schpub_setting( 'layout', 'sidebar' );
		if ( ! in_array( $layout, array( 'sidebar', 'stacked' ), true ) ) {
			$layout = 'sidebar';
		}

		// A rail is only worth building when there is something to put in it.
		$rail = ( 'sidebar' === $layout ) && ( $show_stats || $show_chart || $controls );

		wp_enqueue_style( 'schpub' );
		wp_enqueue_script( 'schpub' );

		$stats  = (array) $snapshot['stats'];
		$author = (array) $snapshot['author'];
		$facets = SchPub_Store::build_facets( $items );

		$classes = array( 'schpub' );
		if ( $rail ) {
			// Reclaim the parent column: the theme caps normal blocks at its
			// content size, which would otherwise leave the list too narrow once
			// the rail takes its share.
			$classes[] = 'schpub-wide';
			$classes[] = 'schpub-has-rail';
		}
		// Set server side as well so the year never flashes before the script runs.
		if ( $group && in_array( $sort, array( 'year', 'oldest' ), true ) ) {
			$classes[] = 'schpub-headings-on';
		}

		ob_start();
		?>
		<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" data-sort="<?php echo esc_attr( $sort ); ?>" data-group="<?php echo $group ? '1' : '0'; ?>">
			<?php if ( $rail ) : ?>
			<aside class="schpub-rail" aria-label="<?php esc_attr_e( 'Publication metrics and filters', 'scholar-publications' ); ?>">
			<?php endif; ?>

			<?php
			if ( $show_stats ) {
				self::render_stats( $stats, $author, (int) $snapshot['updated'], count( $items ) );
			}
			if ( $show_chart && ! empty( $stats['citations_year'] ) ) {
				self::render_chart( (array) $stats['citations_year'] );
			}
			if ( $controls ) {
				self::render_controls( $facets, $sort, $group, count( $items ) );
			}
			?>

			<?php if ( $rail ) : ?>
			</aside>
			<div class="schpub-main">
			<?php endif; ?>

			<?php self::render_list( $items, $group ); ?>

			<?php if ( $rail ) : ?>
			</div>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Message shown before the first successful sync.
	 *
	 * @param array $snapshot Stored snapshot.
	 * @return string
	 */
	private static function render_empty( $snapshot ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '<p class="schpub-empty">' . esc_html__( 'Publications are being updated. Please check back shortly.', 'scholar-publications' ) . '</p>';
		}

		$message = __( 'No Google Scholar data has been cached yet. Open Settings → Scholar Publications and run a sync.', 'scholar-publications' );
		if ( ! empty( $snapshot['last_error'] ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: error message. */
				__( 'Last error: %s', 'scholar-publications' ),
				$snapshot['last_error']
			);
		}

		return '<p class="schpub-empty">' . esc_html( $message ) . '</p>';
	}

	/**
	 * Metrics cards and the profile line.
	 *
	 * @param array $stats   Statistics block.
	 * @param array $author  Author block.
	 * @param int   $updated Last sync timestamp.
	 * @param int   $count   Number of rendered publications.
	 */
	private static function render_stats( $stats, $author, $updated, $count ) {
		$cards = array(
			array( __( 'Publications', 'scholar-publications' ), $count, '' ),
			array( __( 'Citations', 'scholar-publications' ), isset( $stats['citations'] ) ? (int) $stats['citations'] : 0, '' ),
			array( __( 'h-index', 'scholar-publications' ), isset( $stats['h_index'] ) ? (int) $stats['h_index'] : 0, __( 'h papers each cited at least h times', 'scholar-publications' ) ),
			array( __( 'i10-index', 'scholar-publications' ), isset( $stats['i10_index'] ) ? (int) $stats['i10_index'] : 0, __( 'papers with at least 10 citations', 'scholar-publications' ) ),
		);
		?>
		<dl class="schpub-stats">
			<?php foreach ( $cards as $card ) : ?>
				<div class="schpub-stat"<?php echo '' !== $card[2] ? ' title="' . esc_attr( $card[2] ) . '"' : ''; ?>>
					<dt class="schpub-stat-label"><?php echo esc_html( $card[0] ); ?></dt>
					<dd class="schpub-stat-value" data-count="<?php echo esc_attr( (string) $card[1] ); ?>">
						<?php echo esc_html( number_format_i18n( $card[1] ) ); ?>
					</dd>
				</div>
			<?php endforeach; ?>
		</dl>
		<p class="schpub-meta">
			<?php if ( ! empty( $author['profile_url'] ) ) : ?>
				<a class="schpub-profile-link" href="<?php echo esc_url( $author['profile_url'] ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Google Scholar profile', 'scholar-publications' ); ?>
				</a>
			<?php endif; ?>
			<?php if ( $updated ) : ?>
				<span class="schpub-updated">
					<?php
					printf(
						/* translators: %s: human readable time difference. */
						esc_html__( 'Updated %s ago', 'scholar-publications' ),
						esc_html( human_time_diff( $updated, time() ) )
					);
					?>
				</span>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Choose round axis values for a chart, in the style of Google Scholar.
	 *
	 * Picks the smallest familiar step (1, 2, 5, 10, 25, 100 …) that divides the
	 * range into roughly four bands, so a peak of 429 yields 100/200/300/400.
	 *
	 * @param int $max    Largest value in the series.
	 * @param int $target Preferred number of gridlines.
	 * @return array Tick values below the maximum.
	 */
	private static function chart_ticks( $max, $target = 4 ) {
		$max = (int) $max;
		if ( $max <= 1 ) {
			return array();
		}

		$steps = array( 1, 2, 5, 10, 20, 25, 50, 100, 200, 250, 500, 1000, 2000, 2500, 5000, 10000 );
		$step  = end( $steps );

		foreach ( $steps as $candidate ) {
			if ( ( $max / $candidate ) <= ( $target + 1 ) ) {
				$step = $candidate;
				break;
			}
		}

		$ticks = array();
		for ( $value = $step; $value < $max; $value += $step ) {
			$ticks[] = $value;
		}

		return $ticks;
	}

	/**
	 * Citations-per-year bar chart, drawn as inline SVG.
	 *
	 * Gridlines and their labels are plain positioned elements rather than SVG
	 * content: the plot is stretched with preserveAspectRatio="none", which would
	 * distort any text drawn inside it.
	 *
	 * @param array $series Year => citation count.
	 */
	private static function render_chart( $series ) {
		ksort( $series, SORT_NUMERIC );
		$max = max( array_map( 'intval', $series ) );
		if ( $max <= 0 ) {
			return;
		}

		$count  = count( $series );
		$width  = 100;
		$gap    = $count > 1 ? 1.6 : 0;
		$bar_w  = ( $width - ( $gap * ( $count - 1 ) ) ) / max( 1, $count );
		$height = 100;
		$index  = 0;
		$years  = array_keys( $series );
		$ticks  = self::chart_ticks( $max );
		?>
		<figure class="schpub-chart">
			<figcaption>
				<span class="schpub-chart-label"><?php esc_html_e( 'Citations per year', 'scholar-publications' ); ?></span>
				<span class="schpub-chart-readout" aria-live="polite" data-idle=""></span>
			</figcaption>
			<div class="schpub-chart-body">
				<?php foreach ( $ticks as $tick ) : ?>
					<?php $offset = ( $tick / $max ) * 100; ?>
					<span class="schpub-gridline" style="bottom:<?php echo esc_attr( (string) round( $offset, 3 ) ); ?>%" aria-hidden="true"></span>
					<span class="schpub-gridtick" style="bottom:<?php echo esc_attr( (string) round( $offset, 3 ) ); ?>%" aria-hidden="true">
						<?php echo esc_html( number_format_i18n( $tick ) ); ?>
					</span>
				<?php endforeach; ?>

				<div class="schpub-chart-plot">
					<svg viewBox="0 0 100 100" preserveAspectRatio="none" role="img"
						aria-label="<?php esc_attr_e( 'Bar chart of citations received each year', 'scholar-publications' ); ?>">
						<?php
						foreach ( $series as $year => $value ) {
							$value = (int) $value;
							$bar_h = $max > 0 ? ( $value / $max ) * $height : 0;
							$x     = $index * ( $bar_w + $gap );
							$y     = $height - $bar_h;
							$index++;
							printf(
								'<rect class="schpub-bar" x="%1$s" y="%2$s" width="%3$s" height="%4$s" rx="0.6" tabindex="0" data-year="%5$s" data-value="%6$s" data-readout="%7$s"><title>%7$s</title></rect>',
								esc_attr( round( $x, 3 ) ),
								esc_attr( round( $y, 3 ) ),
								esc_attr( round( $bar_w, 3 ) ),
								esc_attr( round( max( $bar_h, 0.5 ), 3 ) ),
								esc_attr( (string) $year ),
								esc_attr( (string) $value ),
								esc_attr(
									sprintf(
										/* translators: 1: year, 2: citation count. */
										__( '%1$s: %2$s citations', 'scholar-publications' ),
										$year,
										number_format_i18n( $value )
									)
								)
							);
						}
						?>
					</svg>
				</div>
			</div>
			<div class="schpub-chart-axis">
				<span><?php echo esc_html( (string) reset( $years ) ); ?></span>
				<span><?php echo esc_html( (string) end( $years ) ); ?></span>
			</div>
		</figure>
		<?php
	}

	/**
	 * Search box, type filters and sort control.
	 *
	 * @param array  $facets Available filter values.
	 * @param string $sort   Active sort key.
	 * @param bool   $group  Whether year grouping is on.
	 * @param int    $total  Total publications rendered.
	 */
	private static function render_controls( $facets, $sort, $group, $total ) {
		$labels = self::type_labels();
		?>
		<div class="schpub-controls">
			<div class="schpub-search">
				<label class="screen-reader-text" for="schpub-q"><?php esc_html_e( 'Search publications', 'scholar-publications' ); ?></label>
				<svg class="schpub-search-icon" viewBox="0 0 16 16" fill="none" aria-hidden="true" focusable="false">
					<circle cx="7" cy="7" r="4.6" stroke="currentColor" stroke-width="1.6" />
					<path d="M10.4 10.4 14 14" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
				</svg>
				<input type="search" id="schpub-q" class="schpub-input" autocomplete="off"
					placeholder="<?php esc_attr_e( 'Search title, author, venue…', 'scholar-publications' ); ?>" />
				<button type="button" class="schpub-clear" aria-label="<?php esc_attr_e( 'Clear search', 'scholar-publications' ); ?>" hidden>&times;</button>
			</div>

			<div class="schpub-selects">
				<label class="schpub-field">
					<span><?php esc_html_e( 'Year', 'scholar-publications' ); ?></span>
					<select class="schpub-year">
						<option value=""><?php esc_html_e( 'All years', 'scholar-publications' ); ?></option>
						<?php foreach ( $facets['years'] as $year => $n ) : ?>
							<option value="<?php echo esc_attr( (string) $year ); ?>">
								<?php echo esc_html( $year . ' (' . number_format_i18n( $n ) . ')' ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<label class="schpub-field">
					<span><?php esc_html_e( 'Venue', 'scholar-publications' ); ?></span>
					<select class="schpub-venue">
						<option value=""><?php esc_html_e( 'All venues', 'scholar-publications' ); ?></option>
						<?php foreach ( $facets['venues'] as $abbr => $data ) : ?>
							<option value="<?php echo esc_attr( $abbr ); ?>"
								title="<?php echo esc_attr( $data['full'] ? implode( ' • ', $data['full'] ) : $abbr ); ?>">
								<?php echo esc_html( $abbr . ' (' . number_format_i18n( $data['count'] ) . ')' ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<label class="schpub-field">
					<span><?php esc_html_e( 'Sort', 'scholar-publications' ); ?></span>
					<select class="schpub-sort">
						<option value="year" <?php selected( $sort, 'year' ); ?>><?php esc_html_e( 'Newest first', 'scholar-publications' ); ?></option>
						<option value="oldest" <?php selected( $sort, 'oldest' ); ?>><?php esc_html_e( 'Oldest first', 'scholar-publications' ); ?></option>
						<option value="citations" <?php selected( $sort, 'citations' ); ?>><?php esc_html_e( 'Most cited', 'scholar-publications' ); ?></option>
						<option value="title" <?php selected( $sort, 'title' ); ?>><?php esc_html_e( 'Title A–Z', 'scholar-publications' ); ?></option>
					</select>
				</label>
			</div>

			<?php if ( count( $facets['types'] ) > 1 ) : ?>
			<div class="schpub-types" role="group" aria-label="<?php esc_attr_e( 'Filter by publication type', 'scholar-publications' ); ?>">
				<p class="schpub-types-hint"><?php esc_html_e( 'Type — pick any number', 'scholar-publications' ); ?></p>
				<button type="button" class="schpub-pill is-active" data-type="" aria-pressed="true">
					<?php esc_html_e( 'All', 'scholar-publications' ); ?>
					<span class="schpub-pill-n"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
				</button>
				<?php foreach ( $facets['types'] as $type => $n ) : ?>
					<button type="button" class="schpub-pill" data-type="<?php echo esc_attr( $type ); ?>" aria-pressed="false">
						<?php echo esc_html( isset( $labels[ $type ] ) ? $labels[ $type ] : ucfirst( $type ) ); ?>
						<span class="schpub-pill-n"><?php echo esc_html( number_format_i18n( $n ) ); ?></span>
					</button>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<p class="schpub-count" aria-live="polite" data-template="<?php echo esc_attr__( '%1$s of %2$s publications', 'scholar-publications' ); ?>">
				<?php
				printf(
					/* translators: 1: shown count, 2: total count. */
					esc_html__( '%1$s of %2$s publications', 'scholar-publications' ),
					esc_html( number_format_i18n( $total ) ),
					esc_html( number_format_i18n( $total ) )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * The publication list itself.
	 *
	 * Year headings are siblings of the entries rather than wrappers, so the
	 * script can reorder or hide them when a different sort is chosen.
	 *
	 * @param array $items Publication records.
	 * @param bool  $group Whether to emit year headings.
	 */
	private static function render_list( $items, $group ) {
		$labels    = self::type_labels();
		$highlight = self::highlight_variants();
		$last_year = null;
		?>
		<ol class="schpub-list">
			<?php
			foreach ( $items as $position => $item ) {
				$year = (int) $item['year'];
				if ( $group && $year !== $last_year ) {
					$last_year = $year;
					printf(
						'<li class="schpub-year-head" data-year="%1$s" aria-hidden="true">%2$s</li>',
						esc_attr( (string) $year ),
						esc_html( $year > 0 ? (string) $year : __( 'Undated', 'scholar-publications' ) )
					);
				}
				self::render_item( $item, $position, $labels, $highlight );
			}
			?>
		</ol>
		<p class="schpub-noresults" hidden><?php esc_html_e( 'No publications match your filters.', 'scholar-publications' ); ?></p>
		<?php
	}

	/**
	 * Name fragments that should be emphasised inside author lists.
	 *
	 * @return array
	 */
	private static function highlight_variants() {
		$name = trim( (string) schpub_setting( 'highlight_name', '' ) );
		if ( '' === $name ) {
			$snapshot = SchPub_Store::get_snapshot();
			$name     = isset( $snapshot['author']['name'] ) ? (string) $snapshot['author']['name'] : '';
		}
		if ( '' === $name ) {
			return array();
		}

		$parts = preg_split( '/\s+/', trim( $name ) );
		if ( ! $parts ) {
			return array();
		}

		$last     = array_pop( $parts );
		$variants = array( strtolower( $name ) );

		// Scholar abbreviates given names, so "Jane Doe" also appears as "J Doe".
		if ( $parts ) {
			$initials = '';
			foreach ( $parts as $part ) {
				$initials .= mb_substr( $part, 0, 1 );
			}
			$variants[] = strtolower( $initials . ' ' . $last );
			$variants[] = strtolower( $initials . '. ' . $last );
		}

		return array_values( array_unique( array_filter( $variants ) ) );
	}

	/**
	 * One publication entry.
	 *
	 * @param array $item      Publication record.
	 * @param int   $position  Original index, used as the default sort order.
	 * @param array $labels    Type labels.
	 * @param array $highlight Author name variants to emphasise.
	 */
	private static function render_item( $item, $position, $labels, $highlight ) {
		$year      = (int) $item['year'];
		$citations = (int) $item['citations'];
		$type      = isset( $labels[ $item['type'] ] ) ? $labels[ $item['type'] ] : ucfirst( $item['type'] );
		$primary   = '' !== $item['link'] ? $item['link'] : $item['scholar_url'];
		$haystack  = strtolower( $item['title'] . ' ' . implode( ' ', (array) $item['authors'] ) . ' ' . $item['venue'] . ' ' . $year );
		$dom_id    = 'schpub-' . sanitize_html_class( substr( md5( $item['id'] . $position ), 0, 10 ) );
		$has_panel = '' !== trim( (string) $item['abstract'] ) || ! empty( $item['history'] );
		$venue_abbr = SchPub_Store::venue_abbr( $item['venue'], $item['type'] );
		?>
		<li class="schpub-item"
			data-year="<?php echo esc_attr( (string) $year ); ?>"
			data-type="<?php echo esc_attr( $item['type'] ); ?>"
			data-venue="<?php echo esc_attr( $venue_abbr ); ?>"
			data-citations="<?php echo esc_attr( (string) $citations ); ?>"
			data-title="<?php echo esc_attr( strtolower( $item['title'] ) ); ?>"
			data-order="<?php echo esc_attr( (string) $position ); ?>"
			data-search="<?php echo esc_attr( $haystack . ' ' . strtolower( $venue_abbr ) ); ?>">

			<div class="schpub-item-main">
				<h3 class="schpub-title">
					<?php if ( $primary ) : ?>
						<a href="<?php echo esc_url( $primary ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $item['title'] ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $item['title'] ); ?>
					<?php endif; ?>
				</h3>

				<?php if ( ! empty( $item['authors'] ) ) : ?>
					<p class="schpub-authors"><?php echo wp_kses_post( self::format_authors( (array) $item['authors'], $highlight ) ); ?></p>
				<?php endif; ?>

				<p class="schpub-venue">
					<span class="schpub-badge schpub-badge-<?php echo esc_attr( $item['type'] ); ?>"><?php echo esc_html( $type ); ?></span><span class="schpub-venue-text"><?php
					if ( '' !== trim( (string) $item['venue'] ) ) :
						?><span class="schpub-venue-name"><?php echo esc_html( $item['venue'] ); ?></span><?php
					endif;
					if ( '' !== trim( (string) $item['volume'] ) ) :
						?><span class="schpub-vol"> <?php echo esc_html( self::format_volume( $item ) ); ?></span><?php
					endif;
					// Skip the year when the venue name already states it, which is
					// common for conferences such as "2023 IEEE Conference on Games".
					if ( $year > 0 && false === strpos( (string) $item['venue'], (string) $year ) ) :
						?><span class="schpub-item-year"> · <?php echo esc_html( (string) $year ); ?></span><?php
					endif;
					?></span>
				</p>

				<p class="schpub-actions">
					<?php if ( $has_panel ) : ?>
						<button type="button" class="schpub-link schpub-toggle" aria-expanded="false" aria-controls="<?php echo esc_attr( $dom_id ); ?>">
							<?php esc_html_e( 'Details', 'scholar-publications' ); ?>
						</button>
					<?php endif; ?>
					<?php if ( ! empty( $item['pdf_url'] ) ) : ?>
						<a class="schpub-link" href="<?php echo esc_url( $item['pdf_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'PDF', 'scholar-publications' ); ?></a>
					<?php endif; ?>
					<?php if ( ! empty( $item['link'] ) ) : ?>
						<a class="schpub-link" href="<?php echo esc_url( $item['link'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Publisher', 'scholar-publications' ); ?></a>
					<?php endif; ?>
					<?php if ( ! empty( $item['scholar_url'] ) ) : ?>
						<a class="schpub-link" href="<?php echo esc_url( $item['scholar_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Scholar', 'scholar-publications' ); ?></a>
					<?php endif; ?>
					<button type="button" class="schpub-link schpub-bibtex" data-bibtex="<?php echo esc_attr( self::build_bibtex( $item ) ); ?>">
						<?php esc_html_e( 'BibTeX', 'scholar-publications' ); ?>
					</button>
				</p>
			</div>

			<?php if ( $citations > 0 ) : ?>
				<div class="schpub-cited">
					<?php if ( ! empty( $item['cited_by_url'] ) ) : ?>
						<a href="<?php echo esc_url( $item['cited_by_url'] ); ?>" target="_blank" rel="noopener noreferrer">
							<span class="schpub-cited-n"><?php echo esc_html( number_format_i18n( $citations ) ); ?></span>
							<span class="schpub-cited-label"><?php esc_html_e( 'citations', 'scholar-publications' ); ?></span>
						</a>
					<?php else : ?>
						<span class="schpub-cited-n"><?php echo esc_html( number_format_i18n( $citations ) ); ?></span>
						<span class="schpub-cited-label"><?php esc_html_e( 'citations', 'scholar-publications' ); ?></span>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( $has_panel ) : ?>
				<div class="schpub-panel" id="<?php echo esc_attr( $dom_id ); ?>" hidden>
					<?php if ( '' !== trim( (string) $item['abstract'] ) ) : ?>
						<p class="schpub-abstract"><?php echo esc_html( $item['abstract'] ); ?></p>
					<?php endif; ?>
					<?php if ( ! empty( $item['history'] ) ) : ?>
						<?php self::render_sparkline( (array) $item['history'] ); ?>
					<?php endif; ?>
					<?php if ( ! empty( $item['publisher'] ) ) : ?>
						<p class="schpub-publisher"><?php echo esc_html( $item['publisher'] ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</li>
		<?php
	}

	/**
	 * Per-article citation history.
	 *
	 * Years are written in full and each bar carries its own count: an unlabelled
	 * two digit year sitting next to a citation total reads as another number.
	 *
	 * @param array $history Year => citations.
	 */
	private static function render_sparkline( $history ) {
		ksort( $history, SORT_NUMERIC );
		$max = max( array_map( 'intval', $history ) );
		if ( $max <= 0 ) {
			return;
		}
		$total = array_sum( array_map( 'intval', $history ) );
		?>
		<div class="schpub-spark-wrap">
			<p class="schpub-spark-title">
				<?php esc_html_e( 'Citations by year', 'scholar-publications' ); ?>
				<span class="schpub-spark-total">
					<?php
					printf(
						/* translators: %s: total citations in the chart. */
						esc_html__( '%s total', 'scholar-publications' ),
						esc_html( number_format_i18n( $total ) )
					);
					?>
				</span>
			</p>
			<div class="schpub-spark">
				<?php foreach ( $history as $year => $value ) : ?>
					<span class="schpub-spark-col">
						<span class="schpub-spark-n"><?php echo esc_html( number_format_i18n( (int) $value ) ); ?></span>
						<span class="schpub-spark-track">
							<span class="schpub-spark-bar" style="height:<?php echo esc_attr( (string) max( 6, round( ( (int) $value / $max ) * 100 ) ) ); ?>%"></span>
						</span>
						<span class="schpub-spark-year"><?php echo esc_html( (string) $year ); ?></span>
					</span>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render an author list, emphasising the profile owner.
	 *
	 * @param array $authors   Author names.
	 * @param array $highlight Lowercased name variants to emphasise.
	 * @return string Escaped HTML.
	 */
	private static function format_authors( $authors, $highlight ) {
		$out = array();
		foreach ( $authors as $author ) {
			$name = esc_html( $author );
			if ( $highlight && in_array( strtolower( trim( $author ) ), $highlight, true ) ) {
				$name = '<strong class="schpub-me">' . $name . '</strong>';
			}
			$out[] = $name;
		}
		return implode( ', ', $out );
	}

	/**
	 * Combine volume and issue for display, e.g. "35 (9)".
	 *
	 * @param array $item Publication record.
	 * @return string
	 */
	private static function format_volume( $item ) {
		$volume = trim( (string) $item['volume'] );
		$issue  = isset( $item['issue'] ) ? trim( (string) $item['issue'] ) : '';

		if ( '' === $volume ) {
			return '';
		}
		// Guard against a volume that already carries its issue.
		if ( '' !== $issue && false !== strpos( $volume, '(' ) ) {
			return $volume;
		}
		return '' !== $issue ? $volume . ' (' . $issue . ')' : $volume;
	}

	/**
	 * Build a BibTeX entry from a record.
	 *
	 * @param array $item Publication record.
	 * @return string
	 */
	public static function build_bibtex( $item ) {
		$type_map = array(
			'journal'    => 'article',
			'conference' => 'inproceedings',
			'preprint'   => 'misc',
			'patent'     => 'misc',
			'thesis'     => 'phdthesis',
			'other'      => 'misc',
		);
		$entry = isset( $type_map[ $item['type'] ] ) ? $type_map[ $item['type'] ] : 'misc';

		$authors = (array) $item['authors'];
		$surname = 'unknown';
		if ( $authors ) {
			$parts   = preg_split( '/\s+/', trim( (string) $authors[0] ) );
			$surname = strtolower( preg_replace( '/[^A-Za-z]/', '', (string) end( $parts ) ) );
		}
		$word = '';
		foreach ( preg_split( '/\s+/', (string) $item['title'] ) as $candidate ) {
			$candidate = preg_replace( '/[^A-Za-z]/', '', $candidate );
			if ( strlen( $candidate ) > 3 ) {
				$word = strtolower( $candidate );
				break;
			}
		}

		$key    = $surname . ( $item['year'] > 0 ? (string) (int) $item['year'] : '' ) . $word;
		$fields = array(
			'author' => implode( ' and ', $authors ),
			'title'  => (string) $item['title'],
		);

		if ( 'inproceedings' === $entry ) {
			$fields['booktitle'] = (string) $item['venue'];
		} elseif ( 'article' === $entry ) {
			$fields['journal'] = (string) $item['venue'];
		} elseif ( 'phdthesis' === $entry ) {
			$fields['school'] = (string) $item['venue'];
		} elseif ( '' !== trim( (string) $item['venue'] ) ) {
			$fields['howpublished'] = (string) $item['venue'];
		}

		if ( '' !== trim( (string) $item['volume'] ) ) {
			$fields['volume'] = (string) $item['volume'];
		}
		if ( isset( $item['issue'] ) && '' !== trim( (string) $item['issue'] ) ) {
			$fields['number'] = (string) $item['issue'];
		}
		if ( '' !== trim( (string) $item['pages'] ) ) {
			// BibTeX page ranges use an en dash written as a double hyphen.
			$fields['pages'] = preg_replace( '/\s*[-–—]\s*/u', '--', (string) $item['pages'] );
		}
		if ( (int) $item['year'] > 0 ) {
			$fields['year'] = (string) (int) $item['year'];
		}
		if ( '' !== trim( (string) $item['publisher'] ) ) {
			$fields['publisher'] = (string) $item['publisher'];
		}
		if ( '' !== trim( (string) $item['link'] ) ) {
			$fields['url'] = (string) $item['link'];
		}

		$lines = array( '@' . $entry . '{' . $key . ',' );
		foreach ( $fields as $name => $value ) {
			$value = trim( preg_replace( '/\s+/', ' ', (string) $value ) );
			if ( '' === $value ) {
				continue;
			}
			$lines[] = sprintf( '  %s = {%s},', $name, str_replace( array( '{', '}' ), '', $value ) );
		}
		$lines[] = '}';

		return implode( "\n", $lines );
	}
}
