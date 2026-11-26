<?php
/**
 * Broad, cache-backed product search engine for CatalogFilters (AJAX live search + shop ?s= queries).
 *
 * Searches across titles, SKUs, descriptions, excerpts, brands, categories, tags, all tire technical
 * attributes (model, construction, tube, usage, load/speed), kit front+rear sizes and compatible motorcycles.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\CatalogFilters;

use Motored\Core\Modules\Compatibility\AttributeInstaller;
use Motored\Core\Modules\Compatibility\CatalogRepository;
use Motored\Core\Modules\Compatibility\Config as CompatConfig;
use Motored\Core\Modules\Compatibility\KitSpec;
use Motored\Core\Modules\Compatibility\MatchEngine;
use Motored\Core\Modules\Compatibility\ProductIndexRepository;
use Motored\Core\Modules\Compatibility\SizeParser;
use Motored\Core\Modules\Compatibility\TireSpecs;
use Motored\Core\Modules\Compatibility\Storefront as CompatStorefront;
use Motored\Core\Support\Cache;
use Motored\Core\Support\SearchText;

defined( 'ABSPATH' ) || exit;

/**
 * In-memory scored search over a compact cached product document index (0 extra DB tables, Redis-safe).
 */
final class ProductSearch {

	public const CACHE_KEY = 'product_search_index_v1';
	public const CACHE_TTL = 600; // 10 minutes; also version-keyed by SnapshotInvalidator::version().

	/**
	 * Common Spanish connectors and generic tire nouns ignored when paired with specific tokens/sizes.
	 *
	 * @var array<string,true>
	 */
	private const STOP_WORDS = array(
		'de'         => true,
		'del'        => true,
		'para'       => true,
		'con'        => true,
		'sin'        => true,
		'en'         => true,
		'el'         => true,
		'la'         => true,
		'los'        => true,
		'las'        => true,
		'un'         => true,
		'una'        => true,
		'y'          => true,
		'e'          => true,
		'o'          => true,
		'x'          => true,
		'moto'       => true,
		'motos'      => true,
		'cubierta'   => true,
		'cubiertas'  => true,
		'neumatico'  => true,
		'neumaticos' => true,
		'goma'       => true,
		'gomas'      => true,
		'llanta'     => true,
		'llantas'    => true,
	);

	/**
	 * Words signalling that the shopper is looking for a front+rear kit/pair.
	 *
	 * @var array<string,true>
	 */
	private const KIT_WORDS = array(
		'kit'    => true,
		'kits'   => true,
		'combo'  => true,
		'combos' => true,
		'par'    => true,
		'juego'  => true,
		'set'    => true,
		'pack'   => true,
	);

	/**
	 * Per-request memoized search documents.
	 *
	 * @var list<array<string,mixed>>|null
	 */
	private static ?array $memo = null;

	/**
	 * Returns the compact search index (per-request memo -> Support\Cache -> 2-3 batch SQL queries).
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function index(): array {
		if ( null !== self::$memo ) {
			return self::$memo;
		}

		$ver       = SnapshotInvalidator::version();
		$cache_key = self::CACHE_KEY . '_' . md5( $ver );
		if ( Cache::is_persistent() ) {
			$found  = false;
			$cached = Cache::get( $cache_key, SnapshotInvalidator::BUCKET, $found );
			if ( $found && is_array( $cached ) ) {
				self::$memo = $cached;
				return self::$memo;
			}
		}

		$docs       = self::build_index_from_db();
		self::$memo = $docs;
		if ( Cache::is_persistent() ) {
			Cache::set( $cache_key, $docs, self::CACHE_TTL, SnapshotInvalidator::BUCKET );
		}
		return $docs;
	}

	/**
	 * Resets the per-request memo (tests / invalidation).
	 */
	public static function reset(): void {
		self::$memo = null;
	}

