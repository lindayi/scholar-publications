<?php
/**
 * Refresh orchestration: scheduled and manual synchronisation with Google Scholar.
 *
 * @package scholar-publications
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pulls the Scholar profile, enriches records with per-article detail and caches
 * the result.
 */
class SchPub_Sync {

	const CRON_HOOK = 'schpub_refresh_event';

	/**
	 * Seconds one enrichment pass may spend fetching article details.
	 */
	const DETAIL_BUDGET = 45;

	/**
	 * Register cron plumbing.
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedules' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_scheduled' ) );
	}

	/**
	 * Add the weekly and monthly intervals used by the settings screen.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function add_schedules( $schedules ) {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'scholar-publications' ),
			);
		}
		if ( ! isset( $schedules['monthly'] ) ) {
			$schedules['monthly'] = array(
				'interval' => 30 * DAY_IN_SECONDS,
				'display'  => __( 'Once Monthly', 'scholar-publications' ),
			);
		}
		return $schedules;
	}

	/**
	 * Re-arm the cron event using the configured interval.
	 */
	public static function reschedule() {
		self::unschedule();

		$interval = (string) schpub_setting( 'refresh', 'daily' );
		if ( 'never' === $interval ) {
			return;
		}
		if ( ! in_array( $interval, array( 'hourly', 'twicedaily', 'daily', 'weekly', 'monthly' ), true ) ) {
			$interval = 'daily';
		}

		wp_schedule_event( time() + 300, $interval, self::CRON_HOOK );
	}

	/**
	 * Remove the scheduled event.
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
		}
	}

	/**
	 * Cron entry point.
	 */
	public static function run_scheduled() {
		self::refresh( true );
	}

	/**
	 * Pull the profile and rebuild the cached snapshot.
	 *
	 * The previous snapshot is left untouched when the API call fails, so a quota
	 * problem or network blip never blanks the published page.
	 *
	 * @param bool $enrich Whether to also fetch missing article details.
	 * @return array|WP_Error Summary array on success.
	 */
	public static function refresh( $enrich = true ) {
		$profile = SchPub_SerpAPI::fetch_profile();
		if ( is_wp_error( $profile ) ) {
			SchPub_Store::save_error( $profile->get_error_message() );
			return $profile;
		}

		$details      = SchPub_SerpAPI::get_details();
		$fetched      = 0;
		$stopped      = 'complete';
		$publications = $profile['publications'];

		// The profile is authoritative for which works exist, so details belonging
		// to entries that were deleted or merged on Scholar are dropped here.
		$ids     = wp_list_pluck( $publications, 'id' );
		$pruned  = SchPub_SerpAPI::prune_details( $ids );
		if ( $pruned > 0 ) {
			$details = SchPub_SerpAPI::get_details();
		}

		if ( $enrich ) {
			$result  = self::fetch_missing_details( $publications, $details );
			$details = $result['details'];
			$fetched = $result['fetched'];
			$stopped = $result['stopped'];
		}

		foreach ( $publications as $index => $record ) {
			if ( isset( $details[ $record['id'] ] ) ) {
				$publications[ $index ] = self::apply_detail( $record, $details[ $record['id'] ] );
			}
		}

		SchPub_Store::save_snapshot( $profile['author'], $publications, $profile['metrics'] );

		return array(
			'publications'    => count( $publications ),
			'details_added'   => $fetched,
			'details_pruned'  => $pruned,
			'details_missing' => self::count_missing( $publications, $details ),
			'stopped'         => $stopped,
		);
	}

	/**
	 * Fetch details for articles that need them, within a strict request budget.
	 *
	 * Article details are the expensive part: one SerpAPI search each. They are
	 * cached by citation id and normally fetched only once, so a routine refresh
	 * costs a single search for the profile itself. Three independent limits keep
	 * a pathological run from draining the monthly quota:
	 *
	 * - the remaining plan quota, minus a reserve held back for future profile syncs
	 * - a per-run ceiling from the settings
	 * - a wall clock budget, so a cron run cannot stall the request
	 *
	 * @param array $publications Publication records.
	 * @param array $details      Cached details keyed by citation id.
	 * @return array Updated details, the number fetched and why the pass ended.
	 */
	private static function fetch_missing_details( $publications, $details ) {
		$queue = self::build_detail_queue( $publications, $details );
		if ( ! $queue ) {
			return array(
				'details' => $details,
				'fetched' => 0,
				'stopped' => 'complete',
			);
		}

		$per_run = (int) schpub_setting( 'max_details_run', 10 );
		$per_run = $per_run > 0 ? $per_run : 10;
		$reserve = (int) schpub_setting( 'quota_reserve', 40 );

		// Ask SerpAPI how much quota is actually left rather than guessing.
		$left  = SchPub_SerpAPI::searches_left();
		$stopped = 'complete';

		if ( null !== $left ) {
			$affordable = $left - $reserve;
			if ( $affordable <= 0 ) {
				return array(
					'details' => $details,
					'fetched' => 0,
					'stopped' => 'quota_reserve',
				);
			}
			if ( $affordable < $per_run ) {
				$per_run = $affordable;
				$stopped = 'quota_reserve';
			}
		}

		$started = microtime( true );
		$fetched = 0;

		foreach ( $queue as $id ) {
			if ( $fetched >= $per_run ) {
				$stopped = 'complete' === $stopped ? 'run_limit' : $stopped;
				break;
			}
			if ( ( microtime( true ) - $started ) > self::DETAIL_BUDGET ) {
				$stopped = 'time_limit';
				break;
			}

			$detail = SchPub_SerpAPI::fetch_citation( $id );
			if ( is_wp_error( $detail ) ) {
				// Quota exhaustion or a hard API failure should stop the whole pass.
				$stopped = 'api_error';
				break;
			}

			$details[ $id ] = $detail;
			$fetched++;
		}

		if ( $fetched > 0 ) {
			SchPub_SerpAPI::save_details( $details );
		}

		return array(
			'details' => $details,
			'fetched' => $fetched,
			'stopped' => $stopped,
		);
	}

