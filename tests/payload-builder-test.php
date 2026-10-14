<?php

declare( strict_types=1 );

namespace ProfitKoro\Connector {

	const VERSION = '0.0.0';

	class Settings {

		public const META_CLICK_ID      = '_cpa_click_id';
		public const META_CLICK_MISSING = '_cpa_click_missing_reason';
		public const META_TRACKING_URL  = '_cpa_shipment_tracking_url';
		public const META_LINE_PRODUCT  = '_cpa_line_product';
	}

	class ClickCapture {

		public const REASON_NO_COOKIE = 'no_cookie';
	}
}

namespace {

	use ProfitKoro\Connector\PayloadBuilder;

	if ( PHP_SAPI !== 'cli' ) {
		exit;
	}

	define( 'ABSPATH', __DIR__ . '/' );

	class WC_Product {

		public function __construct( public string $sku ) {}

		public function get_sku(): string {
			return $this->sku;
		}

		public function needs_shipping(): bool {
			return true;
		}
	}

	class WC_Order_Item_Product {

		public ?WC_Product $product;

		public int $product_id = 91;

		private array $meta = [];

		public function __construct() {
			$this->product = new WC_Product( 'TSHIRT-RED' );
		}

		public function get_product(): ?WC_Product {
			return $this->product;
		}

		public function get_product_id(): int {
			return $this->product_id;
		}

		public function get_variation_id(): int {
			return 0;
		}

		public function get_name(): string {
			return 'Red T-Shirt';
		}

		public function get_quantity(): int {
			return 2;
		}

		public function get_subtotal(): string {
			return '2400';
		}

		public function get_total(): string {
			return '2400';
		}

		public function get_meta( string $key ) {
			return $this->meta[ $key ] ?? '';
		}

		public function update_meta_data( string $key, $value ): void {
			$this->meta[ $key ] = $value;
		}

		public function save_meta_data(): void {}
	}

	class WC_Order {

		public function __construct( public WC_Order_Item_Product $item ) {}

		public function get_items(): array {
			return [ 1 => $this->item ];
		}

		public function get_id(): int {
			return 10;
		}

		public function get_order_number(): string {
			return '10';
		}

		public function get_status(): string {
			return 'processing';
		}

		public function get_payment_method(): string {
			return 'cod';
		}

		public function get_currency(): string {
			return 'BDT';
		}

		public function get_date_created() {
			return null;
		}

		public function get_meta( string $key ): string {
			return '';
		}

		public function get_qty_refunded_for_item( $item_id ): int {
			return 0;
		}

		public function get_total_refunded_for_item( $item_id ): float {
			return 0.0;
		}

		public function __call( string $name, array $arguments ): string {
			return '0';
		}
	}

	$permalinks = [ 91 => 'https://shop.test/product/red-t-shirt/' ];

	function get_permalink( $id ) {
		global $permalinks;
		return $permalinks[ $id ] ?? false;
	}

	function wc_format_decimal( $value, int $dp, bool $trim ): string {
		return number_format( (float) $value, $dp, '.', '' );
	}

	function check( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	}

	require dirname( __DIR__ ) . '/includes/class-payload-builder.php';

	$item  = new WC_Order_Item_Product();
	$order = new WC_Order( $item );

	$line = PayloadBuilder::build( $order, 'order.created', 'e1' )['items'][0];
	check( '91' === $line['product_id'], 'A line reports its product.' );
	check( 'https://shop.test/product/red-t-shirt/' === $line['permalink'], 'A line reports its product page.' );

	$permalinks[91] = 'https://shop.test/product/renamed/';
	$line           = PayloadBuilder::build( $order, 'order.updated', 'e2' )['items'][0];
	check( 'https://shop.test/product/red-t-shirt/' === $line['permalink'], 'Moving the product page does not change an existing line.' );

	$item->product    = null;
	$item->product_id = 0;
	unset( $permalinks[91] );
	$line = PayloadBuilder::build( $order, 'order.updated', 'e3' )['items'][0];
	check( '91' === $line['product_id'] && 'TSHIRT-RED' === $line['sku'], 'Deleting the product does not change an existing line.' );
	check( 'https://shop.test/product/red-t-shirt/' === $line['permalink'], 'A deleted product keeps the page the line was bought from.' );

	echo "Payload builder checks passed.\n";
}
