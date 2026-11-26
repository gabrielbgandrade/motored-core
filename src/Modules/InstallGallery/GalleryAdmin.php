<?php
/**
 * Admin side: settings fields and the "Installation photos" box of the product screen.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\InstallGallery;

use Motored\Core\Admin\ReviewsSection;
use Motored\Core\Admin\Ui;
use Motored\Core\Integration\WooCommerce\AttributeHooks;
use Motored\Core\Support\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Only instantiated in admin requests. The picker is the native media modal (`wp.media`), the caption and the
 * alt text of each photo are the native Media Library fields (put the motorcycle in the caption).
 */
final class GalleryAdmin {

	public const NONCE_FIELD       = 'motored_ig_nonce';
	public const NONCE_ACTION      = 'motored_ig_box';
	public const FIELD             = 'motored_igallery';
	public const HANDLE            = 'motored-install-gallery-admin';
	public const ACTION_SYNC       = 'motored_ig_sync_reviews';
	public const SYNC_NONCE_ACTION = 'motored_ig_sync';
	public const SYNC_NONCE_FIELD  = 'motored_ig_sync_nonce';
	private const NOTICE_BUCKET    = 'ig_admin';

	/**
	 * Registers admin hooks.
	 */
	public function register(): void {
		ReviewsSection::ensure();
		add_filter( 'motored_reviews_section_fields', array( $this, 'add_fields' ), 20 );
		add_action( 'woocommerce_admin_field_motored_install_gallery_tools', array( $this, 'render_tools' ) );
		add_action( 'admin_post_' . self::ACTION_SYNC, array( $this, 'handle_sync_reviews' ) );
		AttributeHooks::register_product_box_hooks( array( $this, 'add_meta_box' ), array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 20 );
	}

	/**
	 * Adds this module's fields to the shared Reviews section.
	 *
	 * @param mixed $fields Fields of other modules.
	 * @return array<int,array<string,mixed>>
	 */
	public function add_fields( $fields ): array {
		return array_merge( is_array( $fields ) ? $fields : array(), $this->settings() );
	}

