<?php

declare( strict_types=1 );

if ( PHP_SAPI !== 'cli' ) {
	exit;
}

use ProfitKoro\Connector\ConnectionTest;
use ProfitKoro\Connector\Health;
use ProfitKoro\Connector\Settings;
use ProfitKoro\Connector\Transmitter;

define( 'ABSPATH', __DIR__ . '/' );
$options = [];
$actions = [];
$reply = null;

class WP_Error {

	public function __construct( private string $code, private string $message ) {}

	public function get_error_message(): string {
		return $this->message;
	}

	public function get_error_code(): string {
		return $this->code;
	}
}

function check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
	global $actions;
	$actions[ $hook ][] = $callback;
}

function register_activation_hook( string $file, $callback ): void {}

function register_deactivation_hook( string $file, $callback ): void {}

function get_option( string $name, $default = false ) {
	global $options;
	return $options[ $name ] ?? $default;
}

function update_option( string $name, $value, $autoload = null ): bool {
	global $options;
	$options[ $name ] = $value;
	return true;
}

function delete_option( string $name ): bool {
	global $options;
	unset( $options[ $name ] );
	return true;
}

function apply_filters( string $hook, $value ) {
	return $value;
}

function __( string $message, string $domain ): string {
	return $message;
}

function wp_json_encode( $value ): string|false {
	return json_encode( $value );
}

function wp_generate_uuid4(): string {
	return '5b23f33c-abcd-4000-8000-123456789abc';
}

function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

function home_url( string $path = '' ): string {
	return 'https://shop.example' . $path;
}

function wp_remote_post( string $url, array $args ) {
	global $reply;
	check( 'https://profitkoro.com/api/events' === $url, 'Default endpoint must use ProfitKoro.' );
	$body = json_decode( $args['body'], true );
	check( 'connection.setup' === $body['event_type'], 'Setup must not submit an order event.' );
	check( ! isset( $body['merchant_order_id'] ), 'Setup must not contain an order.' );
	check( 'https://shop.example/' === $body['site_url'], 'Setup must identify the site it was sent from.' );
	$headers = $args['headers'];
	check( 'test-store-key' === $headers['X-CPA-Store-Key'], 'Store key must remain in the signed request headers.' );
	check( hash_equals(
		hash_hmac( 'sha256', $headers['X-CPA-Timestamp'] . '.' . $args['body'], 'test-secret' ),
		$headers['X-CPA-Signature']
	), 'Request signature must cover the exact transmitted bytes.' );
	return $reply;
}

function wp_remote_retrieve_response_code( array $response ): int {
	return $response['code'];
}

function wp_remote_retrieve_body( array $response ): string {
	return $response['body'];
}

require dirname( __DIR__ ) . '/profitkoro-connector.php';

check( is_wp_error( ConnectionTest::connect( '', '' ) ), 'Empty credentials cannot pass setup.' );

foreach ( [
	[ 'code' => 200, 'body' => '<html>Proxy response</html>' ],
	[ 'code' => 202, 'body' => json_encode( [ 'accepted' => false, 'status' => 'connection_ready', 'event_id' => wp_generate_uuid4() ] ) ],
	[ 'code' => 202, 'body' => json_encode( [ 'accepted' => true, 'status' => 'connection_ready', 'event_id' => 'different-event' ] ) ],
	[ 'code' => 202, 'body' => json_encode( [ 'accepted' => true, 'status' => 'ping_unmatched', 'event_id' => wp_generate_uuid4() ] ) ],
	[ 'code' => 401, 'body' => '{}' ],
	new WP_Error( 'timeout', 'Request timed out.' ),
] as $reply ) {
	check( is_wp_error( ConnectionTest::connect( 'test-store-key', 'test-secret' ) ), 'A rejected or unconfirmed test must fail.' );
	check( ! Settings::is_configured(), 'Rejected credentials must not be saved.' );
	check( empty( Settings::connection_state()['verified_at'] ), 'Failed tests must not leave the store ready.' );
}

$reply = [ 'code' => 202, 'body' => json_encode( [
	'accepted' => true,
	'status' => 'connection_ready',
	'event_id' => wp_generate_uuid4(),
] ) ];
check( ! is_wp_error( ConnectionTest::connect( 'test-store-key', 'test-secret' ) ), 'Accepted setup event must pass.' );
check( 'test-secret' === Settings::secret(), 'Accepted credentials must be saved.' );
check( ! empty( Settings::connection_state()['verified_at'] ), 'Accepted setup must persist readiness.' );

$reply = [ 'code' => 401, 'body' => '{}' ];
check( is_wp_error( ConnectionTest::connect( 'test-store-key', 'test-secret' ) ), 'Rejected replacement credentials must fail.' );
check( 'test-secret' === Settings::secret(), 'A rejected replacement must keep the working credentials.' );
check( ! empty( Settings::connection_state()['verified_at'] ), 'A rejected replacement must keep the store ready.' );

Health::record_failure( new WP_Error( 'unreachable', 'Request timed out.' ) );
check( ! Health::is_failing(), 'A single network failure must not warn the merchant.' );
Health::record_failure( new WP_Error( 'unreachable', 'Request timed out.' ) );
check( Health::is_failing(), 'Repeated network failures must warn the merchant.' );
Health::record_success();
check( ! Health::is_failing(), 'A successful delivery must clear the warning.' );
Health::record_failure( new WP_Error( ConnectionTest::REJECTED, 'Rejected.' ) );
check( Health::is_failing(), 'Rejected credentials must warn the merchant immediately.' );

$reply = [ 'code' => 202, 'body' => json_encode( [
	'accepted' => true,
	'status' => 'connection_ready',
	'event_id' => wp_generate_uuid4(),
] ) ];
check( ! is_wp_error( ConnectionTest::connect( 'test-store-key', 'test-secret' ) ), 'Reconnecting must pass.' );
check( ! Health::is_failing(), 'A successful setup must clear earlier connection problems.' );

Health::record_failure( new WP_Error( ConnectionTest::REJECTED, 'Rejected.' ) );
$options[ Settings::OPT_SECRET ] = 'replaced-secret';
check( [] === Settings::connection_state(), 'Credential changes must invalidate readiness.' );
check( ! Health::is_failing(), 'Failures for earlier credentials must not apply to new ones.' );

Transmitter::init();
check( isset( $actions['profitkoro_connector_send_event'] ), 'Register the renamed delivery hook.' );
check( isset( $actions['cpa_connector_send_event'] ), 'Retain the old hook for pending scheduled events.' );

echo "Connection setup checks passed.\n";
