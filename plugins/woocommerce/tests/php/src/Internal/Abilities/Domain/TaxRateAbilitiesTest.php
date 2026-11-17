<?php
/**
 * TaxRateAbilitiesTest class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Abilities\Domain;

use Automattic\WooCommerce\Internal\Abilities\AbilitiesLoader;

/**
 * Tests for the tax-rate-create, tax-rate-update and tax-rate-delete abilities.
 */
class TaxRateAbilitiesTest extends \WC_Unit_Test_Case {

	/**
	 * Administrator used as the current user.
	 *
	 * @var int
	 */
	private static $administrator_id;

	/**
	 * Init action count restored in tearDown.
	 *
	 * @var int|null
	 */
	private $original_init_count;

	/**
	 * Create the administrator once for the class.
	 *
	 * @param \WP_UnitTest_Factory $factory WordPress unit test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		self::$administrator_id = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Start each test from an empty abilities registry and no tax rates.
	 */
	public function setUp(): void {
		global $wp_actions, $wpdb;

		if ( ! function_exists( 'wp_register_ability' ) ) {
			require_once WC_ABSPATH . 'vendor/wordpress/abilities-api/includes/bootstrap.php';
		}

		// The Abilities API registry refuses to initialize before `init` has fired.
		$this->original_init_count = $wp_actions['init'] ?? null;
		$wp_actions['init']        = max( 1, (int) ( $wp_actions['init'] ?? 0 ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		parent::setUp();
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_tax_rate_locations" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_tax_rates" );

		wc_get_container()->get( AbilitiesLoader::class )->init();
		self::reset_registries();
		wp_set_current_user( self::$administrator_id );
	}

	/**
	 * Leave the registries and the init count as the next test expects them.
	 */
	public function tearDown(): void {
		global $wp_actions;

		try {
			self::reset_registries();
		} finally {
			parent::tearDown();
		}

		if ( null !== $this->original_init_count ) {
			$wp_actions['init'] = $this->original_init_count; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		} else {
			unset( $wp_actions['init'] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	/**
	 * Run an ability as the current user, with its permission check.
	 *
	 * @param string $name  Ability name.
	 * @param array  $input Ability input.
	 * @return mixed|\WP_Error
	 */
	private function run_ability( string $name, array $input = array() ) {
		$ability = wp_get_ability( $name );
		$this->assertNotNull( $ability, "Ability {$name} should be registered." );

		return $ability->execute( $input );
	}

	/**
	 * Drop every registered ability, so the next read registers them again.
	 */
	private static function reset_registries(): void {
		global $wp_actions;

		foreach ( array( 'WP_Abilities_Registry', 'WP_Ability_Categories_Registry' ) as $registry ) {
			if ( class_exists( $registry ) ) {
				$instance = new \ReflectionProperty( $registry, 'instance' );
				$instance->setAccessible( true );
				$instance->setValue( null, null );
			}
		}
		unset( $wp_actions['wp_abilities_api_init'], $wp_actions['wp_abilities_api_categories_init'] );
	}

	/**
	 * Create a rate through the ability.
	 *
	 * @param array $input Ability input.
	 * @return array The created rate.
	 */
	private function create_rate( array $input ): array {
		$result = $this->run_ability( 'woocommerce/tax-rate-create', $input );
		$this->assertNotWPError( $result );

		return $result['rate'];
	}

	/**
	 * @testdox Should create a rate that tax-rates-query returns as the create returned it.
	 */
	public function test_create_reads_back_through_the_query(): void {
		$rate = $this->create_rate(
			array(
				'rate'      => '7',
				'country'   => 'de',
				'state'     => '',
				'postcodes' => array( '10115', '10117' ),
				'cities'    => array( 'Berlin' ),
				'name'      => 'MwSt reduced',
				'priority'  => 2,
				'compound'  => true,
				'shipping'  => false,
				'class'     => 'reduced-rate',
			)
		);

		$this->assertSame(
			array(
				'id'        => $rate['id'],
				'country'   => 'DE',
				'state'     => '',
				'postcodes' => array( '10115', '10117' ),
				'cities'    => array( 'BERLIN' ),
				'rate'      => '7.0000',
				'name'      => 'MwSt reduced',
				'priority'  => 2,
				'compound'  => true,
				'shipping'  => false,
				'class'     => 'reduced-rate',
			),
			$rate,
			'The create should return the stored rate.'
		);

		$query = $this->run_ability( 'woocommerce/tax-rates-query', array( 'include' => array( $rate['id'] ) ) );
		$this->assertSame( array( $rate ), $query['rates'], 'tax-rates-query should return the rate as the create did.' );
	}

	/**
	 * @testdox Should give a created rate the defaults of the tax settings screen and place it after the rates of its class.
	 */
	public function test_create_applies_defaults_and_appends(): void {
		$first  = $this->create_rate( array( 'rate' => '19' ) );
		$second = $this->create_rate( array( 'rate' => '20' ) );

		$this->assertSame( 'standard', $second['class'] );
		$this->assertSame( 1, $second['priority'] );
		$this->assertFalse( $second['compound'] );
		$this->assertTrue( $second['shipping'] );
		$this->assertSame( '', $second['country'] );
		$this->assertSame( 'Tax', $second['name'] );

		$query = $this->run_ability( 'woocommerce/tax-rates-query' );
		$this->assertSame( array( $first['id'], $second['id'] ), array_column( $query['rates'], 'id' ) );
	}

	/**
	 * @testdox Should change only the sent fields of a rate.
	 */
	public function test_partial_update_leaves_other_fields(): void {
		$rate = $this->create_rate(
			array(
				'rate'      => '19',
				'country'   => 'DE',
				'postcodes' => array( '10115' ),
				'name'      => 'MwSt',
				'compound'  => true,
			)
		);

		$result = $this->run_ability(
			'woocommerce/tax-rate-update',
			array(
				'id'   => $rate['id'],
				'rate' => '16',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( array_merge( $rate, array( 'rate' => '16.0000' ) ), $result['rate'] );
	}

	/**
	 * @testdox Should clear the postcodes of a rate when an empty list is sent, and keep its cities.
	 */
	public function test_update_clears_postcodes(): void {
		$rate = $this->create_rate(
			array(
				'rate'      => '19',
				'postcodes' => array( '10115', '10117' ),
				'cities'    => array( 'BERLIN' ),
			)
		);

		$result = $this->run_ability(
			'woocommerce/tax-rate-update',
			array(
				'id'        => $rate['id'],
				'postcodes' => array(),
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( array(), $result['rate']['postcodes'] );
		$this->assertSame( array( 'BERLIN' ), $result['rate']['cities'] );
	}

	/**
	 * @testdox Should return the rate unchanged, without a write, when only the id is sent.
	 */
	public function test_update_with_only_id_returns_the_rate(): void {
		$rate    = $this->create_rate( array( 'rate' => '19' ) );
		$updated = 0;
		add_action(
			'woocommerce_tax_rate_updated',
			static function () use ( &$updated ) {
				++$updated;
			}
		);

		$result = $this->run_ability( 'woocommerce/tax-rate-update', array( 'id' => $rate['id'] ) );

		$this->assertSame( array( 'rate' => $rate ), $result );
		$this->assertSame( 0, $updated, 'No write should happen.' );
	}

	/**
	 * @testdox Should refuse a tax class that does not exist, on create and on update.
	 */
	public function test_unknown_class_is_refused(): void {
		$create = $this->run_ability(
			'woocommerce/tax-rate-create',
			array(
				'rate'  => '19',
				'class' => 'no-such-class',
			)
		);

		$this->assertWPError( $create );
		$this->assertSame( 'woocommerce_ability_tax_rate_class_not_found', $create->get_error_code() );
		$this->assertSame( 400, $create->get_error_data()['status'] );
		$this->assertSame( 0, $this->run_ability( 'woocommerce/tax-rates-query' )['total'], 'No rate should be created.' );

		$rate   = $this->create_rate( array( 'rate' => '19' ) );
		$update = $this->run_ability(
			'woocommerce/tax-rate-update',
			array(
				'id'    => $rate['id'],
				'rate'  => '7',
				'class' => 'no-such-class',
			)
		);

		$this->assertWPError( $update );
		$this->assertSame( 'woocommerce_ability_tax_rate_class_not_found', $update->get_error_code() );
		$this->assertSame( '19.0000', $this->run_ability( 'woocommerce/tax-rates-query' )['rates'][0]['rate'], 'The rate should not change.' );
	}

	/**
	 * @testdox Should report an unknown rate ID as not found on $ability.
	 *
	 * @testWith ["woocommerce/tax-rate-update"]
	 *           ["woocommerce/tax-rate-delete"]
	 *
	 * @param string $ability Ability name.
	 */
	public function test_unknown_id_is_not_found( string $ability ): void {
		$result = $this->run_ability( $ability, array( 'id' => PHP_INT_MAX ) );

		$this->assertWPError( $result );
		$this->assertSame( 'woocommerce_ability_tax_rate_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	/**
	 * @testdox Should delete a rate with its locations and return it as it was.
	 */
	public function test_delete_returns_the_old_rate(): void {
		global $wpdb;

		$rate = $this->create_rate(
			array(
				'rate'      => '19',
				'country'   => 'DE',
				'postcodes' => array( '10115' ),
				'cities'    => array( 'BERLIN' ),
			)
		);

		$result = $this->run_ability( 'woocommerce/tax-rate-delete', array( 'id' => $rate['id'] ) );

		$this->assertSame( array( 'rate' => $rate ), $result );
		$this->assertSame( 0, $this->run_ability( 'woocommerce/tax-rates-query' )['total'], 'The rate should be gone.' );
		$this->assertSame(
			'0',
			$wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_tax_rate_locations WHERE tax_rate_id = %d", $rate['id'] ) ),
			'The locations of the rate should be gone.'
		);
	}

	/**
	 * @testdox Should let a shop manager create, update and delete rates.
	 */
	public function test_shop_manager_can_write(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );

		$rate = $this->create_rate( array( 'rate' => '19' ) );
		$this->assertNotWPError(
			$this->run_ability(
				'woocommerce/tax-rate-update',
				array(
					'id'   => $rate['id'],
					'rate' => '7',
				)
			)
		);
		$this->assertNotWPError( $this->run_ability( 'woocommerce/tax-rate-delete', array( 'id' => $rate['id'] ) ) );
	}

	/**
	 * @testdox Should refuse tax rate writes to a customer.
	 */
	public function test_customer_is_refused(): void {
		$rate_id = \WC_Tax::_insert_tax_rate(
			array(
				'tax_rate'       => '19.0000',
				'tax_rate_order' => 0,
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );

		$results = array(
			$this->run_ability( 'woocommerce/tax-rate-create', array( 'rate' => '7' ) ),
			$this->run_ability(
				'woocommerce/tax-rate-update',
				array(
					'id'   => $rate_id,
					'rate' => '7',
				)
			),
			$this->run_ability( 'woocommerce/tax-rate-delete', array( 'id' => $rate_id ) ),
		);

		foreach ( $results as $result ) {
			$this->assertWPError( $result );
			$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		}
		$this->assertSame( '19.0000', \WC_Tax::_get_tax_rate( $rate_id )['tax_rate'], 'The rate should not change.' );
	}
}