	/**
	 * Fields of the Reviews section.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function settings(): array {
		return array(
			array(
				'title' => __( 'Photo gallery', 'motored-core' ),
				'type'  => 'title',
				'desc'  => __( 'A second gallery on the product page: installation photos you choose plus the photos of approved reviews.', 'motored-core' ),
				'id'    => 'motored_install_gallery_title',
			),
			array(
				'title'    => __( 'Show the gallery', 'motored-core' ),
				'desc'     => __( 'Print the gallery on product pages when the product has photos.', 'motored-core' ),
				'id'       => 'motored_install_gallery[enabled]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['enabled'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Include review photos', 'motored-core' ),
				'desc'     => __( 'Add the photos of approved reviews to the gallery.', 'motored-core' ),
				'id'       => 'motored_install_gallery[include_reviews]',
				'type'     => 'checkbox',
				'default'  => Config::DEFAULTS['include_reviews'],
				'autoload' => false,
			),
			array(
				'title'    => __( 'Placement on product page', 'motored-core' ),
				'desc'     => __( 'Where the gallery appears in classic WooCommerce templates. You can also place [motored_install_gallery] manually anywhere.', 'motored-core' ),
				'desc_tip' => true,
				'id'       => 'motored_install_gallery[placement]',
				'type'     => 'select',
				'default'  => Config::DEFAULTS['placement'],
				'options'  => array(
					Config::PLACE_BELOW_GALLERY     => __( 'Below product gallery', 'motored-core' ),
					Config::PLACE_AFTER_ADD_TO_CART => __( 'Below Add to Cart button', 'motored-core' ),
					Config::PLACE_BEFORE_TABS       => __( 'Below product summary (above tabs)', 'motored-core' ),
					Config::PLACE_AFTER_TABS        => __( 'Below product tabs', 'motored-core' ),
					Config::PLACE_TAB               => __( 'As a product tab', 'motored-core' ),
					Config::PLACE_SHORTCODE         => __( 'Manual placement only ([motored_install_gallery])', 'motored-core' ),
				),
				'autoload' => false,
			),
			array(
				'title'             => __( 'Thumbnails in the strip', 'motored-core' ),
				'desc_tip'          => __( 'The rest of the photos open from the "+N" thumbnail. Fewer thumbnails means a lighter page.', 'motored-core' ),
				'id'                => 'motored_install_gallery[visible]',
				'type'              => 'number',
				'default'           => Config::DEFAULTS['visible'],
				'custom_attributes' => array(
					'min'  => '4',
					'max'  => '12',
					'step' => '1',
				),
				'autoload'          => false,
			),
			// No id on purpose: WooCommerce skips id-less fields when saving.
			array( 'type' => 'motored_install_gallery_tools' ),
			array(
				'type' => 'sectionend',
				'id'   => 'motored_install_gallery_title',
			),
		);
	}

	/**
	 * Renders the tools row inside WooCommerce > Settings > Motored > Reviews.
	 */
	public function render_tools(): void {
		$notice = $this->pull_notice();
		echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html__( 'Gallery tools', 'motored-core' ) . '</th><td class="forminp"><div class="motored-box">';
		if ( null !== $notice ) {
			printf(
				'<div class="notice notice-%1$s inline"><p>%2$s</p></div>',
				esc_attr( $notice['ok'] ? 'success' : 'error' ),
				esc_html( $notice['text'] )
			);
		}
		wp_nonce_field( self::SYNC_NONCE_ACTION, self::SYNC_NONCE_FIELD, false );
		printf(
			'<p class="motored-actions"><button type="submit" class="button" name="action" value="%1$s" formmethod="post" formaction="%2$s">%3$s</button></p>',
			esc_attr( self::ACTION_SYNC ),
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_html__( 'Sync existing review photos', 'motored-core' )
		);
		echo '<p class="description">' . esc_html__( 'Rebuilds the gallery index from already-approved product reviews that have photos. For block themes or custom product builders, insert shortcode [motored_install_gallery].', 'motored-core' ) . '</p>';
		echo '</div></td></tr>';
	}

	/**
	 * Rebuilds the derived review photo index across all products (admin-post, nonce + manage_woocommerce).
	 */
	public function handle_sync_reviews(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'motored-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::SYNC_NONCE_ACTION, self::SYNC_NONCE_FIELD );
		try {
			$count = GalleryStore::rebuild_all();
		} catch ( \RuntimeException $exception ) {
			\Motored\Core\Support\Logger::error( 'Gallery rebuild failed.' );
			wp_die(
				esc_html__( 'Action could not be completed.', 'motored-core' ),
				'',
				array(
					'response'  => 500,
					'back_link' => true,
				)
			);
		}
		Cache::set(
			'notice:' . get_current_user_id(),
			array(
				'ok'   => true,
				'text' => sprintf(
					/* translators: %d: number of products rebuilt. */
					__( 'Synced review photos for %d products.', 'motored-core' ),
					$count
				),
			),
			120,
			self::NOTICE_BUCKET
		);
		wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=motored&section=' . ReviewsSection::ID ) );
		exit;
	}

	/**
	 * Adds the meta box in the side column of the product screen.
	 */
	public function add_meta_box(): void {
		add_meta_box( 'motored-install-gallery', __( 'Installation photos', 'motored-core' ), array( $this, 'render' ), 'product', 'side', 'low' );
	}

