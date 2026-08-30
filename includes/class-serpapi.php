<?php
/**
 * Google Scholar client backed by the SerpAPI Google Scholar Author API.
 *
 * Google Scholar cannot be scraped directly from a server: it answers datacenter
 * IP addresses with an anti-bot page. SerpAPI performs the lookup and returns the
 * same Scholar records as structured JSON, so Scholar remains the only source of
 * truth for this plugin.
 *
 * @package scholar-publications
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Talks to SerpAPI and normalizes its payloads into publication records.
 */
class SchPub_SerpAPI {

	const ENDPOINT       = 'https://serpapi.com/search.json';
	const ACCOUNT_URL    = 'https://serpapi.com/account.json';
	const OPTION_DETAILS = 'schpub_details';
	const OPTION_USAGE   = 'schpub_usage';
	const TRANSIENT_LEFT = 'schpub_searches_left';

	/**
	 * Perform a GET against SerpAPI and decode the JSON body.
	 *
	 * @param array $args Query arguments, excluding the API key.
	 * @return array|WP_Error
	 */
	private static function request( $args ) {
		$key = trim( (string) schpub_setting( 'serpapi_key', '' ) );
		if ( '' === $key ) {
			return new WP_Error( 'schpub_no_key', __( 'No SerpAPI key has been configured.', 'scholar-publications' ) );
		}

		$args['api_key'] = $key;
		$url             = add_query_arg( array_map( 'rawurlencode', $args ), self::ENDPOINT );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 45,
				'user-agent' => 'ScholarPublications/' . SCHPUB_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );

		if ( ! is_array( $json ) ) {
			return new WP_Error(
				'schpub_bad_json',
				sprintf( /* translators: %d: HTTP status code. */ __( 'SerpAPI returned an unreadable response (HTTP %d).', 'scholar-publications' ), $code )
			);
		}

