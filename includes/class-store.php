<?php
/**
 * Storage for the Google Scholar snapshot.
 *
 * Google Scholar is the single source of truth. This class only persists the
 * most recent successful snapshot and derives the facets the front end needs.
 *
 * @package scholar-publications
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the cached Scholar snapshot.
 */
class SchPub_Store {

	const OPTION_DATA = 'schpub_data';

	/**
	 * Read the stored snapshot.
	 *
	 * @return array
	 */
	public static function get_snapshot() {
		$data = get_option( self::OPTION_DATA, array() );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		return wp_parse_args(
			$data,
			array(
				'author'       => array(),
				'publications' => array(),
				'stats'        => array(),
				'updated'      => 0,
				'last_error'   => '',
				'last_try'     => 0,
			)
		);
	}

	/**
	 * Persist a fresh snapshot returned by the Scholar client.
	 *
	 * @param array $author       Author profile fields.
	 * @param array $publications Normalized publication records.
	 * @param array $metrics      Citation metrics reported by Scholar.
	 */
	public static function save_snapshot( $author, $publications, $metrics ) {
		$publications = self::apply_user_filters( array_values( (array) $publications ) );
		usort( $publications, array( __CLASS__, 'compare' ) );

		$data = array(
			'author'       => (array) $author,
			'publications' => $publications,
			'stats'        => self::build_stats( $publications, $metrics ),
			'updated'      => time(),
			'last_error'   => '',
			'last_try'     => time(),
		);

		update_option( self::OPTION_DATA, $data, false );
	}

	/**
	 * Record a failed refresh without discarding the last good snapshot.
	 *
	 * @param string $message Error message.
	 */
	public static function save_error( $message ) {
		$data               = self::get_snapshot();
		$data['last_error'] = (string) $message;
		$data['last_try']   = time();
		update_option( self::OPTION_DATA, $data, false );
	}

	/**
	 * Remove the cached snapshot entirely.
	 */
	public static function clear() {
		delete_option( self::OPTION_DATA );
	}

	/**
	 * The shape every publication record follows.
	 *
	 * @return array
	 */
	public static function blank_record() {
		return array(
			'id'               => '',
			'title'            => '',
			'authors'          => array(),
			'venue'            => '',
			'volume'           => '',
			'issue'            => '',
			'pages'            => '',
			'publisher'        => '',
			'publication_date' => '',
			'year'             => 0,
			'type'             => 'other',
			'citations'        => 0,
			'scholar_url'      => '',
			'cited_by_url'     => '',
			'link'             => '',
			'pdf_url'          => '',
			'abstract'         => '',
			'patent_number'    => '',
			'history'          => array(),
			'detailed'         => false,
		);
	}

	/**
	 * Drop records the site owner chose to hide.
	 *
	 * @param array $items Publication records.
	 * @return array
	 */
	private static function apply_user_filters( $items ) {
		$min_year = (int) schpub_setting( 'min_year', 0 );
		$raw      = (string) schpub_setting( 'exclude_titles', '' );

		$needles = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = self::normalize_title( $line );
			if ( '' !== $line ) {
				$needles[] = $line;
			}
		}

		$out = array();
		foreach ( $items as $item ) {
			$item = wp_parse_args( (array) $item, self::blank_record() );

			if ( '' === trim( $item['title'] ) ) {
				continue;
			}
			if ( $min_year > 0 && $item['year'] > 0 && $item['year'] < $min_year ) {
				continue;
			}

			if ( $needles ) {
				$norm = self::normalize_title( $item['title'] );
				$skip = false;
				foreach ( $needles as $needle ) {
					if ( false !== strpos( $norm, $needle ) ) {
						$skip = true;
						break;
					}
				}
				if ( $skip ) {
					continue;
				}
			}

			$out[] = $item;
		}

