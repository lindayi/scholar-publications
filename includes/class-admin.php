<?php
/**
 * Settings screen, status panel and manual sync actions.
 *
 * @package scholar-publications
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the plugin's admin page and handles its form submissions.
 */
class SchPub_Admin {

	const PAGE_SLUG = 'scholar-publications';
	const NONCE     = 'schpub_admin';

	/**
	 * Hook the admin screens.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_schpub_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_schpub_action', array( __CLASS__, 'handle_action' ) );
	}

	/**
	 * Register the options page.
	 */
	public static function add_menu() {
		add_options_page(
			__( 'Scholar Publications', 'scholar-publications' ),
			__( 'Scholar Publications', 'scholar-publications' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Build a URL back to this screen carrying a status notice.
	 *
	 * @param string $notice Notice slug.
	 * @param string $detail Optional detail text.
	 * @return string
	 */
	private static function redirect_url( $notice, $detail = '' ) {
		$args = array(
			'page'          => self::PAGE_SLUG,
			'schpub_notice' => $notice,
		);
		if ( '' !== $detail ) {
			$args['schpub_detail'] = rawurlencode( $detail );
		}
		return add_query_arg( $args, admin_url( 'options-general.php' ) );
	}

	/**
	 * Persist the settings form.
	 */
	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'scholar-publications' ) );
		}
		check_admin_referer( self::NONCE );

		$settings = schpub_settings();
		$posted   = wp_unslash( $_POST );

		$settings['scholar_id']     = isset( $posted['scholar_id'] ) ? sanitize_text_field( $posted['scholar_id'] ) : '';
		$settings['refresh']        = isset( $posted['refresh'] ) ? sanitize_key( $posted['refresh'] ) : 'daily';
		$settings['max_details_run'] = isset( $posted['max_details_run'] ) ? absint( $posted['max_details_run'] ) : 10;
		$settings['quota_reserve']   = isset( $posted['quota_reserve'] ) ? absint( $posted['quota_reserve'] ) : 40;
		$settings['detail_ttl_days'] = isset( $posted['detail_ttl_days'] ) ? absint( $posted['detail_ttl_days'] ) : 0;
		$settings['sort_default']   = isset( $posted['sort_default'] ) ? sanitize_key( $posted['sort_default'] ) : 'year';
		$settings['layout']         = isset( $posted['layout'] ) && 'stacked' === $posted['layout'] ? 'stacked' : 'sidebar';
		$settings['highlight_name'] = isset( $posted['highlight_name'] ) ? sanitize_text_field( $posted['highlight_name'] ) : '';
		$settings['min_year']       = isset( $posted['min_year'] ) ? absint( $posted['min_year'] ) : 0;
		$settings['exclude_titles'] = isset( $posted['exclude_titles'] ) ? sanitize_textarea_field( $posted['exclude_titles'] ) : '';
		$settings['group_by_year']  = empty( $posted['group_by_year'] ) ? 0 : 1;
		$settings['show_stats']     = empty( $posted['show_stats'] ) ? 0 : 1;
		$settings['show_chart']     = empty( $posted['show_chart'] ) ? 0 : 1;

		// Only overwrite the stored key when a new one is supplied.
		$key = isset( $posted['serpapi_key'] ) ? trim( sanitize_text_field( $posted['serpapi_key'] ) ) : '';
		if ( '' !== $key ) {
			$settings['serpapi_key'] = $key;
		}

		update_option( 'schpub_settings', $settings, false );
		SchPub_Sync::reschedule();

