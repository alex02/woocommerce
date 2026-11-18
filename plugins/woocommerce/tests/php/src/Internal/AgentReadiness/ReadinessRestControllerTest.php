<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\AgentReadiness;

use Automattic\WooCommerce\Internal\AgentReadiness\AgentReadiness;
use Automattic\WooCommerce\Internal\AgentReadiness\ScheduledScan;
use Automattic\WooCommerce\Internal\AgentReadiness\UcpSwitch;
use Automattic\WooCommerce\Internal\Ucp\UcpDiscovery;
use WC_REST_Unit_Test_Case;
use WP_REST_Request;

/**
 * Tests for the agent readiness REST endpoints.
 */
class ReadinessRestControllerTest extends WC_REST_Unit_Test_Case {

	/**
	 * Tests fire rest_api_init with the feature both on and off.
	 *
	 * @var bool
	 */
	protected static $reuse_rest_server = false;

	/**
	 * Enable the feature after parent::setUp(), so the filter stays out of the hook
	 * snapshot WordPress takes in the first test. Routes register on first dispatch.
	 */
	public function setUp(): void {
		parent::setUp();
		add_filter( 'pre_option_woocommerce_feature_agent_readiness_enabled', array( __CLASS__, 'enabled' ) );
		// Report tests stay offline: store output checks would load the test site's pages.
		StoreOutputChecksTest::block_requests();
		delete_option( AgentReadiness::REPORT_OPTION );
		as_unschedule_all_actions( ScheduledScan::RUN_NOW_HOOK );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		as_unschedule_all_actions( ScheduledScan::RUN_NOW_HOOK );
		as_unschedule_all_actions( ScheduledScan::DAILY_HOOK );
		parent::tearDown();
	}

	/**
	 * The feature option's value while these tests run.
	 */
	public static function enabled(): string {
		return 'yes';
	}

	/**
	 * @testdox GET returns a null report before the first scan, then the stored report.
	 */
	public function test_get_returns_stored_report(): void {
		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/agent-readiness' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $response->get_data()['report'] );
		$this->assertFalse( $response->get_data()['scan_pending'] );
		$this->assertFalse( $response->get_data()['scan_stalled'] );

		$report   = wc_get_container()->get( AgentReadiness::class )->refresh_report();
		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/agent-readiness' ) );
		$this->assertSame( $report['generated_at'], $response->get_data()['report']['generated_at'] );
	}

	/**
	 * @testdox GET view gives the Sales readiness page and the AI tools Overview what they show, as text and badges.
	 */
	public function test_get_view(): void {
		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/agent-readiness/view' ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'Not checked yet', $data['last_checked'] );
		$this->assertSame( 'Check your store', $data['run']['label'] );
		$this->assertNull( $data['fix_first'] );
		$this->assertSame( array(), $data['sections'] );
		$this->assertSame( 'AI shopping', $data['overview']['shopping']['title'] );
		$this->assertStringContainsString( 'path=/sales-readiness', $data['overview']['view_all']['href'] );

		wc_get_container()->get( AgentReadiness::class )->refresh_report();
		$data = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/agent-readiness/view' ) )->get_data();
		$this->assertSame( 'Check again', $data['run']['label'] );
		$this->assertSame( array( 'access', 'find', 'understand', 'products', 'buy' ), array_column( $data['sections'], 'id' ) );
		$this->assertArrayHasKey( 'intent', $data['sections'][0]['badge'] );
	}

	/**
	 * @testdox GET reads setup checks now, so a setting changed after the scan shows its current state.
	 */
	public function test_get_reads_store_checks_now(): void {
		StoreConditionsTest::make_store_ready( $this );
		wc_get_container()->get( AgentReadiness::class )->refresh_report();
		update_option( 'woocommerce_enable_guest_checkout', 'no' );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/agent-readiness' ) );
		$checks   = array_column( $response->get_data()['report']['store_checks'], 'ok', 'id' );

		$this->assertFalse( $checks['guest_checkout'] );
	}