	/**
	 * Scores and returns matching product IDs for any shopper query (sizes, kits, partial kit pairs,
	 * brands, models, specs, SKUs, descriptions, excerpts and compatible motorcycles).
	 *
	 * @param string                         $raw_q         Raw user query.
	 * @param bool                           $only_in_stock Exclude out-of-stock products.
	 * @param int                            $limit         Max product IDs to return.
	 * @param list<array<string,mixed>>|null $docs          Optional preloaded docs (tests).
	 * @param list<array<string,mixed>>|null $bikes         Optional preloaded bikes index (tests).
	 * @return list<int>
	 */
	public static function search(
		string $raw_q,
		bool $only_in_stock = true,
		int $limit = 20,
		?array $docs = null,
		?array $bikes = null
	): array {
		$norm = SearchText::normalize( $raw_q );
		if ( strlen( $norm ) < 2 ) {
			return array();
		}

		$all_docs = null !== $docs ? $docs : self::index();
		if ( empty( $all_docs ) ) {
			return array();
		}

		$parsed_query = self::analyze_query( $raw_q );
		$full_sizes   = $parsed_query['sizes'];
		$partials     = $parsed_query['partials'];
		$tokens       = $parsed_query['tokens'];
		$wants_kit    = $parsed_query['wants_kit'];

		// Compatible motorcycle lookup when the query contains non-size words (e.g. "Benelli TRK 502", "Tornado Pirelli").
		$bike_product_ids = array();
		if ( empty( $full_sizes ) && empty( $partials ) && CompatConfig::enabled() ) {
			$bike_list = null !== $bikes ? $bikes : CatalogRepository::published_bike_search_index();
			$matched_b = 0;
			foreach ( $bike_list as $bk ) {
				if ( $matched_b >= 5 ) {
					break;
				}
				if ( SearchText::matches( (string) ( $bk['haystack'] ?? '' ), $raw_q ) ) {
					++$matched_b;
					foreach ( MatchEngine::matching_product_ids( (string) $bk['slug'] ) as $pid ) {
						$bike_product_ids[ $pid ] = true;
					}
				}
			}
		}

		$scored = array();
		foreach ( $all_docs as $doc ) {
			if ( $only_in_stock && empty( $doc['in_stock'] ) ) {
				continue;
			}
			$score = self::score_document( $doc, $parsed_query, $bike_product_ids );
			if ( $score <= 0 ) {
				continue;
			}
			// Small stock & sales tie-breaker while keeping relevance dominant.
			if ( ! empty( $doc['in_stock'] ) ) {
				$score += 5;
			}
			$scored[] = array(
				'id'         => (int) $doc['id'],
				'score'      => $score,
				'menu_order' => (int) ( $doc['menu_order'] ?? 0 ),
			);
		}

		// Broad fallback: if strict multi-token matching found nothing and there were 2+ text tokens,
		// allow matching products that hit at least one full size or a majority of tokens so the shopper is never stuck.
		if ( empty( $scored ) && ( count( $tokens ) >= 2 || ( ! empty( $full_sizes ) && ! empty( $tokens ) ) ) ) {
			foreach ( $all_docs as $doc ) {
				if ( $only_in_stock && empty( $doc['in_stock'] ) ) {
					continue;
				}
				$score = self::score_document_relaxed( $doc, $parsed_query );
				if ( $score > 0 ) {
					$scored[] = array(
						'id'         => (int) $doc['id'],
						'score'      => $score,
						'menu_order' => (int) ( $doc['menu_order'] ?? 0 ),
					);
				}
			}
		}

		usort(
			$scored,
			static function ( array $a, array $b ): int {
				$cmp = $b['score'] <=> $a['score'];
				if ( 0 !== $cmp ) {
					return $cmp;
				}
				$cmp = $a['menu_order'] <=> $b['menu_order'];
				return 0 !== $cmp ? $cmp : ( $b['id'] <=> $a['id'] );
			}
		);

		$out = array();
		foreach ( array_slice( $scored, 0, max( 1, $limit ) ) as $item ) {
			$out[] = $item['id'];
		}
		return $out;
	}

	/**
	 * Builds real in-stock Kit suggestions for Smart Search (supports 2 sizes, 1 size + partial 2nd size,
	 * 1 size with matching kits, or "kit/combo/par" queries).
	 *
	 * @param string                         $raw_q         Raw user query.
	 * @param string                         $shop_url      Base shop URL.
	 * @param bool                           $only_in_stock Exclude out-of-stock kits.
	 * @param list<array<string,mixed>>|null $docs          Optional preloaded docs (tests).
	 * @return list<array{type:string,exact?:bool,label:string,meta:string,url:string}>
	 */
	public static function kit_suggestions(
		string $raw_q,
		string $shop_url,
		bool $only_in_stock = true,
		?array $docs = null
	): array {
		unset( $shop_url );
		$norm = SearchText::normalize( $raw_q );
		if ( strlen( $norm ) < 2 ) {
			return array();
		}

		$all_docs = null !== $docs ? $docs : self::index();
		if ( empty( $all_docs ) ) {
			return array();
		}

		$pq        = self::analyze_query( $raw_q );
		$sizes     = $pq['sizes'];
		$partials  = $pq['partials'];
		$tokens    = $pq['tokens'];
		$wants_kit = $pq['wants_kit'];

		// Only generate kit suggestions when the query mentions sizes, partial sizes or kit keywords.
		if ( empty( $sizes ) && empty( $partials ) && ! $wants_kit ) {
			return array();
		}

		$groups = array();
		foreach ( $all_docs as $doc ) {
			if ( empty( $doc['is_kit'] ) ) {
				continue;
			}
			if ( $only_in_stock && empty( $doc['in_stock'] ) ) {
				continue;
			}
			$front = (string) ( $doc['front'] ?? '' );
			$rear  = (string) ( $doc['rear'] ?? '' );
			if ( '' === $front || '' === $rear ) {
				continue;
			}

			if ( ! self::kit_matches_query( $doc, $pq ) ) {
				continue;
			}

			$f_desig = '' !== (string) ( $doc['front_desig'] ?? '' ) ? (string) $doc['front_desig'] : $front;
			$r_desig = '' !== (string) ( $doc['rear_desig'] ?? '' ) ? (string) $doc['rear_desig'] : $rear;
			$gkey    = $f_desig . '+' . $r_desig;
			if ( ! isset( $groups[ $gkey ] ) ) {
				$groups[ $gkey ] = array(
					'front_desig' => $f_desig,
					'rear_desig'  => $r_desig,
					'front_canon' => $front,
					'rear_canon'  => $rear,
					'count'       => 0,
				);
			}
			++$groups[ $gkey ]['count'];
		}

		if ( empty( $groups ) ) {
			return array();
		}

		uasort(
			$groups,
			static fn( array $a, array $b ): int => ( $b['count'] <=> $a['count'] ) ?: strcmp( $a['front_desig'], $b['front_desig'] )
		);

		$is_exact_pair = count( $sizes ) >= 2 && empty( $tokens );
		$out           = array();
		foreach ( array_slice( array_values( $groups ), 0, 3 ) as $idx => $g ) {
			$label = sprintf(
				/* translators: 1: first tire size, 2: second tire size. */
				__( 'Kit %1$s + %2$s', 'motored-core' ),
				$g['front_desig'],
				$g['rear_desig']
			);
			$search_q = $g['front_desig'] . ' + ' . $g['rear_desig'];
			$out[]    = array(
				'type'  => 'kit',
				'exact' => $is_exact_pair && 0 === $idx && 1 === count( $groups ),
				'label' => $label,
				'meta'  => __( 'Kit', 'motored-core' ) . ' (' . $g['count'] . ')',
				'url'   => add_query_arg(
					array(
						's'         => $search_q,
						'post_type' => 'product',
					),
					home_url( '/' )
				),
			);
		}
		return $out;
	}

