<?php
/**
 * Admin: "Payment plans" section (switch + editable table + gateway warnings).
 *
 * @package Motored\Core
 */

namespace Motored\Core\Modules\PaymentPlans;

use Motored\Core\Admin\SectionRegistry;
use Motored\Core\Admin\Ui;
use Motored\Core\Integration\MercadoPago\CheckoutPro;
use Motored\Core\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Only instantiated in admin requests; reads the gateway options but never writes them.
 */
final class PaymentPlansAdmin {

	public const SECTION      = 'payment_plans';
	public const NONCE_ACTION = 'motored_payment_plans_save';
	public const NONCE_FIELD  = 'motored_payment_plans_nonce';
	public const POST_KEY     = 'motored_payment_plans_rows';

	/**
	 * Registers the section and the save listener.
	 */
	public function register(): void {
		SectionRegistry::add(
			self::SECTION,
			static fn(): string => __( 'Payment plans', 'motored-core' ),
			array( $this, 'settings' ),
			null,
			40
		);
		// Custom field type rendered by WooCommerce through woocommerce_admin_field_{type} (VERIFY.md).
		add_action( 'woocommerce_admin_field_motored_payment_plans_table', array( $this, 'render_table' ) );
		add_action( 'woocommerce_update_options_motored_' . self::SECTION, array( $this, 'save_plans' ) );
		add_filter( 'motored_status_rows', array( $this, 'status_rows' ) );
	}

	/**
	 * Adds the Mercado Pago integration state to the Status section.
	 *
	 * @param mixed $rows Rows (label => value).
	 * @return mixed
	 */
	public function status_rows( $rows ) {
		if ( is_array( $rows ) ) {
			$rows[ __( 'Mercado Pago integration', 'motored-core' ) ] = self::integration_state();
		}
		return $rows;
	}

	/**
	 * Translated state of the Mercado Pago preference integration (version guard, VERIFY.md #81).
	 */
	public static function integration_state(): string {
		$version = CheckoutPro::installed_version();
		if ( null === $version ) {
			return __( 'Mercado Pago plugin not active', 'motored-core' );
		}
		if ( CheckoutPro::VALIDATED_VERSION === $version ) {
			/* translators: %s: Mercado Pago plugin version */
			return sprintf( __( 'OK (plugin %s)', 'motored-core' ), $version );
		}
		return sprintf(
			/* translators: 1: active Mercado Pago plugin version, 2: validated version */
			__( 'Review integration (active plugin %1$s, validated with %2$s)', 'motored-core' ),
			$version,
			CheckoutPro::VALIDATED_VERSION
		);
	}