		wp_safe_redirect( self::redirect_url( 'saved' ) );
		exit;
	}

	/**
	 * Run a manual sync action.
	 */
	public static function handle_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to run this action.', 'scholar-publications' ) );
		}
		check_admin_referer( self::NONCE );

		$action = isset( $_POST['schpub_do'] ) ? sanitize_key( wp_unslash( $_POST['schpub_do'] ) ) : '';

		if ( 'clear' === $action ) {
			SchPub_Store::clear();
			wp_safe_redirect( self::redirect_url( 'cleared' ) );
			exit;
		}

		if ( 'details' === $action ) {
			$result = SchPub_Sync::enrich_only();
		} else {
			$result = SchPub_Sync::refresh( true );
		}

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( self::redirect_url( 'error', $result->get_error_message() ) );
			exit;
		}

		$detail = sprintf(
			/* translators: 1: publication count, 2: newly detailed count, 3: remaining count, 4: pruned count. */
			__( '%1$d publications cached, %2$d article details added, %3$d still pending, %4$d orphaned details removed.', 'scholar-publications' ),
			$result['publications'],
			$result['details_added'],
			$result['details_missing'],
			$result['details_pruned']
		);

		$reasons = array(
			'quota_reserve' => __( 'Stopped early to stay above the reserved SerpAPI quota.', 'scholar-publications' ),
			'run_limit'     => __( 'Stopped at the per-run detail limit; the next sync continues where this one left off.', 'scholar-publications' ),
			'time_limit'    => __( 'Stopped at the time limit; the next sync continues where this one left off.', 'scholar-publications' ),
			'api_error'     => __( 'Stopped because SerpAPI returned an error.', 'scholar-publications' ),
		);
		if ( isset( $reasons[ $result['stopped'] ] ) ) {
			$detail .= ' ' . $reasons[ $result['stopped'] ];
		}

		wp_safe_redirect( self::redirect_url( 'synced', $detail ) );
		exit;
	}

	/**
	 * Show the notice requested by the redirect.
	 */
	private static function render_notice() {
		$notice = isset( $_GET['schpub_notice'] ) ? sanitize_key( wp_unslash( $_GET['schpub_notice'] ) ) : '';
		if ( '' === $notice ) {
			return;
		}

		$detail = isset( $_GET['schpub_detail'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['schpub_detail'] ) ) ) : '';

		$map = array(
			'saved'   => array( 'success', __( 'Settings saved.', 'scholar-publications' ) ),
			'cleared' => array( 'success', __( 'Cached Scholar data cleared.', 'scholar-publications' ) ),
			'synced'  => array( 'success', __( 'Synced with Google Scholar.', 'scholar-publications' ) ),
			'error'   => array( 'error', __( 'The sync failed.', 'scholar-publications' ) ),
		);

		if ( ! isset( $map[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p><strong>%2$s</strong>%3$s</p></div>',
			esc_attr( $map[ $notice ][0] ),
			esc_html( $map[ $notice ][1] ),
			'' !== $detail ? ' ' . esc_html( $detail ) : ''
		);
	}

	/**
	 * Format a timestamp in the site's timezone.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	private static function format_time( $timestamp ) {
		if ( ! $timestamp ) {
			return __( 'never', 'scholar-publications' );
		}
		return sprintf(
			/* translators: %s: human readable time difference. */
			__( '%s ago', 'scholar-publications' ),
			human_time_diff( $timestamp, time() )
		);
	}

	/**
	 * Render the options page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = schpub_settings();
		$snapshot = SchPub_Store::get_snapshot();
		$details  = SchPub_SerpAPI::get_details();
		$pubs     = (array) $snapshot['publications'];
		$stats    = (array) $snapshot['stats'];

		$missing = 0;
		foreach ( $pubs as $record ) {
			if ( empty( $record['detailed'] ) ) {
				$missing++;
			}
		}

		$next_run = wp_next_scheduled( SchPub_Sync::CRON_HOOK );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Scholar Publications', 'scholar-publications' ); ?></h1>
			<?php self::render_notice(); ?>

			<p class="description">
				<?php esc_html_e( 'Google Scholar is the single source of truth for this plugin. Data is fetched through the SerpAPI Google Scholar Author API and cached locally, so visitors never wait on an external request.', 'scholar-publications' ); ?>
			</p>

			<h2><?php esc_html_e( 'Status', 'scholar-publications' ); ?></h2>
			<table class="widefat striped" style="max-width:820px">
				<tbody>
					<tr>
						<th style="width:240px"><?php esc_html_e( 'Publications cached', 'scholar-publications' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( count( $pubs ) ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Scholar metrics', 'scholar-publications' ); ?></th>
						<td>
							<?php
							printf(
								/* translators: 1: citations, 2: h-index, 3: i10-index. */
								esc_html__( '%1$s citations, h-index %2$s, i10-index %3$s', 'scholar-publications' ),
								esc_html( number_format_i18n( isset( $stats['citations'] ) ? $stats['citations'] : 0 ) ),
								esc_html( number_format_i18n( isset( $stats['h_index'] ) ? $stats['h_index'] : 0 ) ),
								esc_html( number_format_i18n( isset( $stats['i10_index'] ) ? $stats['i10_index'] : 0 ) )
							);
							?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Article details', 'scholar-publications' ); ?></th>
						<td>
							<?php
							printf(
								/* translators: 1: cached count, 2: pending count. */
								esc_html__( '%1$d cached, %2$d pending', 'scholar-publications' ),
								count( $details ),
								(int) $missing
							);
							?>
							<?php if ( $missing > 0 ) : ?>
								<em><?php esc_html_e( '— full author lists and abstracts appear once details are fetched.', 'scholar-publications' ); ?></em>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'SerpAPI quota', 'scholar-publications' ); ?></th>
						<td>
							<?php
							$usage = SchPub_SerpAPI::get_usage();
							$left  = SchPub_SerpAPI::searches_left();

							printf(
								/* translators: %d: number of searches. */
								esc_html__( '%d searches used by this site this month', 'scholar-publications' ),
								(int) $usage['count']
							);

							if ( null !== $left ) {
								echo ' — ';
								printf(
									/* translators: %d: number of searches. */
									esc_html__( '%d left on the plan', 'scholar-publications' ),
									(int) $left
								);

								$reserve = (int) schpub_setting( 'quota_reserve', 40 );
								if ( $left <= $reserve ) {
									echo ' <strong style="color:#b32d2e">' . esc_html__( '(reserve reached — detail fetching is paused)', 'scholar-publications' ) . '</strong>';
								}
							}
							?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Last successful sync', 'scholar-publications' ); ?></th>
						<td><?php echo esc_html( self::format_time( (int) $snapshot['updated'] ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Next scheduled sync', 'scholar-publications' ); ?></th>
						<td>
							<?php
							echo $next_run
								? esc_html( sprintf( /* translators: %s: time difference. */ __( 'in %s', 'scholar-publications' ), human_time_diff( time(), $next_run ) ) )
								: esc_html__( 'not scheduled', 'scholar-publications' );
							?>
						</td>
					</tr>
					<?php if ( ! empty( $snapshot['last_error'] ) ) : ?>
					<tr>
						<th><?php esc_html_e( 'Last error', 'scholar-publications' ); ?></th>
						<td style="color:#b32d2e"><?php echo esc_html( $snapshot['last_error'] ); ?></td>
					</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:16px 0">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="action" value="schpub_action" />
				<button type="submit" name="schpub_do" value="refresh" class="button button-primary">
					<?php esc_html_e( 'Sync with Google Scholar', 'scholar-publications' ); ?>
				</button>
				<button type="submit" name="schpub_do" value="details" class="button">
					<?php esc_html_e( 'Fetch pending article details', 'scholar-publications' ); ?>
				</button>
				<button type="submit" name="schpub_do" value="clear" class="button button-link-delete">
					<?php esc_html_e( 'Clear cache', 'scholar-publications' ); ?>
				</button>
			</form>

			<h2><?php esc_html_e( 'Settings', 'scholar-publications' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="action" value="schpub_save" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="schpub_scholar_id"><?php esc_html_e( 'Google Scholar ID', 'scholar-publications' ); ?></label></th>
						<td>
							<input name="scholar_id" id="schpub_scholar_id" type="text" class="regular-text"
								value="<?php echo esc_attr( $settings['scholar_id'] ); ?>" />
							<p class="description"><?php esc_html_e( 'The user parameter from your Scholar profile URL, e.g. AbCdEfGhIjKl.', 'scholar-publications' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="schpub_key"><?php esc_html_e( 'SerpAPI key', 'scholar-publications' ); ?></label></th>
						<td>
							<input name="serpapi_key" id="schpub_key" type="password" class="regular-text" autocomplete="off"
								placeholder="<?php echo $settings['serpapi_key'] ? esc_attr__( 'Stored — leave blank to keep', 'scholar-publications' ) : ''; ?>" />
							<p class="description">
								<?php esc_html_e( 'Stored in the database, never in a file. Leave blank to keep the existing key.', 'scholar-publications' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="schpub_refresh"><?php esc_html_e( 'Refresh interval', 'scholar-publications' ); ?></label></th>
						<td>
							<select name="refresh" id="schpub_refresh">
								<?php
								$intervals = array(
									'daily'      => __( 'Daily (recommended)', 'scholar-publications' ),
									'twicedaily' => __( 'Twice daily', 'scholar-publications' ),
									'weekly'     => __( 'Weekly', 'scholar-publications' ),
									'monthly'    => __( 'Monthly', 'scholar-publications' ),
									'never'      => __( 'Never (manual only)', 'scholar-publications' ),
								);
								foreach ( $intervals as $value => $label ) {
									printf(
										'<option value="%1$s"%2$s>%3$s</option>',
										esc_attr( $value ),
										selected( $settings['refresh'], $value, false ),
										esc_html( $label )
									);
								}
								?>
							</select>
							<p class="description"><?php esc_html_e( 'One profile sync costs a single SerpAPI search, so a daily refresh uses about 30 per month.', 'scholar-publications' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="schpub_maxrun"><?php esc_html_e( 'Detail requests per sync', 'scholar-publications' ); ?></label></th>
						<td>
							<input name="max_details_run" id="schpub_maxrun" type="number" min="1" max="100" class="small-text"
								value="<?php echo esc_attr( (string) $settings['max_details_run'] ); ?>" />
							<p class="description">
								<?php esc_html_e( 'Hard ceiling on the expensive per-article requests in a single sync. Anything left over is picked up by the next sync, so a burst of new papers is spread out instead of draining the quota at once.', 'scholar-publications' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="schpub_reserve"><?php esc_html_e( 'Reserve searches', 'scholar-publications' ); ?></label></th>
						<td>
							<input name="quota_reserve" id="schpub_reserve" type="number" min="0" max="10000" class="small-text"
								value="<?php echo esc_attr( (string) $settings['quota_reserve'] ); ?>" />
							<p class="description">
								<?php esc_html_e( 'Detail fetching pauses once the plan drops to this many remaining searches, keeping enough in hand for the daily profile syncs. Profile syncs themselves are never blocked.', 'scholar-publications' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="schpub_ttl"><?php esc_html_e( 'Re-check details after', 'scholar-publications' ); ?></label></th>
						<td>
							<input name="detail_ttl_days" id="schpub_ttl" type="number" min="0" max="3650" class="small-text"
								value="<?php echo esc_attr( (string) $settings['detail_ttl_days'] ); ?>" />
							<?php esc_html_e( 'days', 'scholar-publications' ); ?>
							<p class="description">
								<?php esc_html_e( '0 means each article is fetched once and never again — the cheapest option. Set a value only if you edit records on Scholar and want those edits to appear; every re-check costs one search. Citation counts refresh on every sync regardless of this setting.', 'scholar-publications' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="schpub_highlight"><?php esc_html_e( 'Highlight author name', 'scholar-publications' ); ?></label></th>
						<td>
							<input name="highlight_name" id="schpub_highlight" type="text" class="regular-text"
								value="<?php echo esc_attr( $settings['highlight_name'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Your name is shown in bold in author lists. Scholar abbreviates names, so both "Jane Doe" and "J Doe" are matched.', 'scholar-publications' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="schpub_layout"><?php esc_html_e( 'Layout', 'scholar-publications' ); ?></label></th>
						<td>
							<select name="layout" id="schpub_layout">
								<option value="sidebar" <?php selected( $settings['layout'], 'sidebar' ); ?>>
									<?php esc_html_e( 'Metrics and filters in a sticky side rail', 'scholar-publications' ); ?>
								</option>
								<option value="stacked" <?php selected( $settings['layout'], 'stacked' ); ?>>
									<?php esc_html_e( 'Metrics and filters stacked above the list', 'scholar-publications' ); ?>
								</option>
							</select>
							<p class="description">
								<?php esc_html_e( 'The side rail keeps search and filters visible while scrolling and puts the first publication at the top of the page. It widens the block to its containing column so the list keeps its width, and folds back above the list on narrow screens.', 'scholar-publications' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Display', 'scholar-publications' ); ?></th>
						<td>
							<label><input type="checkbox" name="show_stats" value="1" <?php checked( $settings['show_stats'], 1 ); ?> /> <?php esc_html_e( 'Show the metrics summary', 'scholar-publications' ); ?></label><br />
							<label><input type="checkbox" name="show_chart" value="1" <?php checked( $settings['show_chart'], 1 ); ?> /> <?php esc_html_e( 'Show the citations-per-year chart', 'scholar-publications' ); ?></label><br />
							<label><input type="checkbox" name="group_by_year" value="1" <?php checked( $settings['group_by_year'], 1 ); ?> /> <?php esc_html_e( 'Group publications under year headings', 'scholar-publications' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="schpub_sort"><?php esc_html_e( 'Default sort', 'scholar-publications' ); ?></label></th>
						<td>
							<select name="sort_default" id="schpub_sort">
								<?php
								$sorts = array(
									'year'      => __( 'Newest first', 'scholar-publications' ),
									'citations' => __( 'Most cited first', 'scholar-publications' ),
									'title'     => __( 'Title A–Z', 'scholar-publications' ),
								);
								foreach ( $sorts as $value => $label ) {
									printf(
										'<option value="%1$s"%2$s>%3$s</option>',
										esc_attr( $value ),
										selected( $settings['sort_default'], $value, false ),
										esc_html( $label )
									);
								}
								?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="schpub_minyear"><?php esc_html_e( 'Earliest year to show', 'scholar-publications' ); ?></label></th>
						<td>
							<input name="min_year" id="schpub_minyear" type="number" min="0" max="2200" class="small-text"
								value="<?php echo esc_attr( (string) $settings['min_year'] ); ?>" />
							<p class="description"><?php esc_html_e( '0 shows everything.', 'scholar-publications' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="schpub_exclude"><?php esc_html_e( 'Hide titles containing', 'scholar-publications' ); ?></label></th>
						<td>
							<textarea name="exclude_titles" id="schpub_exclude" rows="4" class="large-text code"><?php echo esc_textarea( $settings['exclude_titles'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One phrase per line. Useful for removing duplicate Scholar entries.', 'scholar-publications' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Usage', 'scholar-publications' ); ?></h2>
			<p><?php esc_html_e( 'Add this shortcode to any page:', 'scholar-publications' ); ?> <code>[scholar_publications]</code></p>
			<p><?php esc_html_e( 'Optional attributes:', 'scholar-publications' ); ?></p>
			<ul style="list-style:disc;margin-left:24px">
				<li><code>limit="10"</code> — <?php esc_html_e( 'show only the newest N publications', 'scholar-publications' ); ?></li>
				<li><code>type="conference"</code> — <?php esc_html_e( 'restrict to one type (journal, conference, preprint, patent, thesis)', 'scholar-publications' ); ?></li>
				<li><code>stats="0" chart="0" controls="0" group="0"</code> — <?php esc_html_e( 'turn individual sections off', 'scholar-publications' ); ?></li>
			</ul>
		</div>
		<?php
	}
}