	/**
	 * Parses a raw query into full sizes, partial size prefixes (e.g. "+ 120" or "+ 120/70"),
	 * kit intent flag and normalized text tokens.
	 *
	 * @param string $raw_q Raw query.
	 * @return array{raw:string,norm:string,sizes:list<array<string,mixed>>,partials:list<string>,tokens:list<string>,wants_kit:bool}
	 */
	public static function analyze_query( string $raw_q ): array {
		$norm      = SearchText::normalize( $raw_q );
		$sizes     = SizeParser::parse_all( $raw_q );
		$wants_kit = count( $sizes ) >= 2 || str_contains( $raw_q, '+' );

		// Strip full size tokens from a working copy so we can detect partial 2nd sizes ("+ 120", "+ 120/70") and text words.
		$rest = strtoupper( trim( $raw_q ) );
		$rest = str_replace( array( ',', '–', '—', '−' ), array( '.', '-', '-', '-' ), $rest );
		$rest = (string) preg_replace( '/(?<!\d)\d{2,3}\s*(?:\/|\s+)\s*\d{2,3}\s*(?:ZR|VR|VB|R|B|D|-|\s+)\s*\d{1,2}(?!\d)/', ' ', $rest );
		$rest = (string) preg_replace( '/\bM[HJMNPRSTUV]\d{2}\s*(?:ZR|R|B|D|-)?\s*\d{1,2}(?!\d)/', ' ', $rest );
		$rest = (string) preg_replace( '/(?<!\d)\d+\.\d{1,2}\s*(?:R|B|X|-|\s+)\s*\d{1,2}(?!\d)/', ' ', $rest );

		$partials = array();
		// Match partial metric sizes like "120/70", "120/70zr", "120 70" or trailing width after "+" like "+ 120".
		if ( preg_match_all( '/(?<!\d)(\d{2,3})\s*(?:\/|\s+)\s*(\d{2,3})(?:\s*(?:ZR|VR|VB|R|B|D|-))?(?!\d)/', $rest, $pm, PREG_SET_ORDER ) ) {
			foreach ( $pm as $m ) {
				$w = (int) $m[1];
				$p = (int) $m[2];
				if ( $w >= 50 && $w <= 360 && $p >= 25 && $p <= 100 && ( str_contains( $m[0], '/' ) || 0 === $p % 5 ) ) {
					$partials[] = $w . '/' . $p;
					$rest       = str_replace( $m[0], ' ', $rest );
				}
			}
		}
		if ( preg_match_all( '/(?<!\d)(\d+\.\d{1,2})(?!\d)/', $rest, $im, PREG_SET_ORDER ) ) {
			foreach ( $im as $m ) {
				$partials[] = $m[1];
				$rest       = str_replace( $m[0], ' ', $rest );
			}
		}
		// Trailing 2-3 digit tire width (e.g. "160 60 17 + 120" or "120/70-17 + 160").
		if ( ( ! empty( $sizes ) || str_contains( $raw_q, '+' ) ) && preg_match_all( '/(?<!\d)(\d{2,3})(?!\d)/', $rest, $wm, PREG_SET_ORDER ) ) {
			foreach ( $wm as $m ) {
				$w = (int) $m[1];
				if ( $w >= 60 && $w <= 360 ) {
					$partials[] = (string) $w;
					$rest       = str_replace( $m[0], ' ', $rest );
				}
			}
		}
		if ( ! empty( $sizes ) && ! empty( $partials ) ) {
			$wants_kit = true;
		}

		$clean_rest = SearchText::normalize( (string) preg_replace( '/[+&,\/]+/', ' ', $rest ) );
		$raw_tokens = '' !== $clean_rest ? explode( ' ', $clean_rest ) : array();
		$tokens     = array();
		foreach ( $raw_tokens as $tok ) {
			$tok = trim( $tok, "-_." );
			if ( '' === $tok ) {
				continue;
			}
			if ( isset( self::KIT_WORDS[ $tok ] ) ) {
				$wants_kit = true;
				continue;
			}
			if ( isset( self::STOP_WORDS[ $tok ] ) ) {
				continue;
			}
			if ( strlen( $tok ) >= 2 || is_numeric( $tok ) ) {
				$tokens[] = $tok;
			}
		}

		// Preserve single meaningful stopword when nothing else was typed (e.g. user literally searched "cubiertas").
		if ( empty( $tokens ) && empty( $sizes ) && empty( $partials ) && ! $wants_kit && ! empty( $raw_tokens ) ) {
			foreach ( $raw_tokens as $tok ) {
				$tok = trim( $tok, "-_." );
				if ( strlen( $tok ) >= 2 ) {
					$tokens[] = $tok;
				}
			}
		}

		return array(
			'raw'       => $raw_q,
			'norm'      => $norm,
			'sizes'     => $sizes,
			'partials'  => array_values( array_unique( $partials ) ),
			'tokens'    => array_values( array_unique( $tokens ) ),
			'wants_kit' => $wants_kit,
		);
	}

