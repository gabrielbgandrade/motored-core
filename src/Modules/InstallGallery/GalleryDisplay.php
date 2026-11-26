<?php
/**
 * Product page output of the gallery.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\InstallGallery;

defined( 'ABSPATH' ) || exit;

/**
 * A horizontal strip of square thumbnails with optional source filters; clicking any photo opens a native
 * <dialog> split-view lightbox showing the full `large` photo alongside the customer review or installation info.
 * Weight rules: only the strip thumbnails are images (lazy, `thumbnail` size); hidden photos are plain links
 * (0 image bytes until filtered/opened) and the dialog loads `large` one photo at a time. No theme dependency.
 */
final class GalleryDisplay {

	public const HANDLE     = 'motored-install-gallery';
	public const CSS_HANDLE = 'motored-install-gallery-css';

	/**
	 * Maximum length of a review comment excerpt embedded in the lightbox data attribute.
	 */
	private const MAX_REVIEW_CHARS = 900;

	/**
	 * Product ids whose gallery was already printed on this request (never twice per product).
	 *
	 * @var array<int,bool>
	 */
	private array $done_ids = array();

	/**
	 * Action callback right below the native product gallery inside the gallery column.
	 */
	public function render_below_gallery(): void {
		$this->render_for_placement( Config::PLACE_BELOW_GALLERY );
	}

	/**
	 * Action callback right below the Add to Cart button inside the product summary column.
	 */
	public function render_after_add_to_cart(): void {
		$this->render_for_placement( Config::PLACE_AFTER_ADD_TO_CART );
	}

	/**
	 * Action callback right below product gallery/summary (above tabs).
	 */
	public function render_before_tabs(): void {
		$this->render_for_placement( Config::PLACE_BEFORE_TABS );
	}

	/**
	 * Action callback right below product tabs.
	 */
	public function render_after_tabs(): void {
		$this->render_for_placement( Config::PLACE_AFTER_TABS );
	}

	/**
	 * Backwards-compatible entrypoint: renders at the default action placement (`below_gallery`).
	 */
	public function render(): void {
		$this->render_below_gallery();
	}