		// SerpAPI reports quota and lookup problems in an "error" member.
		if ( ! empty( $json['error'] ) ) {
			return new WP_Error( 'schpub_api_error', (string) $json['error'] );
		}

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'schpub_http',
				sprintf( /* translators: %d: HTTP status code. */ __( 'SerpAPI request failed with HTTP %d.', 'scholar-publications' ), $code )
			);
		}

		// SerpAPI bills successful searches only, so count them here.
		self::record_usage();

		return $json;
	}

	/**
	 * Read this month's local search counter, resetting it when the month rolls.
	 *
	 * @return array Month key and count.
	 */
	public static function get_usage() {
		$usage = get_option( self::OPTION_USAGE, array() );
		$month = gmdate( 'Y-m' );

		if ( ! is_array( $usage ) || ! isset( $usage['month'] ) || $usage['month'] !== $month ) {
			$usage = array(
				'month' => $month,
				'count' => 0,
			);
		}

		return $usage;
	}

	/**
	 * Increment the local search counter.
	 */
	private static function record_usage() {
		$usage          = self::get_usage();
		$usage['count'] = (int) $usage['count'] + 1;
		update_option( self::OPTION_USAGE, $usage, false );

		// The cached remaining-quota figure is now one search out of date.
		$left = get_transient( self::TRANSIENT_LEFT );
		if ( false !== $left ) {
			set_transient( self::TRANSIENT_LEFT, max( 0, (int) $left - 1 ), 15 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Searches remaining on the SerpAPI plan.
	 *
	 * This is the authoritative figure from SerpAPI rather than a local estimate.
	 * The account endpoint does not consume search quota, and the result is
	 * cached briefly so a refresh pass only asks once.
	 *
	 * @param bool $force Bypass the cached value.
	 * @return int|null Remaining searches, or null when unknown.
	 */
	public static function searches_left( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT_LEFT );
			if ( false !== $cached ) {
				return (int) $cached;
			}
		}

		$account = self::fetch_account();
		if ( is_wp_error( $account ) || ! isset( $account['total_searches_left'] ) ) {
			return null;
		}

		$left = (int) $account['total_searches_left'];
		set_transient( self::TRANSIENT_LEFT, $left, 15 * MINUTE_IN_SECONDS );
		return $left;
	}

	/**
	 * Fetch the author profile: every article plus the headline metrics.
	 *
	 * @return array|WP_Error Array with author, publications and metrics keys.
	 */
	public static function fetch_profile() {
		$author_id = trim( (string) schpub_setting( 'scholar_id', '' ) );
		if ( '' === $author_id ) {
			return new WP_Error( 'schpub_no_author', __( 'No Google Scholar ID has been configured.', 'scholar-publications' ) );
		}

		$articles = array();
		$author   = array();
		$metrics  = array();
		$start    = 0;

		// Scholar pages profiles; 100 per call keeps the request count minimal.
		for ( $page = 0; $page < 10; $page++ ) {
			$json = self::request(
				array(
					'engine'    => 'google_scholar_author',
					'author_id' => $author_id,
					'hl'        => 'en',
					'sort'      => 'pubdate',
					'num'       => '100',
					'start'     => (string) $start,
				)
			);

			if ( is_wp_error( $json ) ) {
				// Keep whatever earlier pages returned rather than failing outright.
				if ( $articles ) {
					break;
				}
				return $json;
			}

			if ( 0 === $page ) {
				$author  = self::parse_author( isset( $json['author'] ) ? $json['author'] : array() );
				$metrics = self::parse_metrics( isset( $json['cited_by'] ) ? $json['cited_by'] : array() );
			}

			$batch = isset( $json['articles'] ) ? (array) $json['articles'] : array();
			if ( ! $batch ) {
				break;
			}

			foreach ( $batch as $article ) {
				$articles[] = self::normalize_article( $article );
			}

			if ( count( $batch ) < 100 ) {
				break;
			}
			$start += 100;
		}

		return array(
			'author'       => $author,
			'publications' => $articles,
			'metrics'      => $metrics,
		);
	}

	/**
	 * Normalize the author block of the profile response.
	 *
	 * @param array $author Raw author data.
	 * @return array
	 */
	private static function parse_author( $author ) {
		$interests = array();
		if ( ! empty( $author['interests'] ) && is_array( $author['interests'] ) ) {
			foreach ( $author['interests'] as $interest ) {
				if ( ! empty( $interest['title'] ) ) {
					$interests[] = sanitize_text_field( $interest['title'] );
				}
			}
		}

		return array(
			'name'        => isset( $author['name'] ) ? sanitize_text_field( $author['name'] ) : '',
			'affiliation' => isset( $author['affiliations'] ) ? sanitize_text_field( $author['affiliations'] ) : '',
			'website'     => isset( $author['website'] ) ? esc_url_raw( $author['website'] ) : '',
			'thumbnail'   => isset( $author['thumbnail'] ) ? esc_url_raw( $author['thumbnail'] ) : '',
			'interests'   => $interests,
			'profile_url' => 'https://scholar.google.com/citations?user=' . rawurlencode( (string) schpub_setting( 'scholar_id', '' ) ) . '&hl=en',
		);
	}

	/**
	 * Pull the citation metrics table and the per-year citation graph.
	 *
	 * @param array $cited_by Raw cited_by block.
	 * @return array
	 */
	private static function parse_metrics( $cited_by ) {
		$metrics = array(
			'citations'        => 0,
			'citations_recent' => 0,
			'h_index'          => 0,
			'i10_index'        => 0,
			'citations_year'   => array(),
		);

		if ( ! empty( $cited_by['table'] ) && is_array( $cited_by['table'] ) ) {
			foreach ( $cited_by['table'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				foreach ( $row as $name => $values ) {
					if ( ! is_array( $values ) ) {
						continue;
					}
					$all = isset( $values['all'] ) ? (int) $values['all'] : 0;

					if ( 'citations' === $name ) {
						$metrics['citations'] = $all;
						foreach ( $values as $vkey => $vval ) {
							// The "since" column is named after a rolling year, e.g. since_2021.
							if ( 0 === strpos( $vkey, 'since_' ) ) {
								$metrics['citations_recent'] = (int) $vval;
							}
						}
					} elseif ( 'h_index' === $name ) {
						$metrics['h_index'] = $all;
					} elseif ( 'i10_index' === $name ) {
						$metrics['i10_index'] = $all;
					}
				}
			}
		}

		if ( ! empty( $cited_by['graph'] ) && is_array( $cited_by['graph'] ) ) {
			foreach ( $cited_by['graph'] as $point ) {
				if ( isset( $point['year'], $point['citations'] ) ) {
					$metrics['citations_year'][ (string) (int) $point['year'] ] = (int) $point['citations'];
				}
			}
			ksort( $metrics['citations_year'], SORT_NUMERIC );
		}

		return $metrics;
	}

	/**
	 * Convert one profile listing entry into a publication record.
	 *
	 * @param array $article Raw article entry.
	 * @return array
	 */
	private static function normalize_article( $article ) {
		$record = SchPub_Store::blank_record();

		$record['id']          = isset( $article['citation_id'] ) ? sanitize_text_field( $article['citation_id'] ) : '';
		$record['title']       = isset( $article['title'] ) ? self::clean_text( $article['title'] ) : '';
		$record['year']        = isset( $article['year'] ) ? (int) $article['year'] : 0;
		$record['scholar_url'] = isset( $article['link'] ) ? esc_url_raw( $article['link'] ) : '';
		$record['authors']     = self::split_authors( isset( $article['authors'] ) ? $article['authors'] : '' );

		if ( isset( $article['cited_by']['value'] ) ) {
			$record['citations'] = (int) $article['cited_by']['value'];
		}
		if ( isset( $article['cited_by']['link'] ) ) {
			$record['cited_by_url'] = esc_url_raw( $article['cited_by']['link'] );
		}

		$parsed           = self::parse_publication( isset( $article['publication'] ) ? $article['publication'] : '' );
		$record['venue']  = $parsed['venue'];
		$record['volume'] = $parsed['volume'];
		$record['issue']  = $parsed['issue'];
		$record['pages']  = $parsed['pages'];

		if ( $record['year'] <= 0 && $parsed['year'] > 0 ) {
			$record['year'] = $parsed['year'];
		}

		// Front matter entries often carry no year field but name it in the title.
		if ( $record['year'] <= 0 ) {
			$record['year'] = self::guess_year( $record['title'] . ' ' . $record['venue'] );
		}

		$record['type'] = self::infer_type( $record['venue'], $record['title'] );

		if ( '' === $record['id'] ) {
			$record['id'] = substr( md5( SchPub_Store::normalize_title( $record['title'] ) ), 0, 16 );
		}

		return $record;
	}

	/**
	 * Find a plausible publication year inside free text.
	 *
	 * @param string $text Text to scan.
	 * @return int Year, or 0 when nothing plausible is found.
	 */
	public static function guess_year( $text ) {
		if ( ! preg_match_all( '/\b(19|20)\d{2}\b/', (string) $text, $matches ) ) {
			return 0;
		}

		$limit = (int) gmdate( 'Y' ) + 2;
		$best  = 0;
		foreach ( $matches[0] as $candidate ) {
			$candidate = (int) $candidate;
			if ( $candidate >= 1950 && $candidate <= $limit && $candidate > $best ) {
				$best = $candidate;
			}
		}
		return $best;
	}

	/**
	 * Split Scholar's comma separated author string into a list.
	 *
	 * @param string $authors Raw author string.
	 * @return array
	 */
	public static function split_authors( $authors ) {
		$authors = self::clean_text( $authors );
		if ( '' === $authors ) {
			return array();
		}

		$parts = array_map( 'trim', explode( ',', $authors ) );
		$out   = array();
		foreach ( $parts as $part ) {
			if ( '' !== $part ) {
				$out[] = $part;
			}
		}
		return $out;
	}

	/**
	 * Split the profile listing's combined publication string.
	 *
	 * Scholar packs venue, volume, issue, pages and year into one string, for
	 * example "IEEE Transactions on Games 13 (4), 358-371, 2021". Long venues are
	 * truncated with an ellipsis, which is stripped here.
	 *
	 * @param string $publication Raw publication string.
	 * @return array Venue, volume, pages and year.
	 */
	public static function parse_publication( $publication ) {
		$out = array(
			'venue'  => '',
			'volume' => '',
			'issue'  => '',
			'pages'  => '',
			'year'   => 0,
		);

		$publication = self::clean_text( $publication );
		if ( '' === $publication ) {
			return $out;
		}

		// Patent numbers contain commas, so keep those strings intact.
		if ( preg_match( '/^(.*?Patent[^,]*(?:,\s*[\d\/,]+)?)\s*,\s*(\d{4})$/i', $publication, $m ) ) {
			$out['venue'] = trim( $m[1] );
			$out['year']  = (int) $m[2];
			return $out;
		}

		$parts = array_map( 'trim', explode( ',', $publication ) );

		// Trailing 4 digit year.
		$last = end( $parts );
		if ( null !== $last && preg_match( '/^\d{4}$/', (string) $last ) ) {
			$out['year'] = (int) $last;
			array_pop( $parts );
		}

		// Trailing page range or article number.
		$last = end( $parts );
		if ( null !== $last && preg_match( '/^(?:[A-Za-z]?\d+\s*[-–]\s*\d+|e?\d{1,7})$/', (string) $last ) ) {
			$out['pages'] = str_replace( ' ', '', (string) $last );
			array_pop( $parts );
		}

		$venue = trim( implode( ', ', $parts ) );
		$venue = preg_replace( '/[\s,]*…\s*$/u', '', $venue );
		$venue = preg_replace( '/[\s,]*\.\.\.\s*$/', '', $venue );

		// Trailing volume and optional issue, e.g. "… Games 13 (4)".
		if ( preg_match( '/^(.*?)\s+(\d{1,4})\s*(?:\(\s*([\w\d\-]+)\s*\))?$/u', $venue, $m ) ) {
			$tail  = trim( $m[1] );
			$issue = isset( $m[3] ) ? $m[3] : '';
			// A bare four digit year is part of the venue name, not a volume.
			$is_year = '' === $issue && preg_match( '/^(19|20)\d{2}$/', $m[2] );

			// Only treat it as a volume when a real venue name remains.
			if ( '' !== $tail && ! $is_year && ! preg_match( '/\b(conference|workshop|symposium|proceedings|congress)\b/i', $m[0] ) ) {
				$venue         = $tail;
				$out['volume'] = $m[2];
				$out['issue']  = $issue;
			}
		}

		$out['venue'] = trim( $venue, " \t\n\r\0\x0B,.-" );
		return $out;
	}

	/**
	 * Classify a record so the front end can offer type filters.
	 *
	 * @param string $venue Venue name.
	 * @param string $title Publication title.
	 * @return string One of preprint|patent|thesis|conference|journal|other.
	 */
	public static function infer_type( $venue, $title = '' ) {
		$haystack = strtolower( $venue . ' ' . $title );

		if ( '' === trim( $venue ) ) {
			return false !== strpos( $haystack, 'arxiv' ) ? 'preprint' : 'other';
		}
		if ( preg_match( '/\bpatent\b/i', $venue ) ) {
			return 'patent';
		}
		if ( preg_match( '/arxiv|preprint|biorxiv|ssrn/i', $venue ) ) {
			return 'preprint';
		}
		if ( preg_match( '/\b(thesis|dissertation)\b/i', $haystack ) ) {
			return 'thesis';
		}
		if ( preg_match( '/\b(proceedings|conference|symposium|workshop|congress|findings of|companion)\b/i', $venue ) ) {
			return 'conference';
		}
		if ( preg_match( '/\b(journal|transactions|trans\.|letters|magazine|review|notes|access|surveys|practice and experience|software engineering)\b/i', $venue ) ) {
			return 'journal';
		}
		if ( preg_match( '/\b(university|institute|college)\b/i', $venue ) ) {
			return 'thesis';
		}
		return 'other';
	}

	/**
	 * Fetch the full Scholar record for one article.
	 *
	 * @param string $citation_id Scholar citation identifier.
	 * @return array|WP_Error
	 */
	public static function fetch_citation( $citation_id ) {
		$json = self::request(
			array(
				'engine'      => 'google_scholar_author',
				'view_op'     => 'view_citation',
				'citation_id' => $citation_id,
				'hl'          => 'en',
			)
		);

		if ( is_wp_error( $json ) ) {
			return $json;
		}
		if ( empty( $json['citation'] ) || ! is_array( $json['citation'] ) ) {
			return new WP_Error( 'schpub_no_citation', __( 'SerpAPI returned no citation detail.', 'scholar-publications' ) );
		}

		return self::normalize_citation( $json['citation'] );
	}

	/**
	 * Reduce a citation detail payload to the fields the front end uses.
	 *
	 * Scholar names the venue field differently per publication type: journal
	 * articles use "journal", conference papers use "book" or "conference",
	 * theses use "institution" and patents use the patent office fields.
	 *
	 * @param array $citation Raw citation payload.
	 * @return array
	 */
	private static function normalize_citation( $citation ) {
		$get = static function ( $key ) use ( $citation ) {
			return isset( $citation[ $key ] ) ? self::clean_text( $citation[ $key ] ) : '';
		};

		$venue = '';
		foreach ( array( 'journal', 'book', 'conference', 'source', 'institution' ) as $field ) {
			if ( '' !== $get( $field ) ) {
				$venue = $get( $field );
				break;
			}
		}

		$patent_office = $get( 'patent_office' );
		$patent_number = '' !== $get( 'patent_number' ) ? $get( 'patent_number' ) : $get( 'application_number' );
		if ( '' === $venue && '' !== $patent_office ) {
			$venue = trim( $patent_office . ' Patent ' . $patent_number );
		}

		// Patents list inventors rather than authors.
		$authors = '' !== $get( 'authors' ) ? $get( 'authors' ) : $get( 'inventors' );

		$volume = $get( 'volume' );
		$issue  = $get( 'issue' );

		// Older caches stored the issue folded into the volume, e.g. "35 (9)".
		if ( '' === $issue && preg_match( '/^([\w\d.\-\/]+)\s*\(\s*([^)]+)\s*\)$/', $volume, $vm ) ) {
			$volume = trim( $vm[1] );
			$issue  = trim( $vm[2] );
		}

		$pdf_url = '';
		if ( ! empty( $citation['resources'] ) && is_array( $citation['resources'] ) ) {
			foreach ( $citation['resources'] as $resource ) {
				if ( ! empty( $resource['link'] ) ) {
					$pdf_url = esc_url_raw( $resource['link'] );
					break;
				}
			}
		}

		$year = 0;
		if ( preg_match( '/(\d{4})/', $get( 'publication_date' ), $m ) ) {
			$year = (int) $m[1];
		}

		$history = array();
		if ( ! empty( $citation['total_citations']['table'] ) && is_array( $citation['total_citations']['table'] ) ) {
			foreach ( $citation['total_citations']['table'] as $row ) {
				if ( isset( $row['year'], $row['citations'] ) ) {
					$history[ (string) (int) $row['year'] ] = (int) $row['citations'];
				}
			}
			ksort( $history, SORT_NUMERIC );
		}

		return array(
			'title'            => $get( 'title' ),
			'authors'          => self::split_authors( $authors ),
			'venue'            => $venue,
			'volume'           => $volume,
			'issue'            => $issue,
			'pages'            => $get( 'pages' ),
			'publisher'        => $get( 'publisher' ),
			'publication_date' => $get( 'publication_date' ),
			'year'             => $year,
			'abstract'         => self::clean_abstract( isset( $citation['description'] ) ? $citation['description'] : '' ),
			'link'             => isset( $citation['link'] ) ? esc_url_raw( $citation['link'] ) : '',
			'pdf_url'          => $pdf_url,
			'patent_number'    => $patent_number,
			'history'          => $history,
			'fetched'          => time(),
		);
	}

	/**
	 * Tidy a Scholar supplied string.
	 *
	 * @param mixed $text Raw value.
	 * @return string
	 */
	public static function clean_text( $text ) {
		if ( ! is_scalar( $text ) ) {
			return '';
		}
		$text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
		$text = str_replace( "\xc2\xa0", ' ', $text );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( (string) $text );
	}

	/**
	 * Clean an abstract, dropping Scholar's trailing truncation marker.
	 *
	 * @param string $text Raw description.
	 * @return string
	 */
	private static function clean_abstract( $text ) {
		$text = self::clean_text( $text );
		$text = preg_replace( '/\s*…\s*$/u', '…', $text );
		return $text;
	}

	/**
	 * Read the cached citation details map.
	 *
	 * @return array
	 */
	public static function get_details() {
		$details = get_option( self::OPTION_DETAILS, array() );
		return is_array( $details ) ? $details : array();
	}

	/**
	 * Persist the citation details map.
	 *
	 * @param array $details Details keyed by citation id.
	 */
	public static function save_details( $details ) {
		update_option( self::OPTION_DETAILS, $details, false );
	}

	/**
	 * Drop cached details for articles that are no longer on the profile.
	 *
	 * Google Scholar profiles change: entries get deleted or merged, and a merged
	 * entry is issued a new citation id. Without pruning, the details option
	 * would keep every record the profile has ever contained.
	 *
	 * @param array $valid_ids Citation ids currently present on the profile.
	 * @return int Number of orphaned entries removed.
	 */
	public static function prune_details( $valid_ids ) {
		$details = self::get_details();
		if ( ! $details ) {
			return 0;
		}

		$keep    = array_fill_keys( array_filter( (array) $valid_ids ), true );
		$removed = 0;

		foreach ( array_keys( $details ) as $id ) {
			if ( ! isset( $keep[ $id ] ) ) {
				unset( $details[ $id ] );
				$removed++;
			}
		}

		if ( $removed > 0 ) {
			self::save_details( $details );
		}

		return $removed;
	}

	/**
	 * Ask SerpAPI how much of the monthly quota is left.
	 *
	 * @return array|WP_Error
	 */
	public static function fetch_account() {
		$key = trim( (string) schpub_setting( 'serpapi_key', '' ) );
		if ( '' === $key ) {
			return new WP_Error( 'schpub_no_key', __( 'No SerpAPI key has been configured.', 'scholar-publications' ) );
		}

		$response = wp_remote_get(
			add_query_arg( 'api_key', rawurlencode( $key ), self::ACCOUNT_URL ),
			array( 'timeout' => 20 )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $json ) ) {
			return new WP_Error( 'schpub_bad_json', __( 'Could not read the SerpAPI account response.', 'scholar-publications' ) );
		}
		return $json;
	}
}
