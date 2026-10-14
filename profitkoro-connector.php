<?php
/**
 * Plugin Name:       ProfitKoro Connector
 * Plugin URI:        https://github.com/ChaarbitOrg/profitkoro-connector
 * Description:       Connects WooCommerce affiliate orders, refunds, and shipment tracking to ProfitKoro.
 * Version:           1.4.0
 * Requires at least: 6.8
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * WC tested up to:   11.0
 * Author:            Chaarbit
 * Text Domain:       profitkoro-connector
 */

declare( strict_types=1 );

namespace ProfitKoro\Connector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '1.4.0';
const PLUGIN_FILE = __FILE__;
const PLUGIN_PATH = __DIR__ . '/';
const DB_VERSION = '3';

// WooCommerce requires an explicit HPOS declaration for plugins that use its order CRUD API.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				PLUGIN_FILE,
				true
			);
		}
	}
);

require_once PLUGIN_PATH . 'includes/class-settings.php';
require_once PLUGIN_PATH . 'includes/class-logger.php';
require_once PLUGIN_PATH . 'includes/class-click-log.php';
require_once PLUGIN_PATH . 'includes/class-click-capture.php';
require_once PLUGIN_PATH . 'includes/class-payload-builder.php';
require_once PLUGIN_PATH . 'includes/class-transmitter.php';
require_once PLUGIN_PATH . 'includes/class-ping.php';
require_once PLUGIN_PATH . 'includes/class-connection-test.php';
require_once PLUGIN_PATH . 'includes/class-health.php';
require_once PLUGIN_PATH . 'includes/class-k7r2.php';
require_once PLUGIN_PATH . 'includes/class-order-events.php';
require_once PLUGIN_PATH . 'includes/class-admin.php';

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>'
						. esc_html__( 'ProfitKoro Connector requires WooCommerce to be active.', 'profitkoro-connector' )
						. '</p></div>';
				}
			);
			return;
		}

		// Plugin updates do not run the activation hook, so new tables are created on the next load.
		if ( get_option( 'profitkoro_connector_db_version' ) !== DB_VERSION ) {
			install_tables();
		}

		ClickCapture::init();
		OrderEvents::init();
		Transmitter::init();
		Ping::init();
		Health::init();

		if ( is_admin() ) {
			Admin::init();
		}
	}
);

function install_tables(): void {
	Logger::install_table();
	ClickLog::install_table();
	update_option( 'profitkoro_connector_db_version', DB_VERSION );
}

register_activation_hook( PLUGIN_FILE, __NAMESPACE__ . '\install_tables' );

register_deactivation_hook(
	PLUGIN_FILE,
	static function (): void {
		wp_clear_scheduled_hook( Health::HOOK );
	}
);
