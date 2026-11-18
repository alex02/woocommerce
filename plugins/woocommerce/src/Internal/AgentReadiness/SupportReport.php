<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\AgentReadiness;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\ComingSoon\ComingSoonHelper;
use Automattic\WooCommerce\Internal\Ucp\UcpUtils;

defined( 'ABSPATH' ) || exit;

/**
 * The plain-text report a merchant downloads from Sales readiness and sends to support. It is built
 * from the stored report and the store's settings, without running a check, and holds only settings,
 * check results and product IDs: no customer or order data, and no keys.
 *
 * @since 11.4.0
 */
class SupportReport {

	/**
	 * Readiness service.
	 *
	 * @var AgentReadiness
	 */
	private AgentReadiness $agent_readiness;

	/**
	 * Store conditions.
	 *
	 * @var StoreConditions
	 */
	private StoreConditions $store_conditions;

	/**
	 * Background scan.
	 *
	 * @var ScheduledScan
	 */
	private ScheduledScan $scheduled_scan;

	/**
	 * UCP switch.
	 *
	 * @var UcpSwitch
	 */
	private UcpSwitch $ucp_switch;

	/**
	 * Rules file.
	 *
	 * @var RemoteRules
	 */
	private RemoteRules $remote_rules;

	/**
	 * Coming soon mode.
	 *
	 * @var ComingSoonHelper
	 */
	private ComingSoonHelper $coming_soon;

	/**
	 * Initialize dependencies.
	 *
	 * @internal
	 *
	 * @param AgentReadiness   $agent_readiness  Readiness service.
	 * @param StoreConditions  $store_conditions Store conditions.
	 * @param ScheduledScan    $scheduled_scan   Background scan.
	 * @param UcpSwitch        $ucp_switch       UCP switch.
	 * @param RemoteRules      $remote_rules     Rules file.
	 * @param ComingSoonHelper $coming_soon      Coming soon mode.
	 */
	final public function init( AgentReadiness $agent_readiness, StoreConditions $store_conditions, ScheduledScan $scheduled_scan, UcpSwitch $ucp_switch, RemoteRules $remote_rules, ComingSoonHelper $coming_soon ): void {
		$this->agent_readiness  = $agent_readiness;
		$this->store_conditions = $store_conditions;
		$this->scheduled_scan   = $scheduled_scan;
		$this->ucp_switch       = $ucp_switch;
		$this->remote_rules     = $remote_rules;
		$this->coming_soon      = $coming_soon;
	}