	/**
	 * Block themes: appends the gallery right below the Product Image Gallery block when placement is `below_gallery`.
	 *
	 * @param mixed $content Rendered block HTML.
	 */
	public function filter_gallery_block( $content ): string {
		$html = is_string( $content ) ? $content : '';
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return $html;
		}
		$product_id = $this->resolve_product_id( 0 );
		if ( $product_id < 1 ) {
			return $html;
		}
		$meta = $this->read_meta( $product_id );
		if ( array() === $meta['install'] && array() === $meta['reviews'] ) {
			return $html; // Zero option reads on products without photos.
		}
		if ( ! Config::enabled() || Config::PLACE_BELOW_GALLERY !== Config::placement() ) {
			return $html;
		}
		return $html . $this->html( $product_id, true, Config::PLACE_BELOW_GALLERY );
	}

	/**
	 * Block themes: appends the gallery right below the Add to Cart Form block when placement is `after_add_to_cart`.
	 *
	 * @param mixed $content Rendered block HTML.
	 */
	public function filter_add_to_cart_block( $content ): string {
		$html = is_string( $content ) ? $content : '';
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return $html;
		}
		$product_id = $this->resolve_product_id( 0 );
		if ( $product_id < 1 ) {
			return $html;
		}
		$meta = $this->read_meta( $product_id );
		if ( array() === $meta['install'] && array() === $meta['reviews'] ) {
			return $html; // Zero option reads on products without photos.
		}
		if ( ! Config::enabled() || Config::PLACE_AFTER_ADD_TO_CART !== Config::placement() ) {
			return $html;
		}
		return $html . $this->html( $product_id, true, Config::PLACE_AFTER_ADD_TO_CART );
	}

	/**
	 * Block themes: prints the gallery before/after the Product Details block (same placement setting, same
	 * once-per-product guard, so a theme that fires both the classic hook and the block never duplicates it).
	 *
	 * @param mixed $content Rendered block HTML.
	 */
	public function filter_details_block( $content ): string {
		$html = is_string( $content ) ? $content : '';
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return $html;
		}
		$product_id = $this->resolve_product_id( 0 );
		if ( $product_id < 1 ) {
			return $html;
		}
		$meta = $this->read_meta( $product_id );
		if ( array() === $meta['install'] && array() === $meta['reviews'] ) {
			return $html; // Zero option reads on products without photos.
		}
		if ( ! Config::enabled() ) {
			return $html;
		}
		$placement = Config::placement();
		if ( Config::PLACE_BEFORE_TABS === $placement ) {
			return $this->html( $product_id, true, $placement ) . $html;
		}
		if ( Config::PLACE_AFTER_TABS === $placement ) {
			return $html . $this->html( $product_id, true, $placement );
		}
		return $html;
	}

	/**
	 * Registers a dedicated WooCommerce product tab when placement is `tab`.
	 *
	 * @param mixed $tabs WooCommerce tabs array.
	 * @return mixed
	 */
	public function filter_product_tab( $tabs ) {
		if ( ! is_array( $tabs ) ) {
			return $tabs;
		}
		$product_id = $this->resolve_product_id( 0 );
		if ( $product_id < 1 ) {
			return $tabs;
		}
		$meta = $this->read_meta( $product_id );
		if ( array() === $meta['install'] && array() === $meta['reviews'] ) {
			return $tabs; // Zero option reads on products without photos.
		}
		if ( ! Config::enabled() || Config::PLACE_TAB !== Config::placement() ) {
			return $tabs;
		}
		$entries = GalleryItems::merge( $meta['install'], Config::include_reviews() ? $meta['reviews'] : array() );
		if ( array() === $entries ) {
			return $tabs;
		}
		$this->enqueue();
		$count                           = count( $entries );
		$tabs['motored_install_gallery'] = array(
			/* translators: %d: number of photos in the gallery. */
			'title'    => sprintf( __( 'Customer photos (%d)', 'motored-core' ), $count ),
			'priority' => 28,
			'callback' => function () use ( $product_id ): void {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in html().
				echo $this->html( $product_id, false, Config::PLACE_TAB );
			},
		);
		return $tabs;
	}

	/**
	 * Shortcode `[motored_install_gallery id="..."]` for block themes or custom builders.
	 *
	 * @param mixed $atts Shortcode attributes.
	 */
	public function shortcode( $atts = array() ): string {
		$explicit = is_array( $atts ) && isset( $atts['id'] ) && is_scalar( $atts['id'] ) ? max( 0, (int) $atts['id'] ) : 0;
		return $this->html( $explicit, true, Config::PLACE_SHORTCODE );
	}

	/**
	 * Checks meta first (0 option reads when product has no photos), then prints if matching configured placement.
	 *
	 * @param string $expected_placement Placement constant.
	 */
	private function render_for_placement( string $expected_placement ): void {
		$product_id = $this->resolve_product_id( 0 );
		if ( $product_id < 1 ) {
			return;
		}
		$meta = $this->read_meta( $product_id );
		if ( array() === $meta['install'] && array() === $meta['reviews'] ) {
			return; // Zero option reads on products without photos.
		}
		if ( ! Config::enabled() || Config::placement() !== $expected_placement ) {
			return;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in html().
		echo $this->html( $product_id, true, $expected_placement );
	}

	/**
	 * Markup of the gallery ('' when the product has no photos).
	 * Reads the product meta first (already primed with the product: 0 queries), the option second.
	 *
	 * @param int    $explicit_id  Optional explicit product ID.
	 * @param bool   $with_heading Print outer section heading (`h2`).
	 * @param string $placement    Active placement identifier for layout tuning.
	 */
	private function html( int $explicit_id = 0, bool $with_heading = true, string $placement = Config::PLACE_SHORTCODE ): string {
		$product_id = $this->resolve_product_id( $explicit_id );
		if ( $product_id < 1 || isset( $this->done_ids[ $product_id ] ) ) {
			return '';
		}
		$meta = $this->read_meta( $product_id );
		if ( array() === $meta['install'] && array() === $meta['reviews'] ) {
			return '';
		}
		if ( ! Config::enabled() ) {
			return '';
		}
		$entries = GalleryItems::merge( $meta['install'], Config::include_reviews() ? $meta['reviews'] : array() );
		if ( array() === $entries ) {
			return '';
		}
		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_column( $entries, 'id' ), false, true ); // One query for every attachment and its meta.
		}

		$rows = $this->rows( $entries, $product_id );
		if ( array() === $rows ) {
			return '';
		}
		$this->done_ids[ $product_id ] = true;
		$this->enqueue();

		$modes       = GalleryItems::layout( count( $rows ), Config::visible() );
		$more        = GalleryItems::more( count( $rows ), Config::visible() );
		$anchors     = '';
		$install_cnt = 0;
		$review_cnt  = 0;
		foreach ( $rows as $i => $row ) {
			if ( 'review' === $row['src'] ) {
				++$review_cnt;
			} else {
				++$install_cnt;
			}
			$anchors .= $this->anchor( $row, $modes[ $i ], $more );
		}

		$has_filters = $install_cnt > 0 && $review_cnt > 0;
		$head        = '';
		if ( $with_heading ) {
			$head .= '<div class="motored-ig__head">';
			$head .= '<h2 class="motored-ig__title">' . esc_html__( 'Customer and installation photos', 'motored-core' ) . '</h2>';
			$head .= '</div>';
		}

		$filters = '';
		if ( $has_filters ) {
			$filters .= '<div class="motored-ig__filters" role="group" aria-label="' . esc_attr__( 'Filter photos', 'motored-core' ) . '">';
			/* translators: %d: total photo count. */
			$filters .= '<button type="button" class="motored-ig__filter is-active" aria-pressed="true" data-ig-filter="all">' . esc_html( sprintf( __( 'All (%d)', 'motored-core' ), count( $rows ) ) ) . '</button>';
			/* translators: %d: installation photo count. */
			$filters .= '<button type="button" class="motored-ig__filter" aria-pressed="false" data-ig-filter="install">' . esc_html( sprintf( __( 'Installations (%d)', 'motored-core' ), $install_cnt ) ) . '</button>';
			/* translators: %d: customer review photo count. */
			$filters .= '<button type="button" class="motored-ig__filter" aria-pressed="false" data-ig-filter="review">' . esc_html( sprintf( __( 'Reviews (%d)', 'motored-core' ), $review_cnt ) ) . '</button>';
			$filters .= '</div>';
		}

		$product_name = self::plain( (string) get_the_title( $product_id ) );
		$product_url  = (string) get_permalink( $product_id );
		$product_img  = self::product_thumb_url( $product_id );

		$prev_svg = '<svg viewBox="0 0 14 24" width="12" height="20" aria-hidden="true" focusable="false"><path d="M12 2L2 12l10 10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="square"/></svg>';
		$next_svg = '<svg viewBox="0 0 14 24" width="12" height="20" aria-hidden="true" focusable="false"><path d="M2 2l10 10L2 22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="square"/></svg>';

		$html  = '<section class="motored-install-gallery"'
			. ' data-placement="' . esc_attr( $placement ) . '"'
			. ' data-has-filters="' . ( $has_filters ? '1' : '0' ) . '"'
			. ' aria-label="' . esc_attr__( 'Customer and installation photos', 'motored-core' ) . '"'
			. ' data-title="' . esc_attr__( 'Customer and installation photos', 'motored-core' ) . '"'
			. ' data-close="' . esc_attr__( 'Close', 'motored-core' ) . '"'
			. ' data-prev="' . esc_attr__( 'Previous photo', 'motored-core' ) . '"'
			. ' data-next="' . esc_attr__( 'Next photo', 'motored-core' ) . '"'
			. ' data-rv="' . esc_attr__( 'View review', 'motored-core' ) . '"'
			. ' data-install-lbl="' . esc_attr__( 'Installation photo', 'motored-core' ) . '"'
			. ' data-review-lbl="' . esc_attr__( 'Customer review', 'motored-core' ) . '"'
			. ' data-verified-lbl="' . esc_attr__( 'Verified purchase', 'motored-core' ) . '"'
			. ' data-install-fallback="' . esc_attr__( 'Real photo fitted on a motorcycle.', 'motored-core' ) . '"'
			. ( '' !== $product_name ? ' data-prod-name="' . esc_attr( $product_name ) . '"' : '' )
			. ( '' !== $product_url ? ' data-prod-url="' . esc_url( $product_url ) . '"' : '' )
			. ( '' !== $product_img ? ' data-prod-img="' . esc_url( $product_img ) . '"' : '' )
			. '>';
		$html .= $head;
		$html .= '<div class="motored-ig__body">';
		$html .= $filters;
		$html .= '<div class="motored-ig__bar">';
		$html .= '<button type="button" class="motored-ig__nav motored-ig__nav--prev" data-ig-nav="-1" aria-label="' . esc_attr__( 'Previous photo', 'motored-core' ) . '" hidden>' . $prev_svg . '</button>';
		$html .= '<div class="motored-ig__grid" data-ig-track>' . $anchors . '</div>';
		$html .= '<button type="button" class="motored-ig__nav motored-ig__nav--next" data-ig-nav="1" aria-label="' . esc_attr__( 'Next photo', 'motored-core' ) . '" hidden>' . $next_svg . '</button>';
		$html .= '</div></div></section>';
		return $html;
	}

	/**
	 * Resolves the target product id across classic templates, block templates, builders and shortcodes.
	 *
	 * @param int $explicit_id Optional explicit id from shortcode/callback.
	 */
	private function resolve_product_id( int $explicit_id ): int {
		if ( $explicit_id > 0 ) {
			return 'product' === get_post_type( $explicit_id ) ? $explicit_id : 0;
		}
		if ( isset( $GLOBALS['product'] ) && $GLOBALS['product'] instanceof \WC_Product ) {
			$pid = (int) $GLOBALS['product']->get_id();
			if ( $pid > 0 && 'product' === get_post_type( $pid ) ) {
				return $pid;
			}
		}
		if ( function_exists( 'get_the_ID' ) ) {
			$pid = (int) get_the_ID();
			if ( $pid > 0 && 'product' === get_post_type( $pid ) ) {
				return $pid;
			}
		}
		if ( function_exists( 'is_product' ) && is_product() && function_exists( 'get_queried_object_id' ) ) {
			$pid = (int) get_queried_object_id();
			if ( $pid > 0 && 'product' === get_post_type( $pid ) ) {
				return $pid;
			}
		}
		return 0;
	}

	/**
	 * Reads primed product gallery meta keys.
	 *
	 * @param int $product_id Product id.
	 * @return array{install:list<int>,reviews:list<array{id:int,c:int,r:int}>}
	 */
	private function read_meta( int $product_id ): array {
		return array(
			'install' => GalleryStore::install_ids( $product_id ),
			'reviews' => GalleryStore::review_entries( $product_id ),
		);
	}

	/**
	 * Resolves URLs, captions and batched review comment details; attachments that no longer exist are skipped.
	 *
	 * @param array<int,array{id:int,c:int,r:int}> $entries    Items.
	 * @param int                                  $product_id Product id.
	 * @return list<array{id:int,url:string,thumb:string,cap:string,alt:string,rv:string,src:string,rating:int,author:string,date:string,text:string,verified:bool}>
	 */
	private function rows( array $entries, int $product_id ): array {
		$permalink   = '';
		$rows        = array();
		$name        = self::plain( (string) get_the_title( $product_id ) );
		$comment_ids = array();
		foreach ( $entries as $entry ) {
			if ( $entry['c'] > 0 ) {
				$comment_ids[] = (int) $entry['c'];
			}
		}
		$reviews_map = self::load_reviews( $comment_ids );

		foreach ( $entries as $entry ) {
			$url = wp_get_attachment_image_url( $entry['id'], 'large' );
			if ( ! is_string( $url ) || '' === $url ) {
				$url = wp_get_attachment_url( $entry['id'] );
			}
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}
			$thumb_url = wp_get_attachment_image_url( $entry['id'], 'thumbnail' );
			$review    = '';
			$is_review = $entry['c'] > 0;
			$rv_meta   = array(
				'author'   => '',
				'date'     => '',
				'text'     => '',
				'verified' => false,
			);
			if ( $is_review ) {
				$permalink = '' === $permalink ? (string) get_permalink( $product_id ) : $permalink;
				$review    = $permalink . '#comment-' . $entry['c'];
				if ( isset( $reviews_map[ $entry['c'] ] ) ) {
					$rv_meta = $reviews_map[ $entry['c'] ];
				}
			}
			$caption = $is_review ? __( 'Customer photo', 'motored-core' ) : wp_get_attachment_caption( $entry['id'] );
			$caption = is_string( $caption ) ? self::plain( $caption ) : '';
			// SEO/a11y: the Media Library alt text wins; then the install caption; then "<product> — customer photo".
			$alt = trim( (string) get_post_meta( $entry['id'], '_wp_attachment_image_alt', true ) );
			if ( '' === $alt ) {
				$alt = ! $is_review && '' !== $caption ? $caption : ( '' !== $name
					/* translators: %s: product name. */
					? sprintf( __( '%s — customer photo', 'motored-core' ), $name )
					: __( 'Customer photo', 'motored-core' ) );
			}
			$rows[] = array(
				'id'       => $entry['id'],
				'url'      => $url,
				'thumb'    => is_string( $thumb_url ) ? $thumb_url : $url,
				'cap'      => $caption,
				'alt'      => $alt,
				'rv'       => $review,
				'src'      => $is_review ? 'review' : 'install',
				'rating'   => (int) $entry['r'],
				'author'   => $rv_meta['author'],
				'date'     => $rv_meta['date'],
				'text'     => $rv_meta['text'],
				'verified' => $rv_meta['verified'],
			);
		}
		return $rows;
	}

	/**
	 * Batch-loads approved review comments in ONE query so the split-view lightbox can show the review text,
	 * author and date immediately on click without extra AJAX calls (no PII such as email/IP is ever read).
	 *
	 * @param list<int> $comment_ids Unique comment IDs referenced by review photos.
	 * @return array<int,array{author:string,date:string,text:string,verified:bool}>
	 */
	private static function load_reviews( array $comment_ids ): array {
		if ( array() === $comment_ids || ! function_exists( 'get_comments' ) ) {
			return array();
		}
		$comments = get_comments(
			array(
				'comment__in' => array_values( array_unique( $comment_ids ) ),
				'status'      => 'approve',
			)
		);
		if ( ! is_array( $comments ) ) {
			return array();
		}
		$date_format = function_exists( 'get_option' ) ? (string) get_option( 'date_format', 'j M Y' ) : 'j M Y';
		$map         = array();
		foreach ( $comments as $comment ) {
			if ( ! is_object( $comment ) || ! isset( $comment->comment_ID ) ) {
				continue;
			}
			$cid      = (int) $comment->comment_ID;
			$author   = isset( $comment->comment_author ) ? self::plain( (string) $comment->comment_author ) : '';
			$has_gmt  = isset( $comment->comment_date_gmt ) && '' !== (string) $comment->comment_date_gmt && '0000-00-00 00:00:00' !== (string) $comment->comment_date_gmt;
			$raw_date = $has_gmt
				? (string) $comment->comment_date_gmt
				: ( isset( $comment->comment_date ) ? (string) $comment->comment_date : '' );
			$date     = '';
			if ( '' !== $raw_date ) {
				$ts = strtotime( $has_gmt ? $raw_date . ' GMT' : $raw_date );
				if ( false !== $ts && function_exists( 'wp_date' ) ) {
					$date = (string) wp_date( $date_format, $ts );
				} elseif ( function_exists( 'mysql2date' ) ) {
					$date = (string) mysql2date( $date_format, $raw_date, true );
				}
			}
			$content  = isset( $comment->comment_content ) ? self::review_text( (string) $comment->comment_content ) : '';
			$verified = function_exists( 'wc_review_is_from_verified_owner' ) && (bool) wc_review_is_from_verified_owner( $cid );

			$map[ $cid ] = array(
				'author'   => $author,
				'date'     => $date,
				'text'     => $content,
				'verified' => $verified,
			);
		}
		return $map;
	}

	/**
	 * Product featured thumbnail URL for the lightbox product footer (uses primed post meta, 0 queries).
	 *
	 * @param int $product_id Product id.
	 */
	private static function product_thumb_url( int $product_id ): string {
		if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
			return '';
		}
		$thumb_id = (int) get_post_thumbnail_id( $product_id );
		if ( $thumb_id < 1 ) {
			return '';
		}
		$url = wp_get_attachment_image_url( $thumb_id, 'thumbnail' );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * One link of the strip.
	 *
	 * @param array{id:int,url:string,thumb:string,cap:string,alt:string,rv:string,src:string,rating:int,author:string,date:string,text:string,verified:bool} $row  Item.
	 * @param string                                                                                                                                         $mode thumb|chip|hidden.
	 * @param int                                                                                                                                            $more Number of the chip.
	 */
	private function anchor( array $row, string $mode, int $more ): string {
		$classes = 'motored-ig__item no-lightbox' . ( 'chip' === $mode ? ' motored-ig__item--chip' : '' );
		$attrs   = ' href="' . esc_url( $row['url'] ) . '" class="' . esc_attr( $classes ) . '" data-motored-g data-mode="' . esc_attr( $mode ) . '" data-src="' . esc_attr( $row['src'] ) . '" target="_blank" rel="noopener" data-cap="' . esc_attr( $row['cap'] ) . '" data-alt="' . esc_attr( $row['alt'] ) . '"'
			. ( $row['rating'] > 0 ? ' data-rating="' . (int) $row['rating'] . '"' : '' )
			. ( '' !== $row['rv'] ? ' data-rv="' . esc_url( $row['rv'] ) . '"' : '' )
			. ( '' !== $row['author'] ? ' data-author="' . esc_attr( $row['author'] ) . '"' : '' )
			. ( '' !== $row['date'] ? ' data-date="' . esc_attr( $row['date'] ) . '"' : '' )
			. ( '' !== $row['text'] ? ' data-review-text="' . esc_attr( $row['text'] ) . '"' : '' )
			. ( $row['verified'] ? ' data-verified="1"' : '' );

		if ( 'hidden' === $mode ) {
			return '<a' . $attrs . ' data-thumb="' . esc_url( $row['thumb'] ) . '" hidden></a>';
		}

		$img = wp_get_attachment_image(
			$row['id'],
			'thumbnail',
			false,
			array(
				'class'    => 'motored-ig__img',
				'alt'      => $row['alt'],
				'loading'  => 'lazy',
				'decoding' => 'async',
				// Thumbnails render at ~100-140 CSS px: with the 300 px square WooCommerce size in srcset, retina
				// screens get a sharp image instead of an upscaled 150 px thumbnail.
				'sizes'    => '(max-width: 600px) 30vw, 140px',
			)
		);
		if ( '' === $img ) {
			return '';
		}
		$overlay = '';
		if ( 'chip' === $mode ) {
			$overlay = '<span class="motored-ig__more">+' . (int) $more . '</span>';
		} elseif ( 'review' === $row['src'] && $row['rating'] > 0 ) {
			$overlay = '<span class="motored-ig__tag motored-ig__tag--star"><span class="motored-ig__star" aria-hidden="true">&#9733;</span> ' . (int) $row['rating'] . '</span>';
		}

		return '<a' . $attrs . '>' . $img . $overlay . '</a>'; // Image markup comes from core (escaped there).
	}

	/**
	 * Plain text (tags stripped, trimmed); falls back to strip_tags() in isolated unit tests.
	 *
	 * @param string $text Text that may contain HTML.
	 */
	private static function plain( string $text ): string {
		if ( function_exists( 'wp_strip_all_tags' ) ) {
			return trim( wp_strip_all_tags( $text ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Fallback without WordPress.
		return trim( strip_tags( $text ) );
	}

	/**
	 * Clean review body preserving paragraph breaks, capped for lightweight HTML attributes.
	 *
	 * @param string $raw Raw comment content.
	 */
	private static function review_text( string $raw ): string {
		$clean = str_replace( array( "\r\n", "\r" ), "\n", $raw );
		if ( function_exists( 'wp_strip_all_tags' ) ) {
			$clean = wp_strip_all_tags( $clean, false );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Fallback without WordPress.
			$clean = strip_tags( $clean );
		}
		$clean = preg_replace( '/[ \t]+/', ' ', $clean );
		$clean = is_string( $clean ) ? preg_replace( '/\n{3,}/', "\n\n", $clean ) : '';
		$clean = is_string( $clean ) ? trim( $clean ) : '';
		if ( '' === $clean ) {
			return '';
		}
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $clean, 'UTF-8' ) > self::MAX_REVIEW_CHARS ) {
				return rtrim( mb_substr( $clean, 0, self::MAX_REVIEW_CHARS - 1, 'UTF-8' ) ) . '…';
			}
			return $clean;
		}
		if ( strlen( $clean ) > self::MAX_REVIEW_CHARS ) {
			return rtrim( substr( $clean, 0, self::MAX_REVIEW_CHARS - 1 ) ) . '...';
		}
		return $clean;
	}

	/**
	 * Asset cache-buster: uses file modification time when available, else plugin version.
	 *
	 * @param string $relative Path relative to plugin root.
	 */
	private static function asset_version( string $relative ): string {
		if ( defined( 'MOTORED_CORE_DIR' ) ) {
			$path = MOTORED_CORE_DIR . $relative;
			if ( is_file( $path ) ) {
				$mtime = filemtime( $path );
				if ( false !== $mtime ) {
					return (string) $mtime;
				}
			}
		}
		return MOTORED_CORE_VERSION;
	}

	/**
	 * The stylesheet + vanilla `defer` script, printed ONLY when a gallery is rendered.
	 */
	private function enqueue(): void {
		if ( function_exists( 'wp_enqueue_style' ) ) {
			wp_enqueue_style(
				self::CSS_HANDLE,
				MOTORED_CORE_URL . 'assets/css/install-gallery.css',
				array(),
				self::asset_version( 'assets/css/install-gallery.css' )
			);
		}
		wp_enqueue_script(
			self::HANDLE,
			MOTORED_CORE_URL . 'assets/js/install-gallery.js',
			array(),
			self::asset_version( 'assets/js/install-gallery.js' ),
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
	}
}
