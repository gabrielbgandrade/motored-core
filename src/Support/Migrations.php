<?php
/**
 * Versioned schema migrations.
 *
 * @package Motored\Core
 */

namespace Motored\Core\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Runs ordered migrations. Only ever invoked on plugin activation and admin_init,
 * never on frontend requests.
 *
 * Each migration has an id of the form YYYYMMDDNN (10 digits, strictly increasing
 * over time) and an up() callable returning true/null on success or false on failure.
 * The highest applied id is stored in option "motored_schema_version" (autoload=false).
 * up() callables must be idempotent so failed upgrades can be retried.
 */
final class Migrations {

	public const OPTION = 'motored_schema_version';
	public const LOCK   = 'motored_schema_lock';

	/**
	 * Migrations keyed by id, sorted ascending.
	 *
	 * @var array<string,callable>
	 */
	private array $migrations = array();

	/**
	 * Constructor.
	 *
	 * @param array<int|string,callable> $migrations Map of id => up callable (numeric ids become int keys in PHP).
	 * @throws \InvalidArgumentException On a malformed id.
	 */
	public function __construct( array $migrations = array() ) {
		foreach ( $migrations as $id => $up ) {
			$id = (string) $id;
			if ( 1 !== preg_match( '/^\d{10}$/', $id ) ) {
				throw new \InvalidArgumentException( 'Migration id must be YYYYMMDDNN.' );
			}
			$this->migrations[ $id ] = $up;
		}
		ksort( $this->migrations, SORT_STRING );
	}

	/**
	 * Ids of migrations still pending, in execution order.
	 *
	 * @param string $current Highest applied id ('' when none).
	 * @return list<string>
	 */
	public function pending( string $current ): array {
		$pending = array();
		foreach ( array_keys( $this->migrations ) as $id ) {
			if ( strcmp( (string) $id, $current ) > 0 ) {
				$pending[] = (string) $id;
			}
		}
		return $pending;
	}

	/**
	 * Applies pending migrations in order, stopping at the first failure.
	 *
	 * Cheap no-op (no I/O) when there are no migrations registered.
	 *
	 * @return int Number of migrations applied.
	 */
	public function run(): int {
		if ( array() === $this->migrations ) {
			return 0;
		}
		$current = (string) get_option( self::OPTION, '' );
		$pending = $this->pending( $current );
		if ( array() === $pending ) {
			return 0;
		}
		if ( ! Db::acquire_option_lock( self::LOCK ) ) {
			return 0;
		}

		$applied = 0;
		try {
			wp_cache_delete( self::OPTION, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			foreach ( $this->pending( (string) get_option( self::OPTION, '' ) ) as $id ) {
				try {
					$ok = ( $this->migrations[ $id ] )();
				} catch ( \Throwable $e ) {
					Logger::error(
						'Migration threw an exception.',
						array(
							'migration' => $id,
							'exception' => get_class( $e ),
						)
					);
					break;
				}
				if ( false === $ok ) {
					Logger::error( 'Migration failed.', array( 'migration' => $id ) );
					break;
				}
				if ( ! update_option( self::OPTION, $id, false ) ) {
					Logger::error( 'Could not persist migration version.', array( 'migration' => $id ) );
					break;
				}
				++$applied;
			}
		} finally {
			delete_option( self::LOCK );
		}
		return $applied;
	}
}