	/**
	 * Checks whether a kit document satisfies the analyzed query.
	 *
	 * @param array<string,mixed> $doc Search document.
	 * @param array<string,mixed> $pq  Output of analyze_query().
	 */
	private static function kit_matches_query( array $doc, array $pq ): bool {
		$doc_sizes = is_array( $doc['sizes'] ?? null ) ? $doc['sizes'] : array();
		$sizes     = $pq['sizes'];
		$partials  = $pq['partials'];
		$tokens    = $pq['tokens'];

		// All full sizes in the query must be present in the kit (either front or rear, in any order).
		$used = array();
		foreach ( array_slice( $sizes, 0, 2 ) as $sp ) {
			$canon = (string) $sp['canonical'];
			$found = false;
			foreach ( $doc_sizes as $idx => $ds ) {
				if ( ! isset( $used[ $idx ] ) && $ds === $canon ) {
					$used[ $idx ] = true;
					$found        = true;
					break;
				}
			}
			if ( ! $found ) {
				return false;
			}
		}

		// Any partial 2nd size (e.g. "120" or "120/70") must match the remaining axle of the kit.
		foreach ( $partials as $part ) {
			$matched_part = false;
			foreach ( $doc_sizes as $idx => $ds ) {
				if ( isset( $used[ $idx ] ) ) {
					continue;
				}
				if ( str_starts_with( (string) $ds, $part ) ) {
					$used[ $idx ] = true;
					$matched_part = true;
					break;
				}
			}
			if ( ! $matched_part ) {
				return false;
			}
		}

		// Any extra text tokens (e.g. "pirelli", "mt60rs") must match title, SKU, specs or description.
		foreach ( $tokens as $tok ) {
			if ( ! self::token_in_doc( $doc, $tok ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Strict relevance scorer for one product document.
	 *
	 * @param array<string,mixed> $doc              Product search document.
	 * @param array<string,mixed> $pq               Analyzed query.
	 * @param array<int,true>     $bike_product_ids Compatible product IDs for matched bikes.
	 */
	private static function score_document( array $doc, array $pq, array $bike_product_ids ): int {
		$score      = 0;
		$is_kit     = ! empty( $doc['is_kit'] );
		$doc_sizes  = is_array( $doc['sizes'] ?? null ) ? $doc['sizes'] : array();
		$doc_consts = is_array( $doc['constructions'] ?? null ) ? $doc['constructions'] : array();
		$sizes      = $pq['sizes'];
		$partials   = $pq['partials'];
		$tokens     = $pq['tokens'];
		$wants_kit  = ! empty( $pq['wants_kit'] );

		// 1. Size matching (2+ sizes, 1 size + partial 2nd size, 1 size, or partial size).
		if ( ! empty( $sizes ) ) {
			$matched_sizes = 0;
			$const_bonus   = 0;
			$used          = array();
			foreach ( $sizes as $sp ) {
				$canon = (string) $sp['canonical'];
				$need  = (string) ( $sp['construction'] ?? '' );
				foreach ( $doc_sizes as $idx => $ds ) {
					if ( ! isset( $used[ $idx ] ) && $ds === $canon ) {
						$used[ $idx ] = true;
						++$matched_sizes;
						if ( '' !== $need && in_array( $need, $doc_consts, true ) ) {
							$const_bonus += 25;
						}
						break;
					}
				}
			}

			$matched_partials = 0;
			foreach ( $partials as $part ) {
				foreach ( $doc_sizes as $idx => $ds ) {
					if ( ! isset( $used[ $idx ] ) && str_starts_with( (string) $ds, $part ) ) {
						$used[ $idx ] = true;
						++$matched_partials;
						break;
					}
				}
			}

			if ( count( $sizes ) >= 2 ) {
				if ( $is_kit && $matched_sizes >= 2 ) {
					$score += 320 + $const_bonus;
				} elseif ( $is_kit && 1 === $matched_sizes ) {
					$score += 110 + $const_bonus;
				} elseif ( $matched_sizes >= 1 ) {
					$score += 65 + $const_bonus;
				} else {
					return 0;
				}
			} elseif ( ! empty( $partials ) ) {
				// 1 full size + partial 2nd size (e.g. "160 60 17 + 120" or "160/60-17 + 120/70").
				if ( $is_kit && $matched_sizes >= 1 && $matched_partials >= 1 ) {
					$score += 300 + $const_bonus;
				} elseif ( $is_kit && $matched_sizes >= 1 ) {
					$score += 130 + $const_bonus;
				} elseif ( $matched_sizes >= 1 ) {
					$score += 80 + $const_bonus;
				} else {
					return 0;
				}
			} else {
				// Single full size.
				if ( $matched_sizes < 1 ) {
					return 0;
				}
				if ( $is_kit ) {
					$score += ( $wants_kit ? 260 : 140 ) + $const_bonus;
				} else {
					$score += ( $wants_kit ? 110 : 190 ) + $const_bonus;
				}
			}
		} elseif ( ! empty( $partials ) ) {
			$hit = 0;
			foreach ( $partials as $part ) {
				$matched_p = false;
				foreach ( $doc_sizes as $ds ) {
					if ( str_starts_with( (string) $ds, $part ) ) {
						$matched_p = true;
						break;
					}
				}
				if ( ! $matched_p && self::token_in_doc( $doc, $part ) ) {
					$matched_p = true;
				}
				if ( $matched_p ) {
					++$hit;
				}
			}
			if ( $hit < count( $partials ) ) {
				return 0;
			}
			$score += 120 * $hit;
			if ( $wants_kit && $is_kit ) {
				$score += 90;
			}
		} elseif ( $wants_kit && empty( $tokens ) ) {
			// User literally typed "kit", "combo", "par"...
			if ( $is_kit ) {
				$score += 200;
			} elseif ( self::token_in_doc( $doc, 'kit' ) ) {
				$score += 60;
			} else {
				return 0;
			}
		}

		// 2. Text tokens (title, SKU, specs/attributes/brand/model, description/excerpt).
		if ( ! empty( $tokens ) ) {
			$all_matched = true;
			$tok_score   = 0;
			foreach ( $tokens as $tok ) {
				$ts = self::score_token( $doc, $tok );
				if ( $ts <= 0 ) {
					$all_matched = false;
					break;
				}
				$tok_score += $ts;
			}

			if ( $all_matched ) {
				$score += $tok_score;
				// Whole-query phrase boost in title or specs (e.g. "mt60 rs" / "mt60rs").
				$compact_q = SearchText::compact( implode( ' ', $tokens ) );
				if ( '' !== $compact_q && str_contains( (string) $doc['title_hay'], $compact_q ) ) {
					$score += 45;
				} elseif ( '' !== $compact_q && str_contains( (string) $doc['spec_hay'], $compact_q ) ) {
					$score += 30;
				}
			} elseif ( isset( $bike_product_ids[ (int) $doc['id'] ] ) ) {
				// Matched a compatible motorcycle (e.g. "Honda Tornado", "Benelli TRK 502").
				$score += 110;
			} else {
				return 0;
			}
		}

		if ( $wants_kit && $is_kit && $score > 0 ) {
			$score += 75;
		}

		return $score;
	}

	/**
	 * Relaxed fallback scorer when strict AND across all tokens returned 0 products.
	 *
	 * @param array<string,mixed> $doc Product search document.
	 * @param array<string,mixed> $pq  Analyzed query.
	 */
	private static function score_document_relaxed( array $doc, array $pq ): int {
		$score = 0;
		foreach ( $pq['sizes'] as $sp ) {
			if ( in_array( (string) $sp['canonical'], $doc['sizes'] ?? array(), true ) ) {
				$score += 100;
			}
		}
		$hits = 0;
		foreach ( $pq['tokens'] as $tok ) {
			$ts = self::score_token( $doc, $tok );
			if ( $ts > 0 ) {
				++$hits;
				$score += $ts;
			}
		}
		if ( ! empty( $pq['tokens'] ) && 0 === $hits && 0 === $score ) {
			return 0;
		}
		// Require at least half of the text tokens when no size matched.
		if ( empty( $pq['sizes'] ) && $hits < (int) ceil( count( $pq['tokens'] ) / 2 ) ) {
			return 0;
		}
		return $score;
	}

	/**
	 * Scores a single normalized token across SKU, title, technical specs and description.
	 *
	 * @param array<string,mixed> $doc Document.
	 * @param string              $tok Normalized token.
	 */
	private static function score_token( array $doc, string $tok ): int {
		$compact = SearchText::compact( $tok );
		$sku     = (string) ( $doc['sku_hay'] ?? '' );
		if ( '' !== $sku && ( str_contains( $sku, $tok ) || ( '' !== $compact && str_contains( $sku, $compact ) ) ) ) {
			return 140;
		}
		$title = (string) ( $doc['title_hay'] ?? '' );
		if ( str_contains( $title, $tok ) || ( '' !== $compact && str_contains( $title, $compact ) ) ) {
			return 65;
		}
		$spec = (string) ( $doc['spec_hay'] ?? '' );
		if ( str_contains( $spec, $tok ) || ( '' !== $compact && str_contains( $spec, $compact ) ) ) {
			return 45;
		}
		$desc = (string) ( $doc['desc_hay'] ?? '' );
		if ( str_contains( $desc, $tok ) || ( '' !== $compact && str_contains( $desc, $compact ) ) ) {
			return 20;
		}
		return 0;
	}

	/**
	 * Boolean check whether a token exists anywhere in a document.
	 *
	 * @param array<string,mixed> $doc Document.
	 * @param string              $tok Token.
	 */
	private static function token_in_doc( array $doc, string $tok ): bool {
		return self::score_token( $doc, $tok ) > 0;
	}

	/**
	 * Builds the compact search index from WordPress/WooCommerce tables in 2-3 batch SQL queries (0 N+1).
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function build_index_from_db(): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}

		$posts    = $wpdb->posts;
		$postmeta = $wpdb->postmeta;
		$tr       = $wpdb->term_relationships;
		$tt       = $wpdb->term_taxonomy;
		$t        = $wpdb->terms;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed core/plugin tables, cached in Support\Cache.
		$prod_rows = $wpdb->get_results(
			"SELECT p.ID AS id,
				p.post_title AS title,
				p.post_excerpt AS excerpt,
				p.post_content AS content,
				p.menu_order AS menu_order,
				COALESCE(pm_st.meta_value, 'instock') AS stock_status,
				COALESCE(pm_sku.meta_value, '') AS sku,
				COALESCE(pm_fs.meta_value, '') AS full_size,
				COALESCE(pm_ks.meta_value, '') AS kit_spec
			FROM {$posts} p
			LEFT JOIN {$postmeta} pm_st  ON pm_st.post_id  = p.ID AND pm_st.meta_key  = '_stock_status'
			LEFT JOIN {$postmeta} pm_sku ON pm_sku.post_id = p.ID AND pm_sku.meta_key = '_sku'
			LEFT JOIN {$postmeta} pm_fs  ON pm_fs.post_id  = p.ID AND pm_fs.meta_key  = '" . esc_sql( CompatStorefront::FULL_SIZE_META ) . "'
			LEFT JOIN {$postmeta} pm_ks  ON pm_ks.post_id  = p.ID AND pm_ks.meta_key  = '" . esc_sql( KitSpec::META ) . "'
			WHERE p.post_type = 'product'
				AND p.post_status = 'publish'
				AND p.post_parent = 0
				AND NOT EXISTS (
					SELECT 1
					FROM {$tr} trv
					INNER JOIN {$tt} ttv ON ttv.term_taxonomy_id = trv.term_taxonomy_id AND ttv.taxonomy = 'product_visibility'
					INNER JOIN {$t} tv ON tv.term_id = ttv.term_id AND tv.slug = 'exclude-from-search'
					WHERE trv.object_id = p.ID
				)",
			ARRAY_A
		);

		if ( ! is_array( $prod_rows ) || empty( $prod_rows ) ) {
			return array();
		}

		$by_id = array();
		foreach ( $prod_rows as $r ) {
			$pid = (int) ( $r['id'] ?? 0 );
			if ( $pid <= 0 ) {
				continue;
			}
			$by_id[ $pid ] = array(
				'id'           => $pid,
				'title'        => (string) ( $r['title'] ?? '' ),
				'excerpt'      => (string) ( $r['excerpt'] ?? '' ),
				'content'      => (string) ( $r['content'] ?? '' ),
				'menu_order'   => (int) ( $r['menu_order'] ?? 0 ),
				'in_stock'     => 'outofstock' !== strtolower( trim( (string) ( $r['stock_status'] ?? 'instock' ) ) ),
				'sku'          => (string) ( $r['sku'] ?? '' ),
				'full_size'    => (string) ( $r['full_size'] ?? '' ),
				'kit_spec'     => KitSpec::normalize( maybe_unserialize( (string) ( $r['kit_spec'] ?? '' ) ) ),
				'terms'        => array(),
				'attr_map'     => array(),
				'compat_index' => null,
			);
		}

		$ids_in = implode( ',', array_keys( $by_id ) );
		$taxes  = array( 'product_brand', 'product_cat', 'product_tag' );
		foreach ( array_keys( TireSpecs::attribute_order_map() ) as $attr_slug ) {
			$taxes[] = 'pa_' . $attr_slug;
		}
		$tax_in = "'" . implode( "','", array_map( 'esc_sql', $taxes ) ) . "'";

		$term_rows = $wpdb->get_results(
			"SELECT tr.object_id AS id, tt.taxonomy AS taxonomy, t.slug AS slug, t.name AS name
			FROM {$tr} tr
			INNER JOIN {$tt} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy IN ({$tax_in})
			INNER JOIN {$t} t ON t.term_id = tt.term_id
			WHERE tr.object_id IN ({$ids_in})",
			ARRAY_A
		);
		foreach ( is_array( $term_rows ) ? $term_rows : array() as $tr_row ) {
			$pid = (int) ( $tr_row['id'] ?? 0 );
			if ( ! isset( $by_id[ $pid ] ) ) {
				continue;
			}
			$tax  = (string) ( $tr_row['taxonomy'] ?? '' );
			$slug = (string) ( $tr_row['slug'] ?? '' );
			$name = (string) ( $tr_row['name'] ?? '' );
			$by_id[ $pid ]['terms'][] = $name;
			if ( str_starts_with( $tax, 'pa_' ) ) {
				$by_id[ $pid ]['attr_map'][ substr( $tax, 3 ) ][] = array(
					'slug' => $slug,
					'name' => $name,
				);
			}
		}

		// Attach compatibility product index rows (covers kits & normalized sizes/constructions).
		if ( CompatConfig::enabled() ) {
			$compat_rows = ProductIndexRepository::find_many( array_keys( $by_id ) );
			foreach ( $compat_rows as $pid => $crow ) {
				if ( isset( $by_id[ $pid ] ) ) {
					$by_id[ $pid ]['compat_index'] = $crow;
				}
			}
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$fixed_terms = AttributeInstaller::fixed_term_sets();
		$docs        = array();
		foreach ( $by_id as $raw ) {
			$docs[] = self::compile_document( $raw, $fixed_terms );
		}
		return $docs;
	}

	/**
	 * Compiles one raw DB product bundle into a compact searchable document.
	 *
	 * @param array<string,mixed>                    $raw         Raw product data.
	 * @param array<string,array<int|string,string>> $fixed_terms Localized fixed terms.
	 * @return array<string,mixed>
	 */
	private static function compile_document( array $raw, array $fixed_terms ): array {
		$attrs = is_array( $raw['attr_map'] ?? null ) ? $raw['attr_map'] : array();
		$cidx  = is_array( $raw['compat_index'] ?? null ) ? $raw['compat_index'] : null;
		$kspec = is_array( $raw['kit_spec'] ?? null ) ? $raw['kit_spec'] : null;

		$pos = '';
		if ( null !== $cidx && ! empty( $cidx['position'] ) ) {
			$pos = (string) $cidx['position'];
		} elseif ( ! empty( $attrs[ TireSpecs::ATTR_POSITION ][0] ) ) {
			$pos = TireSpecs::normalize_position( (string) $attrs[ TireSpecs::ATTR_POSITION ][0]['slug'] );
		}
		$is_kit = TireSpecs::POS_KIT === $pos;

		$front_canon = '';
		$rear_canon  = '';
		$front_desig = '';
		$rear_desig  = '';
		$const_set   = array();

		if ( null !== $cidx ) {
			$front_canon = (string) ( $cidx['front_canonical'] ?? '' );
			$rear_canon  = (string) ( $cidx['rear_canonical'] ?? '' );
			foreach ( array( 'construction', 'front_construction', 'rear_construction' ) as $ck ) {
				if ( ! empty( $cidx[ $ck ] ) ) {
					$const_set[ (string) $cidx[ $ck ] ] = true;
				}
			}
		}

		// Fallback to attribute terms if product is not in the compatibility index yet.
		if ( '' === $front_canon && ! empty( $attrs[ TireSpecs::ATTR_FRONT_SIZE ][0]['name'] ) ) {
			$front_canon = (string) SizeParser::canonical( (string) $attrs[ TireSpecs::ATTR_FRONT_SIZE ][0]['name'] );
		}
		if ( '' === $rear_canon && ! empty( $attrs[ TireSpecs::ATTR_REAR_SIZE ][0]['name'] ) ) {
			$rear_canon = (string) SizeParser::canonical( (string) $attrs[ TireSpecs::ATTR_REAR_SIZE ][0]['name'] );
		}
		if ( '' === $front_canon && '' === $rear_canon && ! $is_kit ) {
			$w = (string) ( $attrs[ TireSpecs::ATTR_WIDTH ][0]['name'] ?? '' );
			$p = (string) ( $attrs[ TireSpecs::ATTR_PROFILE ][0]['name'] ?? '' );
			$r = (string) ( $attrs[ TireSpecs::ATTR_RIM ][0]['name'] ?? '' );
			if ( '' !== $w && '' !== $r ) {
				$front_canon = (string) SizeParser::canonical( '' !== $p ? $w . '/' . $p . '-' . $r : $w . '-' . $r );
			}
		}

		foreach ( $attrs[ TireSpecs::ATTR_CONSTRUCTION ] ?? array() as $ct ) {
			$nc = TireSpecs::normalize_construction( (string) $ct['slug'] );
			if ( '' !== $nc ) {
				$const_set[ $nc ] = true;
			}
		}

		// Resolve printed designations (with R / ZR / B / -) for front & rear.
		if ( $is_kit && null !== $kspec ) {
			$front_desig = (string) ( $kspec[ TireSpecs::POS_FRONT ]['designation'] ?? '' );
			$rear_desig  = (string) ( $kspec[ TireSpecs::POS_REAR ]['designation'] ?? '' );
			foreach ( array( TireSpecs::POS_FRONT, TireSpecs::POS_REAR ) as $ax ) {
				if ( ! empty( $kspec[ $ax ]['construction'] ) ) {
					$const_set[ (string) $kspec[ $ax ]['construction'] ] = true;
				}
			}
		}
		if ( '' === $front_desig && '' !== $front_canon ) {
			$fp    = SizeParser::parse( $front_canon );
			$f_con = (string) ( $cidx['front_construction'] ?? $cidx['construction'] ?? array_key_first( $const_set ) ?? '' );
			$f_spd = null !== $cidx && null !== ( $cidx['front_speed_rank'] ?? null )
				? TireSpecs::speed_code_for_rank( (int) $cidx['front_speed_rank'] )
				: (string) ( $attrs[ TireSpecs::ATTR_SPEED_RATING ][0]['name'] ?? '' );
			$front_desig = null !== $fp ? SizeParser::format_full_size( $fp, $f_con, $f_spd ) : $front_canon;
		}
		if ( '' === $rear_desig && '' !== $rear_canon ) {
			$rp    = SizeParser::parse( $rear_canon );
			$r_con = (string) ( $cidx['rear_construction'] ?? $cidx['construction'] ?? array_key_first( $const_set ) ?? '' );
			$r_spd = null !== $cidx && null !== ( $cidx['rear_speed_rank'] ?? null )
				? TireSpecs::speed_code_for_rank( (int) $cidx['rear_speed_rank'] )
				: (string) ( $attrs[ TireSpecs::ATTR_SPEED_RATING ][0]['name'] ?? '' );
			$rear_desig = null !== $rp ? SizeParser::format_full_size( $rp, $r_con, $r_spd ) : $rear_canon;
		}

		$sizes = array();
		if ( '' !== $front_canon ) {
			$sizes[] = $front_canon;
		}
		if ( '' !== $rear_canon && ( $is_kit || $rear_canon !== $front_canon ) ) {
			$sizes[] = $rear_canon;
		}

		// Assemble rich technical-specs haystack (attributes, sizes in all formats, synonyms, kit sheet).
		$spec_parts = is_array( $raw['terms'] ?? null ) ? $raw['terms'] : array();
		if ( '' !== (string) ( $raw['full_size'] ?? '' ) ) {
			$spec_parts[] = (string) $raw['full_size'];
		}
		foreach ( array_filter( array( $front_canon, $rear_canon, $front_desig, $rear_desig ) ) as $sz ) {
			$spec_parts[] = $sz;
			$spec_parts[] = str_replace( array( '/', '-' ), ' ', $sz );
		}
		if ( '' !== $pos ) {
			$spec_parts[] = TireSpecs::position_label( $pos );
			if ( $is_kit ) {
				$spec_parts[] = 'kit combo par juego delantero trasero';
			} elseif ( TireSpecs::POS_FRONT === $pos ) {
				$spec_parts[] = 'delantero delantera front';
			} elseif ( TireSpecs::POS_REAR === $pos ) {
				$spec_parts[] = 'trasero trasera rear';
			} elseif ( TireSpecs::POS_BOTH === $pos ) {
				$spec_parts[] = 'delantero delantera trasero trasera ambas';
			}
		}
		foreach ( array_keys( $const_set ) as $cs ) {
			$spec_parts[] = $cs;
			if ( isset( $fixed_terms[ TireSpecs::ATTR_CONSTRUCTION ][ $cs ] ) ) {
				$spec_parts[] = (string) $fixed_terms[ TireSpecs::ATTR_CONSTRUCTION ][ $cs ];
			}
		}
		foreach ( $attrs[ TireSpecs::ATTR_TUBE ] ?? array() as $tb ) {
			$slug = (string) $tb['slug'];
			if ( 'tl' === $slug || 'tl-tt' === $slug ) {
				$spec_parts[] = 'tl tubeless sin camara';
			}
			if ( 'tt' === $slug || 'tl-tt' === $slug ) {
				$spec_parts[] = 'tt tube type con camara';
			}
		}
		$load  = (string) ( $attrs[ TireSpecs::ATTR_LOAD_INDEX ][0]['name'] ?? '' );
		$speed = (string) ( $attrs[ TireSpecs::ATTR_SPEED_RATING ][0]['name'] ?? '' );
		if ( '' !== $load && '' !== $speed ) {
			$spec_parts[] = $load . $speed;
		}
		if ( null !== $kspec ) {
			foreach ( array( TireSpecs::POS_FRONT, TireSpecs::POS_REAR ) as $ax ) {
				$a = $kspec[ $ax ];
				if ( '' !== (string) $a['line'] ) {
					$spec_parts[] = (string) $a['line'];
				}
				if ( null !== $a['load'] && '' !== (string) $a['speed'] ) {
					$spec_parts[] = (string) $a['load'] . (string) $a['speed'];
				}
			}
		}

		$desc_raw = trim( (string) ( $raw['excerpt'] ?? '' ) . ' ' . (string) ( $raw['content'] ?? '' ) );
		if ( '' !== $desc_raw ) {
			if ( function_exists( 'strip_shortcodes' ) ) {
				$desc_raw = strip_shortcodes( $desc_raw );
			}
			$desc_raw = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $desc_raw ) : strip_tags( $desc_raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Fallback outside WP.
			$desc_raw = substr( $desc_raw, 0, 2500 );
		}

		return array(
			'id'            => (int) $raw['id'],
			'in_stock'      => ! empty( $raw['in_stock'] ),
			'is_kit'        => $is_kit,
			'menu_order'    => (int) ( $raw['menu_order'] ?? 0 ),
			'front'         => $front_canon,
			'rear'          => $rear_canon,
			'front_desig'   => $front_desig,
			'rear_desig'    => $rear_desig,
			'sizes'         => $sizes,
			'constructions' => array_keys( $const_set ),
			'title_hay'     => SearchText::haystack( (string) ( $raw['title'] ?? '' ) ),
			'sku_hay'       => SearchText::haystack( (string) ( $raw['sku'] ?? '' ) ),
			'spec_hay'      => SearchText::haystack( implode( ' ', $spec_parts ) ),
			'desc_hay'      => '' !== $desc_raw ? SearchText::haystack( $desc_raw ) : '',
		);
	}
}