	/**
	 * Renders the box.
	 *
	 * @param mixed $post Current post.
	 */
	public function render( $post ): void {
		$pid           = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
		$ids           = $pid > 0 ? GalleryStore::install_ids( $pid ) : array();
		$reviews_count = $pid > 0 ? count( GalleryStore::review_entries( $pid ) ) : 0;
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		Ui::box_open( true );
		printf(
			'<div id="motored-ig" data-max="%1$d" data-title="%2$s" data-btn="%3$s" data-rm="%4$s" data-prev="%5$s" data-next="%6$s" data-cap-ph="%7$s" data-cap-lbl="%8$s">',
			(int) GalleryItems::MAX_INSTALL,
			esc_attr__( 'Installation photos', 'motored-core' ),
			esc_attr__( 'Add to the gallery', 'motored-core' ),
			esc_attr__( 'Remove photo', 'motored-core' ),
			esc_attr__( 'Move left', 'motored-core' ),
			esc_attr__( 'Move right', 'motored-core' ),
			esc_attr__( 'Caption (e.g. motorcycle)', 'motored-core' ),
			esc_attr__( 'Photo caption', 'motored-core' )
		);
		echo '<input type="hidden" name="' . esc_attr( self::FIELD ) . '[present]" value="1" />';
		echo '<input type="hidden" name="' . esc_attr( self::FIELD ) . '[ids]" value="' . esc_attr( implode( ',', $ids ) ) . '" />';
		echo '<ul class="motored-box__thumbs">';
		$cap_ph  = __( 'Caption (e.g. motorcycle)', 'motored-core' );
		$cap_lbl = __( 'Photo caption', 'motored-core' );
		foreach ( $ids as $id ) {
			$caption = wp_get_attachment_caption( $id );
			$cap_str = is_string( $caption ) ? trim( $caption ) : '';
			echo '<li data-id="' . (int) $id . '">';
			echo wp_get_attachment_image( $id, 'thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core markup.
			echo '<input type="text" class="motored-box__cap-input" name="' . esc_attr( self::FIELD ) . '[captions][' . (int) $id . ']" value="' . esc_attr( $cap_str ) . '" placeholder="' . esc_attr( $cap_ph ) . '" maxlength="160" aria-label="' . esc_attr( $cap_lbl ) . '" />';
			echo '<span class="motored-box__controls">';
			echo '<button type="button" class="button button-small" data-prev="1" aria-label="' . esc_attr__( 'Move left', 'motored-core' ) . '">&larr;</button>';
			echo '<button type="button" class="button button-small" data-next="1" aria-label="' . esc_attr__( 'Move right', 'motored-core' ) . '">&rarr;</button>';
			echo '<button type="button" class="button button-small button-link-delete" data-rm="1" aria-label="' . esc_attr__( 'Remove photo', 'motored-core' ) . '">&times;</button>';
			echo '</span></li>';
		}
		echo '</ul>';
		echo '<p class="motored-actions"><button type="button" class="button" data-add="1">' . esc_html__( 'Add photos', 'motored-core' ) . '</button> <span class="motored-box__meta" data-count="1">' . (int) count( $ids ) . ' / ' . (int) GalleryItems::MAX_INSTALL . '</span></p>';
		if ( $pid > 0 ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %d: number of linked customer review photos. */
					__( 'Linked approved review photos: %d.', 'motored-core' ),
					$reviews_count
				)
			) . '</p>';
		}
		/* translators: %d: maximum number of photos. */
		echo '<p class="description">' . esc_html( sprintf( __( 'Photos of tires fitted on motorcycles (up to %d). Write the motorcycle in the caption of each photo in the Media Library. Photos of approved reviews join automatically.', 'motored-core' ), GalleryItems::MAX_INSTALL ) ) . '</p>';
		echo '</div>';
		Ui::box_close();
	}

	/**
	 * Saves the box through the WooCommerce product CRUD (HPOS safe).
	 *
	 * @param mixed $product WC_Product being saved.
	 */
	public function save( $product ): void {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified right below.
		$posted = isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : null;
		if ( null === $posted || empty( $posted['present'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified right below.
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) && is_string( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) || ! current_user_can( 'edit_post', (int) $product->get_id() ) ) {
			return;
		}
		$ids = array();
		foreach ( GalleryItems::parse_csv( $posted['ids'] ?? '' ) as $id ) {
			if ( 'attachment' === get_post_type( $id ) && wp_attachment_is_image( $id ) && ( current_user_can( 'edit_post', $id ) || current_user_can( 'manage_woocommerce' ) ) ) {
				$ids[] = $id;
			}
		}
		if ( array() === $ids ) {
			$product->delete_meta_data( GalleryStore::INSTALL_META );
		} else {
			$product->update_meta_data( GalleryStore::INSTALL_META, $ids );
			$captions = isset( $posted['captions'] ) && is_array( $posted['captions'] ) ? $posted['captions'] : array();
			if ( array() !== $captions && function_exists( 'wp_update_post' ) ) {
				foreach ( $ids as $id ) {
					if ( ! array_key_exists( $id, $captions ) || ! is_scalar( $captions[ $id ] ) ) {
						continue;
					}
					if ( ! current_user_can( 'edit_post', $id ) ) {
						continue;
					}
					$new_cap = trim( sanitize_text_field( (string) $captions[ $id ] ) );
					if ( function_exists( 'mb_substr' ) ) {
						$new_cap = mb_substr( $new_cap, 0, 160, 'UTF-8' );
					} else {
						$new_cap = substr( $new_cap, 0, 160 );
					}
					$cur_cap = wp_get_attachment_caption( $id );
					$cur_cap = is_string( $cur_cap ) ? trim( $cur_cap ) : '';
					if ( $new_cap !== $cur_cap ) {
						wp_update_post(
							array(
								'ID'           => $id,
								'post_excerpt' => $new_cap,
							)
						);
					}
				}
			}
		}
		GalleryStore::rebuild( (int) $product->get_id() );
	}

	/**
	 * The vanilla script of the box, on the product edit screen only.
	 */
	public function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! is_object( $screen ) ) {
			return;
		}
		// Carrega na tela clássica de edição de produto (product/post) e também quando o post sendo
		// editado é um produto (cobre editor de blocos e outras telas de administração de produto).
		$is_product_screen = 'product' === $screen->id && 'post' === $screen->base;
		$post_id           = isset( $_GET['post'] ) && is_scalar( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		$is_block_product  = $post_id > 0 && 'product' === get_post_type( $post_id );
		if ( ! $is_product_screen && ! $is_block_product ) {
			return;
		}
		// Carrega o media do WordPress (inclui media-editor, media-views, wp.media) antes do nosso script.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only post id check for media modal attachment context.
		wp_enqueue_media( $post_id > 0 ? array( 'post' => $post_id ) : array() );
		$script_ver = MOTORED_CORE_VERSION;
		if ( defined( 'MOTORED_CORE_DIR' ) ) {
			$script_path = MOTORED_CORE_DIR . 'assets/admin/motored-install-gallery.js';
			if ( is_file( $script_path ) ) {
				$mtime = filemtime( $script_path );
				if ( false !== $mtime ) {
					$script_ver = (string) $mtime;
				}
			}
		}
		wp_enqueue_script(
			self::HANDLE,
			MOTORED_CORE_URL . 'assets/admin/motored-install-gallery.js',
			array( 'media-editor', 'media-views' ),
			$script_ver,
			true
		);
		// Passa dados para o script de forma confiável (post_id para contexto do media modal).
		wp_localize_script(
			self::HANDLE,
			'motoredIG',
			array(
				'postId' => (int) $post_id,
				'max'    => (int) GalleryItems::MAX_INSTALL,
			)
		);
	}

	/**
	 * Reads and clears the pending admin notice for the current user.
	 *
	 * @return array{ok:bool,text:string}|null
	 */
	private function pull_notice(): ?array {
		$key    = 'notice:' . get_current_user_id();
		$found  = false;
		$notice = Cache::get( $key, self::NOTICE_BUCKET, $found );
		if ( ! $found || ! is_array( $notice ) ) {
			return null;
		}
		Cache::delete( $key, self::NOTICE_BUCKET );
		return array(
			'ok'   => ! empty( $notice['ok'] ),
			'text' => isset( $notice['text'] ) && is_string( $notice['text'] ) ? $notice['text'] : '',
		);
	}
}