		return $out;
	}

	/**
	 * Reduce a title to lowercase alphanumerics for comparison.
	 *
	 * @param string $title Raw title.
	 * @return string
	 */
	public static function normalize_title( $title ) {
		$title = html_entity_decode( (string) $title, ENT_QUOTES, 'UTF-8' );
		return (string) preg_replace( '/[^a-z0-9]+/', '', strtolower( $title ) );
	}

	/**
	 * Newest first, then most cited, then alphabetical.
	 *
	 * @param array $a First record.
	 * @param array $b Second record.
	 * @return int
	 */
	public static function compare( $a, $b ) {
		if ( (int) $a['year'] !== (int) $b['year'] ) {
			return (int) $b['year'] <=> (int) $a['year'];
		}
		if ( (int) $a['citations'] !== (int) $b['citations'] ) {
			return (int) $b['citations'] <=> (int) $a['citations'];
		}
		return strcasecmp( $a['title'], $b['title'] );
	}

	/**
	 * Assemble the statistics block shown above the list.
	 *
	 * Citation totals, h-index and i10-index come straight from Google Scholar.
	 * Only the per-year publication counts are derived locally, because Scholar
	 * does not report them.
	 *
	 * @param array $items   Publication records.
	 * @param array $metrics Metrics reported by Scholar.
	 * @return array
	 */
	private static function build_stats( $items, $metrics ) {
		$metrics = wp_parse_args(
			(array) $metrics,
			array(
				'citations'      => 0,
				'citations_recent' => 0,
				'h_index'        => 0,
				'i10_index'      => 0,
				'citations_year' => array(),
			)
		);

		$by_year = array();
		$years   = array();
		foreach ( $items as $item ) {
			$year = (int) $item['year'];
			if ( $year <= 0 ) {
				continue;
			}
			$years[]                     = $year;
			$key                         = (string) $year;
			$by_year[ $key ]             = isset( $by_year[ $key ] ) ? $by_year[ $key ] + 1 : 1;
		}
		krsort( $by_year, SORT_NUMERIC );

		return array(
			'publications'     => count( $items ),
			'citations'        => (int) $metrics['citations'],
			'citations_recent' => (int) $metrics['citations_recent'],
			'h_index'          => (int) $metrics['h_index'],
			'i10_index'        => (int) $metrics['i10_index'],
			'citations_year'   => (array) $metrics['citations_year'],
			'by_year'          => $by_year,
			'first_year'       => $years ? min( $years ) : 0,
			'last_year'        => $years ? max( $years ) : 0,
		);
	}

	/**
	 * Publication records ready for rendering.
	 *
	 * @return array
	 */
	public static function get_publications() {
		$snapshot = self::get_snapshot();
		return (array) $snapshot['publications'];
	}

	/**
	 * Statistics block.
	 *
	 * @return array
	 */
	public static function get_stats() {
		$snapshot = self::get_snapshot();
		return (array) $snapshot['stats'];
	}

	/**
	 * Shorten a venue name to the abbreviation researchers actually use.
	 *
	 * Scholar only ever supplies the full name, so the short form is derived
	 * here. Preprints and patents are deliberately collapsed into single buckets:
	 * every arXiv paper otherwise becomes its own "venue" and makes the filter
	 * useless.
	 *
	 * @param string $venue Full venue name.
	 * @param string $type  Inferred publication type.
	 * @return string
	 */
	public static function venue_abbr( $venue, $type = '' ) {
		$result = self::resolve_venue_abbr( $venue, $type );

		/**
		 * Filters the final venue abbreviation.
		 *
		 * @param string $result Abbreviation that will be displayed.
		 * @param string $venue  Full venue name from Google Scholar.
		 * @param string $type   Inferred publication type.
		 */
		return apply_filters( 'scholar_publications_venue_abbr', $result, $venue, $type );
	}

	/**
	 * Work out the abbreviation before filters are applied.
	 *
	 * @param string $venue Full venue name.
	 * @param string $type  Inferred publication type.
	 * @return string
	 */
	private static function resolve_venue_abbr( $venue, $type = '' ) {
		$venue = trim( (string) $venue );
		if ( '' === $venue ) {
			return __( 'Unlisted', 'scholar-publications' );
		}

		if ( preg_match( '/arxiv/i', $venue ) ) {
			return 'arXiv';
		}
		if ( preg_match( '/\bpatents?\b/i', $venue ) ) {
			return preg_match( '/\b([A-Z]{2})\s+Patent/', $venue, $m ) ? $m[1] . ' Patent' : 'Patent';
		}

		// An acronym in brackets is the venue's own short name, so prefer it.
		if ( preg_match_all( '/\(([^)]{2,14})\)/', $venue, $matches ) ) {
			foreach ( $matches[1] as $candidate ) {
				$candidate = trim( $candidate );
				// Require at least two capitals so place names like "(Canada)" are ignored.
				if ( preg_match( '/^[A-Za-z][A-Za-z0-9\-\/]*$/', $candidate )
					&& preg_match_all( '/[A-Z]/', $candidate ) >= 2 ) {
					return $candidate;
				}
			}
		}

		$map = array(
			'/findings of the association for computational linguistics/i' => 'ACL Findings',
			'/association for computational linguistics/i'                 => 'ACL',
			'/empirical software engineering/i'                            => 'EMSE',
			'/transactions on software engineering and methodology/i'      => 'TOSEM',
			'/transactions on software engineering\b/i'                    => 'TSE',
			'/sigsoft software engineering notes/i'                        => 'SEN',
			'/transactions on games/i'                                     => 'ToG',
			'/software:?\s*practice and experience/i'                      => 'SPE',
			'/ieee access/i'                                               => 'IEEE Access',
			'/foundations of software engineering/i'                       => 'FSE',
			'/(sigkdd|knowledge discovery and data mining)/i'              => 'KDD',
			'/automated software engineering/i'                            => 'ASE',
			'/international conference on software engineering/i'          => 'ICSE',
			'/conference on games/i'                                       => 'CoG',
			'/empirical methods in natural language processing/i'          => 'EMNLP',
			'/neural information processing systems/i'                     => 'NeurIPS',
			'/international conference on machine learning/i'              => 'ICML',
		);

		/**
		 * Filters the venue lookup table.
		 *
		 * The bundled entries cover computer science venues. Other disciplines can
		 * add their own without forking, for example:
		 *
		 *     add_filter( 'scholar_publications_venue_map', function ( $map ) {
		 *         $map['/journal of the american chemical society/i'] = 'JACS';
		 *         return $map;
		 *     } );
		 *
		 * @param array  $map   Regular expression to abbreviation pairs.
		 * @param string $venue Full venue name being shortened.
		 * @param string $type  Inferred publication type.
		 */
		$map = apply_filters( 'scholar_publications_venue_map', $map, $venue, $type );

		foreach ( $map as $pattern => $abbr ) {
			if ( preg_match( $pattern, $venue ) ) {
				return $abbr;
			}
		}

		if ( 'thesis' === $type ) {
			return __( 'Thesis', 'scholar-publications' );
		}

		// Nothing matched: keep short names as they are, otherwise build initials.
		if ( mb_strlen( $venue ) <= 22 ) {
			return $venue;
		}

		$skip     = array( 'of', 'the', 'on', 'and', 'in', 'for', 'a', 'an', 'proceedings', 'international', 'conference', 'annual', 'companion', 'ieee', 'acm', 'workshop', 'symposium' );
		$initials = '';
		foreach ( preg_split( '/[\s:,\-]+/', $venue ) as $word ) {
			$clean = preg_replace( '/[^A-Za-z]/', '', $word );
			if ( '' === $clean || in_array( strtolower( $clean ), $skip, true ) ) {
				continue;
			}
			$initials .= strtoupper( mb_substr( $clean, 0, 1 ) );
		}

		return '' !== $initials ? mb_substr( $initials, 0, 6 ) : mb_substr( $venue, 0, 22 );
	}

	/**
	 * Distinct venues, years and types for the filter controls.
	 *
	 * @param array $items Publication records.
	 * @return array
	 */
	public static function build_facets( $items ) {
		$years  = array();
		$types  = array();
		$venues = array();

		foreach ( $items as $item ) {
			$year = (int) $item['year'];
			if ( $year > 0 ) {
				$years[ $year ] = isset( $years[ $year ] ) ? $years[ $year ] + 1 : 1;
			}

			$type           = '' !== $item['type'] ? $item['type'] : 'other';
			$types[ $type ] = isset( $types[ $type ] ) ? $types[ $type ] + 1 : 1;

			$abbr = self::venue_abbr( $item['venue'], $item['type'] );
			if ( ! isset( $venues[ $abbr ] ) ) {
				$venues[ $abbr ] = array(
					'count' => 0,
					'full'  => array(),
				);
			}
			$venues[ $abbr ]['count']++;

			$full = trim( (string) $item['venue'] );
			if ( '' !== $full && ! in_array( $full, $venues[ $abbr ]['full'], true ) && count( $venues[ $abbr ]['full'] ) < 4 ) {
				$venues[ $abbr ]['full'][] = $full;
			}
		}

		krsort( $years, SORT_NUMERIC );
		arsort( $types );

		// Busiest venues first, then alphabetically.
		uasort(
			$venues,
			static function ( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);

		return array(
			'years'  => $years,
			'types'  => $types,
			'venues' => $venues,
		);
	}
}