	/**
	 * @testdox POST scan queues a background scan and answers 202 with scan_pending.
	 */
	public function test_post_queues_scan(): void {
		$response = $this->server->dispatch( new WP_REST_Request( 'POST', '/wc/v3/agent-readiness/scan' ) );

		$this->assertSame( 202, $response->get_status() );
		$this->assertTrue( $response->get_data()['scan_pending'] );
		$this->assertTrue( as_has_scheduled_action( ScheduledScan::RUN_NOW_HOOK ) );
		$this->assertFalse( $response->get_data()['scan_stalled'] );
	}

	/**
	 * @testdox When the queued scan has stalled, POST leaves it queued and answers with scan_stalled.
	 */
	public function test_post_reports_stalled_scan(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}actionscheduler_actions SET last_attempt_gmt = %s WHERE status IN ( 'complete', 'failed', 'in-progress' )", gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );
		as_schedule_single_action( time() - ScheduledScan::STALLED_AFTER - 60, ScheduledScan::RUN_NOW_HOOK, array(), ScheduledScan::GROUP );

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', '/wc/v3/agent-readiness/scan' ) );

		$this->assertSame( 202, $response->get_status() );
		$this->assertNull( $response->get_data()['report'], 'The scan must not run in the request.' );
		$this->assertTrue( $response->get_data()['scan_pending'] );
		$this->assertTrue( $response->get_data()['scan_stalled'] );
		as_unschedule_all_actions( ScheduledScan::RUN_NOW_HOOK );
	}

	/**
	 * @testdox A user without manage_woocommerce can't read the report or queue a scan.
	 */
	public function test_requires_manage_woocommerce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );

		$this->assertSame( 403, $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/agent-readiness' ) )->get_status() );
		$this->assertSame( 403, $this->server->dispatch( new WP_REST_Request( 'POST', '/wc/v3/agent-readiness/scan' ) )->get_status() );
		$this->assertSame( 403, $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/agent-readiness/view' ) )->get_status() );
		$this->assertSame( 403, $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/agent-readiness/support-report' ) )->get_status() );
		$this->assertFalse( as_has_scheduled_action( ScheduledScan::RUN_NOW_HOOK ) );
	}

	/**
	 * @testdox With the feature disabled the routes are not registered.
	 */
	public function test_routes_absent_when_feature_disabled(): void {
		remove_filter( 'pre_option_woocommerce_feature_agent_readiness_enabled', array( __CLASS__, 'enabled' ) );
		update_option( 'woocommerce_feature_agent_readiness_enabled', 'no' );
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$this->assertSame( 404, $wp_rest_server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/agent-readiness' ) )->get_status() );
		$this->assertSame( 404, $wp_rest_server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/agent-readiness/support-report' ) )->get_status() );
	}

	/**
	 * @testdox The support report before the first check has the header and says "Not checked yet", without queueing a check.
	 */
	public function test_support_report_before_first_check(): void {
		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/agent-readiness/support-report' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertMatchesRegularExpression( '/^ai-sales-readiness-[a-z0-9.-]+-\d{4}-\d{2}-\d{2}\.txt$/', $data['filename'] );
		$this->assertStringContainsString( 'Site: ' . home_url(), $data['text'] );
		$this->assertStringContainsString( 'WooCommerce version: ' . WC_VERSION, $data['text'] );
		$this->assertStringContainsString( 'Last check: Not checked yet', $data['text'] );
		$this->assertStringContainsString( "### Store checks ###\n\nNot checked yet", $data['text'] );
		$this->assertStringContainsString( '(launched)', $data['text'], 'Setup steps are read now, so they show before the first check.' );
		$this->assertStringNotContainsString( '### Product checks ###', $data['text'] );
		$this->assertFalse( as_has_scheduled_action( ScheduledScan::RUN_NOW_HOOK ), 'The download must not queue a check.' );
	}

	/**
	 * @testdox The support report lists each check with its status and what was found, product counts with their IDs, and hides a same-cause check.
	 */
	public function test_support_report_after_a_check(): void {
		update_option( 'blog_public', '0' );
		update_option(
			AgentReadiness::REPORT_OPTION,
			array(
				'generated_at'        => '2026-11-18T10:42:00+00:00',
				'products'            => 3,
				'hidden_from_catalog' => 1,
				'unsupported_types'   => 2,
				'store_checks'        => array(
					array(
						'id'       => 'llms_txt_present',
						'ok'       => false,
						'blocks'   => 'discovery',
						'name'     => 'llms.txt',
						'why'      => 'Report why.',
						'conflict' => false,
						'source'   => '404 text/html',
					),
					array(
						'id'       => 'open_graph_tags',
						'ok'       => true,
						'blocks'   => 'discovery',
						'name'     => 'Open Graph tags',
						'why'      => '',
						'conflict' => false,
					),
					array(
						'id'       => 'no_noindex_directives',
						'ok'       => false,
						'blocks'   => 'listing',
						'name'     => 'No noindex',
						'why'      => '',
						'conflict' => false,
					),
				),
				'channels'            => array(
					'chatgpt' => array(
						'name'         => 'ChatGPT',
						'listable'     => 1,
						'not_listable' => 2,
						'with_gaps'    => 1,
					),
				),
				'checks'              => array(
					array(
						'id'          => 'missing_brand',
						'label'       => 'Products with no brand',
						'count'       => 2,
						'product_ids' => array( 12, 34 ),
					),
					array(
						'id'    => 'missing_images',
						'label' => 'Products with no image',
						'count' => 0,
					),
				),
			),
			false
		);

		$text = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/agent-readiness/support-report' ) )->get_data()['text'];

		$this->assertStringContainsString( 'Last check: 2026-11-18T10:42:00+00:00', $text );
		$this->assertStringContainsString( 'Check status: Finished', $text );
		$this->assertStringContainsString( 'Hidden: 1', $text );
		$this->assertStringContainsString( 'Not supported: 2', $text );
		$this->assertMatchesRegularExpression( '/^\[Failed\] [^\n]*\(search_visible\): \S/m', $text, 'A failing setup step says what was found.' );
		$this->assertMatchesRegularExpression( '/^\[Failed\] llms\.txt \(llms_txt_present\): \S[^\n]*Store answered: 404 text\/html$/m', $text );
		$this->assertMatchesRegularExpression( '/^\[Passed\] Open Graph tags \(open_graph_tags\)$/m', $text );
		$this->assertMatchesRegularExpression( '/^\[Failed\] No noindex \(no_noindex_directives\)[^\n]*\(hidden while search_visible fails\)$/m', $text );
		$this->assertStringContainsString( 'ChatGPT (chatgpt): 1 can be listed, 2 can’t, 1 with gaps', $text );
		$this->assertStringContainsString( '[Failed] Products with no brand (missing_brand): 2 products. Product IDs: 12, 34', $text );
		$this->assertStringContainsString( '[Passed] Products with no image (missing_images)', $text );
	}

	/**
	 * @testdox The support report holds no customer, order or account data, and no keys.
	 */
	public function test_support_report_has_no_personal_data_or_secrets(): void {
		global $wpdb;
		update_option( 'admin_email', 'owner-secret@example.com' );
		update_option( 'woocommerce_email_from_address', 'from-secret@example.com' );
		self::factory()->user->create(
			array(
				'role'       => 'customer',
				'user_email' => 'shopper-secret@example.com',
				'first_name' => 'Zebulon',
			)
		);
		$order = wc_create_order();
		$order->set_billing_email( 'billing-secret@example.com' );
		$order->set_billing_address_1( '42 Secret Street' );
		$order->save();
		$wpdb->insert(
			$wpdb->prefix . 'woocommerce_api_keys',
			array(
				'user_id'         => get_current_user_id(),
				'description'     => 'Support test',
				'permissions'     => 'read_write',
				'consumer_key'    => wc_api_hash( 'ck_secret_key_value' ),
				'consumer_secret' => 'cs_secret_value',
				'truncated_key'   => 'ecret_v',
			)
		);
		wc_get_container()->get( AgentReadiness::class )->refresh_report();

		$text = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/agent-readiness/support-report' ) )->get_data()['text'];

		foreach ( array( 'secret', 'Zebulon', '@example.com', 'cs_', 'ck_' ) as $leak ) {
			$this->assertStringNotContainsString( $leak, $text );
		}
	}

	/**
	 * @testdox POST ucp answers 409 and leaves UCP off while a required setup check fails.
	 */
	public function test_ucp_switch_blocked_by_failing_check(): void {
		StoreConditionsTest::make_store_ready();
		update_option( 'woocommerce_coming_soon', 'yes' );
		update_option( 'woocommerce_feature_ucp_enabled', 'no' );

		$response = $this->dispatch_ucp( true );
		$data     = $response->get_data();

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'woocommerce_ucp_required_checks_failing', $data['code'] );
		$this->assertSame( array( 'launched' ), $data['data']['failing_checks'] );
		$this->assertSame( 'no', get_option( 'woocommerce_feature_ucp_enabled' ) );
	}

	/**
	 * @testdox POST ucp turns UCP on once the required checks pass, and off again.
	 */
	public function test_ucp_switch_on_and_off(): void {
		StoreConditionsTest::make_store_ready();

		$response = $this->dispatch_ucp( true );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['enabled'] );
		$this->assertSame( UcpSwitch::STATUS_ACTIVE, $response->get_data()['status'] );
		$this->assertSame( 'yes', get_option( 'woocommerce_feature_ucp_enabled' ) );

		$response = $this->dispatch_ucp( false );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( UcpSwitch::STATUS_OFF, $response->get_data()['status'] );
		$this->assertSame( 'no', get_option( 'woocommerce_feature_ucp_enabled' ) );
	}

	/**
	 * @testdox When the UCP option can't be saved, POST ucp answers with an error, not with failing checks.
	 */
	public function test_ucp_switch_reports_a_failed_save(): void {
		StoreConditionsTest::make_store_ready();
		update_option( 'woocommerce_feature_ucp_enabled', 'no' );
		add_filter( 'pre_update_option_woocommerce_feature_ucp_enabled', static fn( $value, $old_value ) => $old_value, 10, 2 );

		$response = $this->dispatch_ucp( true );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'woocommerce_ucp_not_changed', $response->get_data()['code'] );
		$this->assertSame( 'no', get_option( 'woocommerce_feature_ucp_enabled' ) );
	}

	/**
	 * @testdox Turning UCP off works while a required check fails.
	 */
	public function test_ucp_switch_off_with_failing_check(): void {
		update_option( 'woocommerce_feature_ucp_enabled', 'yes' );

		$response = $this->dispatch_ucp( false );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no', get_option( 'woocommerce_feature_ucp_enabled' ) );
	}

	/**
	 * @testdox Turning UCP on with Plain permalinks writes the discovery file, so the address is served without a permalink change. Multisite refuses it.
	 */
	public function test_turning_ucp_on_writes_the_discovery_file_for_plain_permalinks(): void {
		global $wp_rewrite;
		StoreConditionsTest::make_store_ready();
		$wp_rewrite->set_permalink_structure( '' );
		$path = get_temp_dir() . uniqid( 'wc-ucp-' ) . '/ucp';
		add_filter( 'woocommerce_ucp_discovery_file_path', fn() => $path );

		try {
			$response = $this->dispatch_ucp( true );

			if ( is_multisite() ) {
				// Multisite never writes the file, so plain permalinks keep the switch off.
				$this->assertSame( 409, $response->get_status() );
				$this->assertFileDoesNotExist( $path );
				return;
			}
			$this->assertSame( 200, $response->get_status() );
			$this->assertFileExists( $path );
			$this->assertSame( 'ok', UcpDiscovery::serve_status() );
		} finally {
			wp_delete_file( $path );
			$dir = dirname( $path );
			if ( is_dir( $dir ) ) {
				rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			}
		}
	}

	/**
	 * @testdox A user without manage_woocommerce can't switch UCP.
	 */
	public function test_ucp_switch_requires_manage_woocommerce(): void {
		StoreConditionsTest::make_store_ready();
		update_option( 'woocommerce_feature_ucp_enabled', 'no' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );

		$this->assertSame( 403, $this->dispatch_ucp( true )->get_status() );
		$this->assertSame( 'no', get_option( 'woocommerce_feature_ucp_enabled' ) );
	}

	/**
	 * Send POST wc/v3/agent-readiness/ucp.
	 *
	 * @param bool $enabled Whether to turn UCP on.
	 */
	private function dispatch_ucp( bool $enabled ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/wc/v3/agent-readiness/ucp' );
		$request->set_param( 'enabled', $enabled );
		return $this->server->dispatch( $request );
	}
}
