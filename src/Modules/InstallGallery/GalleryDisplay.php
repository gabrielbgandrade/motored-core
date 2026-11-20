<?php
/**
 * Product page output of the gallery.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\InstallGallery;

defined( 'ABSPATH' ) || exit;

/**
 * A strip of small thumbnails plus a "+N" chip; the full images open in a native <dialog> (see install-gallery.js).
 * Weight rules: only the strip thumbnails are images (lazy, `thumbnail` size); the rest of the photos are plain links
 * and the dialog loads the `large` size (never the original) one photo at a time. No theme dependency.
 */
final class GalleryDisplay {

	public const HANDLE     = 'motored-install-gallery';
	public const CSS_HANDLE = 'motored-install-gallery-css';

	/**
	 * Product ids whose gallery was already printed on this request (never twice per product).
	 *
	 * @var array<int,bool>
	 */
	private array $done_ids = array();

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
	 * Backwards-compatible entrypoint: renders at the active action placement (before_tabs default).
	 */
	public function render(): void {
		$this->render_before_tabs();
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
			return $this->html( $product_id, true ) . $html;
		}
		if ( Config::PLACE_AFTER_TABS === $placement ) {
			return $html . $this->html( $product_id, true );
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
				echo $this->html( $product_id, false );
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
		return $this->html( $explicit, true );
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
		echo $this->html( $product_id, true );
	}

	/**
	 * Markup of the gallery ('' when the product has no photos).
	 * Reads the product meta first (already primed with the product: 0 queries), the option second.
	 *
	 * @param int  $explicit_id  Optional explicit product ID.
	 * @param bool $with_heading Print outer section heading (`h2`).
	 */
	private function html( int $explicit_id = 0, bool $with_heading = true ): string {
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

		$head = '';
		if ( $with_heading || ( $install_cnt > 0 && $review_cnt > 0 ) ) {
			$head .= '<div class="motored-ig__head">';
			if ( $with_heading ) {
				$head .= '<h2 class="motored-ig__title">' . esc_html__( 'Customer and installation photos', 'motored-core' ) . '</h2>';
			}
			if ( $install_cnt > 0 && $review_cnt > 0 ) {
				$head .= '<div class="motored-ig__filters" role="group" aria-label="' . esc_attr__( 'Filter photos', 'motored-core' ) . '">';
				/* translators: %d: total photo count. */
				$head .= '<button type="button" class="motored-ig__filter is-active" aria-pressed="true" data-ig-filter="all">' . esc_html( sprintf( __( 'All (%d)', 'motored-core' ), count( $rows ) ) ) . '</button>';
				/* translators: %d: installation photo count. */
				$head .= '<button type="button" class="motored-ig__filter" aria-pressed="false" data-ig-filter="install">' . esc_html( sprintf( __( 'Installations (%d)', 'motored-core' ), $install_cnt ) ) . '</button>';
				/* translators: %d: customer review photo count. */
				$head .= '<button type="button" class="motored-ig__filter" aria-pressed="false" data-ig-filter="review">' . esc_html( sprintf( __( 'Reviews (%d)', 'motored-core' ), $review_cnt ) ) . '</button>';
				$head .= '</div>';
			}
			$head .= '</div>';
		}

		$html  = '<section class="motored-install-gallery" aria-label="' . esc_attr__( 'Customer and installation photos', 'motored-core' ) . '" data-title="' . esc_attr__( 'Customer and installation photos', 'motored-core' ) . '" data-close="' . esc_attr__( 'Close', 'motored-core' ) . '" data-prev="' . esc_attr__( 'Previous photo', 'motored-core' ) . '" data-next="' . esc_attr__( 'Next photo', 'motored-core' ) . '" data-rv="' . esc_attr__( 'View review', 'motored-core' ) . '">';
		$html .= $head;
		$html .= '<div class="motored-ig__grid">' . $anchors . '</div></section>';
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
	 * Resolves URLs and captions; attachments that no longer exist are skipped.
	 *
	 * @param array<int,array{id:int,c:int,r:int}> $entries    Items.
	 * @param int                                  $product_id Product id.
	 * @return list<array{id:int,url:string,thumb:string,cap:string,alt:string,rv:string,src:string,rating:int}>
	 */
	private function rows( array $entries, int $product_id ): array {
		$permalink = '';
		$rows      = array();
		$name      = self::plain( (string) get_the_title( $product_id ) );
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
			if ( $is_review ) {
				$permalink = '' === $permalink ? (string) get_permalink( $product_id ) : $permalink;
				$review    = $permalink . '#comment-' . $entry['c'];
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
				'id'     => $entry['id'],
				'url'    => $url,
				'thumb'  => is_string( $thumb_url ) ? $thumb_url : $url,
				'cap'    => $caption,
				'alt'    => $alt,
				'rv'     => $review,
				'src'    => $is_review ? 'review' : 'install',
				'rating' => (int) $entry['r'],
			);
		}
		return $rows;
	}

	/**
	 * One link of the strip.
	 *
	 * @param array{id:int,url:string,thumb:string,cap:string,alt:string,rv:string,src:string,rating:int} $row  Item.
	 * @param string                                                                                      $mode thumb|chip|hidden.
	 * @param int                                                                                         $more Number of the chip.
	 */
	private function anchor( array $row, string $mode, int $more ): string {
		$classes = 'motored-ig__item no-lightbox' . ( 'chip' === $mode ? ' motored-ig__item--chip' : '' );
		$attrs   = ' href="' . esc_url( $row['url'] ) . '" class="' . esc_attr( $classes ) . '" data-motored-g data-mode="' . esc_attr( $mode ) . '" data-src="' . esc_attr( $row['src'] ) . '" target="_blank" rel="noopener" data-cap="' . esc_attr( $row['cap'] ) . '" data-alt="' . esc_attr( $row['alt'] ) . '"'
			. ( $row['rating'] > 0 ? ' data-rating="' . (int) $row['rating'] . '"' : '' )
			. ( '' !== $row['rv'] ? ' data-rv="' . esc_url( $row['rv'] ) . '"' : '' );

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
		} elseif ( '' !== $row['cap'] && 'install' === $row['src'] ) {
			$overlay = '<span class="motored-ig__tag">' . esc_html( $row['cap'] ) . '</span>';
		} elseif ( $row['rating'] > 0 ) {
			$overlay = '<span class="motored-ig__tag">&#9733; ' . (int) $row['rating'] . '</span>';
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
	 * The stylesheet + vanilla `defer` script (< 3 KB), printed ONLY when a gallery is rendered.
	 */
	private function enqueue(): void {
		if ( function_exists( 'wp_enqueue_style' ) ) {
			wp_enqueue_style(
				self::CSS_HANDLE,
				MOTORED_CORE_URL . 'assets/css/install-gallery.css',
				array(),
				MOTORED_CORE_VERSION
			);
		}
		wp_enqueue_script(
			self::HANDLE,
			MOTORED_CORE_URL . 'assets/js/install-gallery.js',
			array(),
			MOTORED_CORE_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
	}
}