	/**
	 * Fields of the section.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function settings(): array {
		$notice = '';
		foreach ( GatewayCheck::warnings( CheckoutPro::settings(), Config::plans()->max_active_installments() ) as $warning ) {
			$notice .= '<div class="notice notice-warning inline"><p>' . esc_html( $warning ) . '</p></div>';
		}

		$version = CheckoutPro::installed_version();
		if ( null !== $version && CheckoutPro::VALIDATED_VERSION !== $version ) {
			$notice .= '<div class="notice notice-warning inline"><p>' . esc_html(
				sprintf(
					/* translators: 1: active Mercado Pago plugin version, 2: validated version */
					__( 'Mercado Pago plugin %1$s is active but the installments limit was validated with %2$s. Review the integration: payments are blocked whenever the limit cannot be applied to the preference.', 'motored-core' ),
					$version,
					CheckoutPro::VALIDATED_VERSION
				)
			) . '</p></div>';
		}

		return array(
			array(
				'title' => __( 'Payment plans', 'motored-core' ),
				'type'  => 'title',
				'desc'  => __( 'The product price (ERP) is the price of the 6 interest-free installments. Each plan adds a percentage to it (negative = discount). Applies to the classic checkout with Mercado Pago Checkout Pro.', 'motored-core' ) . $notice,
				'id'    => 'motored_payment_plans_title',
			),
			array(
				'title'    => __( 'Enable payment plans', 'motored-core' ),
				'desc'     => __( 'Show the installments line on products and let customers choose a plan at checkout.', 'motored-core' ),
				'id'       => 'motored_payment_plans[enabled]',
				'type'     => 'checkbox',
				'default'  => Config::defaults()['enabled'],
				'autoload' => false,
			),
			array(
				// No id on purpose: WooCommerce skips id-less fields on save; save_plans() persists the rows.
				'type' => 'motored_payment_plans_table',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'motored_payment_plans_title',
			),
		);
	}

	/**
	 * Prints the editable table (existing plans + one empty row to add a plan).
	 */
	public function render_table(): void {
		$rows   = array_map( static fn( Plan $plan ): array => $plan->to_array(), array_values( Config::plans()->all() ) );
		$rows[] = array(
			'id'     => '',
			'name'   => '',
			'pct'    => '',
			'max'    => '',
			'type'   => Plan::TYPE_CARD,
			'active' => 'yes',
			'credit' => 'yes',
		);

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, false );

		echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html__( 'Plans', 'motored-core' ) . '</th><td class="forminp">';
		Ui::table_open();
		Ui::table_head(
			array(
				__( 'ID', 'motored-core' )                => 'sm',
				__( 'Name', 'motored-core' )              => 'grow',
				__( 'Adjustment %', 'motored-core' )      => 'sm',
				__( 'Max. installments', 'motored-core' ) => 'sm',
				__( 'Type', 'motored-core' )              => 'lg',
				__( 'Active', 'motored-core' )            => 'xs',
				__( 'Exclude Cuotas sin Tarjeta (consumer_credits)', 'motored-core' ) => 'md',
				__( 'Remove', 'motored-core' )            => 'xs',
			)
		);
		echo '<tbody>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $index => $row ) {
			$name = static fn( string $field ): string => self::POST_KEY . '[' . $index . '][' . $field . ']';
			$new  = '' === $row['id'];
			echo '<tr>';
			printf(
				'<td><input type="text" name="%1$s" value="%2$s" maxlength="20" pattern="[a-z0-9_]+"%3$s></td>',
				esc_attr( $name( 'id' ) ),
				esc_attr( (string) $row['id'] ),
				$new ? '' : ' readonly' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attribute.
			);
			printf( '<td><input type="text" name="%1$s" value="%2$s" ></td>', esc_attr( $name( 'name' ) ), esc_attr( (string) $row['name'] ) );
			printf( '<td><input type="number" name="%1$s" value="%2$s" step="0.01" min="-90" max="100"></td>', esc_attr( $name( 'pct' ) ), esc_attr( (string) $row['pct'] ) );
			printf( '<td><input type="number" name="%1$s" value="%2$s" step="1" min="1" max="%3$d"></td>', esc_attr( $name( 'max' ) ), esc_attr( (string) $row['max'] ), (int) Plan::MAX_INSTALLMENTS );
			printf(
				'<td><select name="%1$s"><option value="card"%2$s>%3$s</option><option value="cash"%4$s>%5$s</option></select></td>',
				esc_attr( $name( 'type' ) ),
				selected( $row['type'], Plan::TYPE_CARD, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core helper.
				esc_html__( 'Card', 'motored-core' ),
				selected( $row['type'], Plan::TYPE_CASH, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core helper.
				esc_html__( 'Cash / transfer', 'motored-core' )
			);
			printf(
				'<td class="motored-cell-center"><input type="checkbox" name="%1$s" value="1"%2$s></td>',
				esc_attr( $name( 'active' ) ),
				checked( 'yes', $row['active'], false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core helper.
			);
			printf(
				'<td class="motored-cell-center"><input type="hidden" name="%1$s" value="0"><input type="checkbox" name="%1$s" value="1"%2$s></td>',
				esc_attr( $name( 'credit' ) ),
				checked( 'yes', $row['credit'] ?? 'yes', false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core helper.
			);
			printf( '<td class="motored-cell-center">%s</td>', $new ? '' : '<input type="checkbox" name="' . esc_attr( $name( 'remove' ) ) . '" value="1">' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			echo '</tr>';
		}

		echo '</tbody>';
		Ui::table_close();
		echo '<p class="description">'
			. esc_html__( 'Percentages: negative = discount, positive = surcharge (never shown to customers). Cash plans always have 1 installment. To add a plan fill the empty row (ID: lowercase letters, numbers and underscores).', 'motored-core' )
			. '</p></td></tr>';
	}

	/**
	 * Saves the table (capability + nonce); rows are sanitized by Plan::from_array().
	 */
	public function save_plans(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified on the next line.
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$posted = isset( $_POST[ self::POST_KEY ] ) && is_array( $_POST[ self::POST_KEY ] )
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field by Plan::from_array().
			? wp_unslash( $_POST[ self::POST_KEY ] )
			: array();

		Options::update( Config::OPTION_KEY, array( 'plans' => self::sanitize_rows( $posted ) ) );
	}

	/**
	 * Turns posted rows into the stored plans list (removed rows, invalid rows and duplicate ids are dropped).
	 *
	 * @param mixed $posted Posted rows.
	 * @return list<array{id:string,name:string,pct:float,max:int,type:string,active:string,credit:string}>
	 */
	public static function sanitize_rows( $posted ): array {
		$kept = array();
		foreach ( is_array( $posted ) ? $posted : array() as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['remove'] ) ) {
				continue;
			}
			$kept[] = $row;
		}
		$plans = new Plans( $kept );
		return array_values( array_map( static fn( Plan $plan ): array => $plan->to_array(), $plans->all() ) );
	}
}
