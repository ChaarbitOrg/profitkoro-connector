<?php

declare( strict_types=1 );

if ( PHP_SAPI !== 'cli' ) {
	exit;
}

use ProfitKoro\Connector\Transmitter;

define( 'ABSPATH', __DIR__ . '/' );
$options   = [];
$reply     = null;
$scheduled = [];

class WP_Error {

	public function __construct( private string $code, private string $message ) {}

	public function get_error_message(): string {
		return $this->message;
	}

	public function get_error_code(): string {
		return $this->code;
	}
}

$wpdb = new class() {
	public string $prefix = 'wp_';

	public function insert( string $table, array $row ): bool {
		return true;
	}
};

function check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {}

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

function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

function home_url( string $path = '' ): string {
	return 'https://shop.example' . $path;
}

function current_time( string $type, bool $gmt = false ): string {
	return gmdate( "Y-m-d H:i:s" );
}

function wc_get_order( $order_id ) {
	return false;
}

function wp_remote_post( string $url, array $args ) {
	global $reply;
	return $reply;
}

function wp_remote_retrieve_response_code( array $response ): int {
	return $response['code'];
}

function wp_remote_retrieve_body( array $response ): string {
	return $response['body'];
}

function wp_remote_retrieve_header( array $response, string $header ): string {
	return $response['headers'][ $header ] ?? '';
}

function as_schedule_single_action( int $timestamp, string $hook, array $args, string $group ): int {
	global $scheduled;
	$scheduled[] = [ 'delay' => $timestamp - time(), 'attempt' => $args['attempt'] ];
	return 1;
}

require dirname( __DIR__ ) . '/profitkoro-connector.php';

$payload = [ 'event_id' => 'evt-1', 'event_type' => 'order.status_changed', 'merchant_order_id' => '7' ];

function send( array $response, int $attempt = 0 ): ?array {
	global $reply, $scheduled, $payload;
	$reply     = $response;
	$scheduled = [];
	Transmitter::handle( $payload, $attempt );
	return $scheduled[0] ?? null;
}

$retry = send( [ 'code' => 429, 'body' => '{}', 'headers' => [] ] );
check( null !== $retry && 1 === $retry['attempt'], 'Rate limited events must be retried.' );
check( abs( $retry['delay'] - 60 ) <= 1, 'Without Retry-After the normal delay applies.' );

$retry = send( [ 'code' => 429, 'body' => '{}', 'headers' => [ 'retry-after' => '600' ] ] );
check( null !== $retry && abs( $retry['delay'] - 600 ) <= 1, 'A longer Retry-After is honoured.' );

$retry = send( [ 'code' => 429, 'body' => '{}', 'headers' => [ 'retry-after' => '5' ] ] );
check( null !== $retry && abs( $retry['delay'] - 60 ) <= 1, 'A shorter Retry-After does not retry sooner than scheduled.' );

$retry = send( [ 'code' => 429, 'body' => '{}', 'headers' => [ 'retry-after' => gmdate( 'D, d M Y H:i:s', time() + 1200 ) . ' GMT' ] ] );
check( null !== $retry && abs( $retry['delay'] - 1200 ) <= 2, 'Retry-After as a date is honoured.' );

$retry = send( [ 'code' => 429, 'body' => '{}', 'headers' => [ 'retry-after' => '999999' ] ] );
check( null !== $retry && abs( $retry['delay'] - 21600 ) <= 1, 'Retry-After is capped at the longest delay.' );

check( null === send( [ 'code' => 429, 'body' => '{}', 'headers' => [] ], 5 ), 'Retries still stop after the last attempt.' );

check( null === send( [ 'code' => 400, 'body' => '{}', 'headers' => [] ] ), 'A rejected event is not retried.' );
check( null === send( [ 'code' => 401, 'body' => '{}', 'headers' => [] ] ), 'Rejected credentials are not retried.' );

$retry = send( [ 'code' => 503, 'body' => '{}', 'headers' => [] ] );
check( null !== $retry && 1 === $retry['attempt'], 'Server errors are still retried.' );

echo "Delivery retry checks passed.\n";