	/**
	 * Decide which articles need a detail request, most valuable first.
	 *
	 * Articles with no cached detail always come first. Re-checking articles that
	 * were fetched long ago is opt-in, because every re-check costs a search;
	 * leaving the interval at zero means details are fetched exactly once.
	 *
	 * @param array $publications Publication records.
	 * @param array $details      Cached details keyed by citation id.
	 * @return array Citation ids to fetch.
	 */
	private static function build_detail_queue( $publications, $details ) {
		$missing = array();
		$stale   = array();

		$ttl_days = (int) schpub_setting( 'detail_ttl_days', 0 );
		$cutoff   = $ttl_days > 0 ? time() - ( $ttl_days * DAY_IN_SECONDS ) : 0;

		foreach ( $publications as $record ) {
			$id = $record['id'];
			if ( '' === $id ) {
				continue;
			}

			if ( ! isset( $details[ $id ] ) ) {
				$missing[] = $id;
				continue;
			}

			if ( $cutoff > 0 ) {
				$fetched = isset( $details[ $id ]['fetched'] ) ? (int) $details[ $id ]['fetched'] : 0;
				if ( $fetched < $cutoff ) {
					$stale[ $id ] = $fetched;
				}
			}
		}

		// Refresh the longest-neglected records first.
		asort( $stale );

		return array_merge( $missing, array_keys( $stale ) );
	}

	/**
	 * Count records still lacking cached detail.
	 *
	 * @param array $publications Publication records.
	 * @param array $details      Cached details.
	 * @return int
	 */
	private static function count_missing( $publications, $details ) {
		$missing = 0;
		foreach ( $publications as $record ) {
			if ( '' !== $record['id'] && ! isset( $details[ $record['id'] ] ) ) {
				$missing++;
			}
		}
		return $missing;
	}

	/**
	 * Overlay a cached citation detail onto a listing record.
	 *
	 * The listing is authoritative for the current citation count; the detail
	 * page is authoritative for everything the listing truncates.
	 *
	 * @param array $record Listing record.
	 * @param array $detail Cached detail.
	 * @return array
	 */
	private static function apply_detail( $record, $detail ) {
		$detail = (array) $detail;

		if ( ! empty( $detail['title'] ) ) {
			$record['title'] = $detail['title'];
		}
		if ( ! empty( $detail['authors'] ) ) {
			$record['authors'] = $detail['authors'];
		}
		if ( ! empty( $detail['venue'] ) ) {
			$record['venue'] = $detail['venue'];
			$record['type']  = SchPub_SerpAPI::infer_type( $detail['venue'], $record['title'] );

			// Volume and issue belong to the same citation as the venue, so the
			// detail page wins outright and any value guessed from the compact
			// listing string is discarded.
			$record['volume'] = isset( $detail['volume'] ) ? (string) $detail['volume'] : '';
			$record['issue']  = isset( $detail['issue'] ) ? (string) $detail['issue'] : '';
		}

		foreach ( array( 'volume', 'issue', 'pages', 'publisher', 'publication_date', 'abstract', 'link', 'pdf_url', 'patent_number' ) as $field ) {
			if ( ! empty( $detail[ $field ] ) ) {
				$record[ $field ] = $detail[ $field ];
			}
		}

		if ( ! empty( $detail['history'] ) ) {
			$record['history'] = (array) $detail['history'];
		}
		if ( empty( $record['year'] ) && ! empty( $detail['year'] ) ) {
			$record['year'] = (int) $detail['year'];
		}

		$record['detailed'] = true;
		return $record;
	}

	/**
	 * Run enrichment on the stored snapshot without re-fetching the profile.
	 *
	 * @return array|WP_Error
	 */
	public static function enrich_only() {
		$publications = SchPub_Store::get_publications();
		if ( ! $publications ) {
			return new WP_Error( 'schpub_empty', __( 'There is no cached snapshot to enrich yet.', 'scholar-publications' ) );
		}

		$snapshot = SchPub_Store::get_snapshot();
		$details  = SchPub_SerpAPI::get_details();
		$result   = self::fetch_missing_details( $publications, $details );
		$details  = $result['details'];

		foreach ( $publications as $index => $record ) {
			if ( isset( $details[ $record['id'] ] ) ) {
				$publications[ $index ] = self::apply_detail( $record, $details[ $record['id'] ] );
			}
		}

		SchPub_Store::save_snapshot( $snapshot['author'], $publications, $snapshot['stats'] );

		return array(
			'publications'    => count( $publications ),
			'details_added'   => $result['fetched'],
			'details_pruned'  => 0,
			'details_missing' => self::count_missing( $publications, $details ),
			'stopped'         => $result['stopped'],
		);
	}
}