	/**
	 * File name for the download: ai-sales-readiness-<site host>-<date>.txt.
	 */
	public function filename(): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return sanitize_file_name( 'ai-sales-readiness-' . ( '' !== $host ? $host : 'site' ) . '-' . gmdate( 'Y-m-d' ) . '.txt' );
	}

	/**
	 * The report as text, one line per item.
	 */
	public function text(): string {
		$report = $this->agent_readiness->get_cached_report();
		$live   = $this->store_conditions->all();
		$lines  = array_merge(
			$this->header( $report ),
			array( '' ),
			$this->shopping( $report ),
			array( '' ),
			$this->store_checks( $report, $live )
		);
		if ( null !== $report ) {
			$lines = array_merge( $lines, array( '' ), self::product_checks( $report ) );
		}
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Site, versions, and when the last check ran and how it ended.
	 *
	 * @param array<string, mixed>|null $report Stored report.
	 * @return string[]
	 */
	private function header( ?array $report ): array {
		$rules   = $this->remote_rules->version();
		$pending = $this->scheduled_scan->is_pending();
		if ( $pending && $this->scheduled_scan->is_stalled() ) {
			$state = __( 'Can’t start: scheduled actions aren’t running', 'woocommerce' );
		} elseif ( $pending ) {
			$state = __( 'Running', 'woocommerce' );
		} else {
			$state = null === $report ? __( 'Not checked yet', 'woocommerce' ) : __( 'Finished', 'woocommerce' );
		}
		$lines = array(
			'### ' . __( 'AI sales readiness', 'woocommerce' ) . ' ###',
			'',
			__( 'Site', 'woocommerce' ) . ': ' . home_url(),
			__( 'WooCommerce version', 'woocommerce' ) . ': ' . (string) Constants::get_constant( 'WC_VERSION' ),
			__( 'Rules version', 'woocommerce' ) . ': ' . ( '' !== $rules ? $rules : __( 'Bundled', 'woocommerce' ) ),
			__( 'Last check', 'woocommerce' ) . ': ' . ( null === $report ? __( 'Not checked yet', 'woocommerce' ) : (string) ( $report['generated_at'] ?? '' ) ),
			__( 'Check status', 'woocommerce' ) . ': ' . $state,
		);
		if ( null !== $report && ! empty( $report['truncated'] ) ) {
			/* translators: %s: number of products checked. */
			$lines[] = sprintf( __( 'Stopped at %s products', 'woocommerce' ), number_format_i18n( ProductScan::PRODUCT_LIMIT ) );
		}
		return $lines;
	}

	/**
	 * The UCP switch, store visibility and product visibility.
	 *
	 * @param array<string, mixed>|null $report Stored report.
	 * @return string[]
	 */
	private function shopping( ?array $report ): array {
		$statuses = array(
			UcpSwitch::STATUS_ACTIVE          => __( 'On', 'woocommerce' ),
			UcpSwitch::STATUS_NEEDS_ATTENTION => __( 'On, a required step fails', 'woocommerce' ),
			UcpSwitch::STATUS_OFF             => __( 'Off', 'woocommerce' ),
		);
		if ( $this->coming_soon->is_site_coming_soon() ) {
			$visibility = __( 'Coming soon (whole site)', 'woocommerce' );
		} elseif ( $this->coming_soon->is_store_coming_soon() ) {
			$visibility = __( 'Coming soon (store pages)', 'woocommerce' );
		} else {
			$visibility = __( 'Live', 'woocommerce' );
		}
		$lines = array(
			'### ' . __( 'AI shopping', 'woocommerce' ) . ' ###',
			'',
			__( 'AI shopping (UCP)', 'woocommerce' ) . ': ' . ( $statuses[ $this->ucp_switch->status() ] ?? '' ),
			__( 'Store visibility', 'woocommerce' ) . ': ' . $visibility,
		);
		if ( null !== $report ) {
			$lines[] = __( 'Products checked', 'woocommerce' ) . ': ' . number_format_i18n( (int) ( $report['products'] ?? 0 ) );
			$lines[] = __( 'Hidden', 'woocommerce' ) . ': ' . number_format_i18n( (int) ( $report['hidden_from_catalog'] ?? 0 ) );
			$lines[] = __( 'Not supported', 'woocommerce' ) . ': ' . number_format_i18n( (int) ( $report['unsupported_types'] ?? 0 ) );
		}
		return $lines;
	}

	/**
	 * Every setup step, read now, then every scanned check from the report, with its status and what was found when it fails.
	 *
	 * @param array<string, mixed>|null        $report Stored report.
	 * @param array<int, array<string, mixed>> $live   Store conditions read now.
	 * @return string[]
	 */
	private function store_checks( ?array $report, array $live ): array {
		$steps   = array_column( $live, 'ok', 'id' );
		$lines   = array( '### ' . __( 'Setup steps', 'woocommerce' ) . ' ###', '' );
		$scanned = array_slice( $this->agent_readiness->get_store_checks( $report, $live ), count( $live ) );
		foreach ( $live as $check ) {
			$lines[] = self::check_line( $check, (string) $check['why'] );
		}
		$lines[] = '';
		$lines[] = '### ' . __( 'Store checks', 'woocommerce' ) . ' ###';
		$lines[] = '';
		if ( null === $report ) {
			$lines[] = __( 'Not checked yet', 'woocommerce' );
			return $lines;
		}
		foreach ( $scanned as $check ) {
			$copy = ReadinessCopy::store_check( $check['id'], (string) ( $check['source'] ?? '' ) );
			$line = self::check_line( $check, null !== $copy ? $copy['found'] : (string) $check['why'] );
			$step = ReadinessCheckPage::SAME_CAUSE[ $check['id'] ] ?? '';
			if ( false === ( $steps[ $step ] ?? null ) ) {
				/* translators: %s: setup step id, such as search_visible. */
				$line .= ' ' . sprintf( __( '(hidden while %s fails)', 'woocommerce' ), $step );
			} elseif ( 'ucp' === $check['blocks'] && ! UcpUtils::is_enabled() ) {
				$line .= ' ' . __( '(hidden while AI shopping is off)', 'woocommerce' );
			}
			$lines[] = $line;
		}
		return $lines;
	}

	/**
	 * One check: status, name and id, then what was found when it fails, where it was seen and what the store answered.
	 *
	 * @param array<string, mixed> $check Store check.
	 * @param string               $found What was found, shown when it fails.
	 */
	private static function check_line( array $check, string $found ): string {
		if ( ! empty( $check['pending'] ) ) {
			$status = __( 'Coming soon', 'woocommerce' );
		} elseif ( null === $check['ok'] ) {
			$status = __( 'Couldn’t check', 'woocommerce' );
		} else {
			$status = $check['ok'] ? __( 'Passed', 'woocommerce' ) : __( 'Failed', 'woocommerce' );
		}
		$line = sprintf( '[%s] %s (%s)', $status, $check['name'], $check['id'] );
		if ( false === $check['ok'] && empty( $check['pending'] ) ) {
			$line .= ': ' . trim( $found );
			if ( ! empty( $check['conflict'] ) ) {
				$line .= ' ' . __( '(likely conflict)', 'woocommerce' );
			}
		}
		if ( '' !== ( $check['seen_at'] ?? '' ) ) {
			$line .= ' ' . __( 'Seen at', 'woocommerce' ) . ': ' . $check['seen_at'];
		}
		if ( '' !== ( $check['source'] ?? '' ) && true !== $check['ok'] ) {
			$line .= ' ' . __( 'Store answered', 'woocommerce' ) . ': ' . $check['source'];
		}
		return $line;
	}

	/**
	 * Channel totals, then each product check with its count and example product IDs.
	 *
	 * @param array<string, mixed> $report Stored report.
	 * @return string[]
	 */
	private static function product_checks( array $report ): array {
		$lines = array( '### ' . __( 'Product checks', 'woocommerce' ) . ' ###', '' );
		foreach ( is_array( $report['channels'] ?? null ) ? $report['channels'] : array() as $id => $channel ) {
			if ( ! is_array( $channel ) ) {
				continue;
			}
			$lines[] = sprintf(
				/* translators: 1: channel name, such as ChatGPT, 2: channel id, 3: products that can be listed, 4: products that can't, 5: products listed with gaps. */
				__( '%1$s (%2$s): %3$s can be listed, %4$s can’t, %5$s with gaps', 'woocommerce' ),
				(string) ( $channel['name'] ?? $id ),
				(string) $id,
				number_format_i18n( (int) ( $channel['listable'] ?? 0 ) ),
				number_format_i18n( (int) ( $channel['not_listable'] ?? 0 ) ),
				number_format_i18n( (int) ( $channel['with_gaps'] ?? 0 ) )
			);
		}
		$lines[] = '';
		foreach ( is_array( $report['checks'] ?? null ) ? $report['checks'] : array() as $check ) {
			if ( ! is_array( $check ) || ! isset( $check['id'] ) ) {
				continue;
			}
			$count = (int) ( $check['count'] ?? 0 );
			$line  = sprintf( '[%s] %s (%s)', 0 === $count ? __( 'Passed', 'woocommerce' ) : __( 'Failed', 'woocommerce' ), (string) ( $check['label'] ?? $check['id'] ), (string) $check['id'] );
			if ( $count > 0 ) {
				$ids = array_map( 'intval', (array) ( $check['product_ids'] ?? array() ) );
				/* translators: %s: number of products. */
				$line .= ': ' . sprintf( _n( '%s product', '%s products', $count, 'woocommerce' ), number_format_i18n( $count ) );
				if ( array() !== $ids ) {
					$line .= '. ' . __( 'Product IDs', 'woocommerce' ) . ': ' . implode( ', ', $ids );
				}
			}
			$lines[] = $line;
		}
		return $lines;
	}
}
