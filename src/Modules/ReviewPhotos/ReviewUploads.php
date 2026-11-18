<?php
/**
 * Handles the photos sent with a product review.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\ReviewPhotos;

use Motored\Core\Integration\WooCommerce\ReviewHooks;
use Motored\Core\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Hooked on comment_post. The comment is already saved here: nothing in this class may lose it,
 * so invalid photos are skipped and every failure is contained.
 */
final class ReviewUploads {

	public const FIELD = 'motored_review_photos';

	/**
	 * Constructor.
	 *
	 * @param \Closure|null $inspect  fn( string $tmp, string $name ): ?string extension (tests).
	 * @param \Closure|null $process  fn( string $tmp, string $ext, int $product_id ): int attachment id (tests).
	 * @param \Closure|null $uploaded fn( string $tmp ): bool, is_uploaded_file (tests).
	 * @param \Closure|null $clock    Returns unix time (tests).
	 */
	public function __construct(
		private ?\Closure $inspect = null,
		private ?\Closure $process = null,
		private ?\Closure $uploaded = null,
		private ?\Closure $clock = null
	) {
	}

	/**
	 * Callback of comment_post.
	 *
	 * @param mixed $comment_id Comment id.
	 * @param mixed $approved   1, 0 or 'spam'.
	 * @param mixed $files      $_FILES entry (null = read the superglobal).
	 * @param mixed $ip         Remote address (null = read the server variable).
	 */
	public function handle( $comment_id, $approved, $files = null, $ip = null ): void {
		if ( ! in_array( $approved, array( 0, 1, '0', '1' ), true ) ) {
			return; // Spam (or anything unexpected): never store photos.
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Public comment form; validated below.
		$files = null === $files ? ( $_FILES[ self::FIELD ] ?? null ) : $files;
		if ( ! is_array( $files ) || ! Config::enabled() ) {
			return;
		}
		try {
			$this->store( (int) $comment_id, FileRules::normalize( $files ), null === $ip ? self::remote_ip() : (string) $ip );
		} catch ( \Throwable $e ) {
			Logger::error( 'Review photos failed.', array( 'exception' => get_class( $e ) ) );
		}
	}

	/**
	 * Validates and stores the photos of a review.
	 *
	 * @param int                                                         $comment_id Comment id.
	 * @param list<array{name:string,tmp_name:string,size:int,error:int}> $files      Normalized files.
	 * @param string                                                      $ip         Remote address.
	 */
	private function store( int $comment_id, array $files, string $ip ): void {
		if ( array() === $files ) {
			return;
		}
		$comment = get_comment( $comment_id );
		if ( ! is_object( $comment ) || ! ReviewHooks::is_review( $comment ) ) {
			return;
		}

		$now       = null !== $this->clock ? ( $this->clock )() : time();
		$selection = FileRules::select( $files, Config::max_photos(), Config::max_bytes(), UploadLimiter::remaining( $ip, $now ) );
		UploadLimiter::record( $ip, $now, count( $selection['accepted'] ) );

		$inspect  = $this->inspect ?? fn( string $tmp, string $name ): ?string => ( new PhotoProcessor() )->inspect( $tmp, $name );
		$process  = $this->process ?? fn( string $tmp, string $ext, int $product ): int => ( new PhotoProcessor() )->process( $tmp, $ext, $product );
		$uploaded = $this->uploaded ?? static fn( string $tmp ): bool => is_uploaded_file( $tmp );

		$ids = array();
		foreach ( $selection['accepted'] as $file ) {
			$ext = $uploaded( $file['tmp_name'] ) ? $inspect( $file['tmp_name'], $file['name'] ) : null;
			$id  = null === $ext ? 0 : (int) $process( $file['tmp_name'], $ext, (int) $comment->comment_post_ID );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		PhotoMeta::save( $comment_id, $ids );

		Logger::info(
			'Review photos processed.',
			array(
				'comment_id' => $comment_id,
				'saved'      => count( $ids ),
				'skipped'    => count( $files ) - count( $ids ),
			)
		);
	}

	/**
	 * Remote address of the request.
	 */
	private static function remote_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}
}
