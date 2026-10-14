<?php

declare( strict_types=1 );

namespace ProfitKoro\Connector;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PayloadBuilder {

	// Increment only for breaking envelope changes so existing platform parsers remain compatible.
	public const SCHEMA_VERSION = 1;

	public const CONNECTOR_NAME = 'woocommerce-plugin';

	public static function build( WC_Order $order, string $event_type, string $event_id ): array {
		return [
			'schema_version'    => self::SCHEMA_VERSION,
			'connector'         => self::CONNECTOR_NAME . '/' . VERSION,
			'event_id'          => $event_id,
			'event_type'        => $event_type,
			'occurred_at'       => self::now_iso(),
			'placed_at'         => self::order_created_iso( $order ),

			'merchant_order_id' => (string) $order->get_id(),
			'order_number'      => (string) $order->get_order_number(),
			'order_status'      => $order->get_status(),
			'payment_method'    => $order->get_payment_method(),

			'click_id'             => self::click_id( $order ),
			'click_missing_reason' => self::click_missing_reason( $order ),

			'shipment_tracking_url' => self::tracking_url( $order ),

			'currency'       => $order->get_currency(),
			'items_subtotal' => self::items_subtotal( $order ),
			'discount_total' => self::money( $order->get_total_discount() ),
			'shipping_total' => self::money( $order->get_shipping_total() ),
			'tax_total'      => self::money( $order->get_total_tax() ),
			'grand_total'    => self::money( $order->get_total() ),
			'refunded_total' => self::money( $order->get_total_refunded() ),

			'items' => self::line_items( $order ),
		];
	}

	// Sum the reported line subtotals so the order aggregate matches the item values.
	private static function items_subtotal( WC_Order $order ): string {
		$total = 0.0;
		foreach ( $order->get_items() as $item ) {
			$total += (float) $item->get_subtotal();
		}
		return self::money( $total );
	}

	private static function line_items( WC_Order $order ): array {
		$lines = [];

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product     = $item->get_product();
			$identity    = self::line_product( $item, $product );
			$quantity    = (int) $item->get_quantity();
			$subtotal    = (float) $item->get_subtotal();
			// Use WooCommerce's allocated line totals so order-level discounts are reported consistently.
			$line_total  = (float) $item->get_total();
			$unit_price  = $quantity > 0 ? $subtotal / $quantity : 0.0;

			// WooCommerce returns negative refunds; the platform expects positive refunded quantities and amounts.
			$refunded_qty    = abs( (int) $order->get_qty_refunded_for_item( $item_id ) );
			$refunded_amount = abs( (float) $order->get_total_refunded_for_item( $item_id ) );

			$lines[] = [
				'product_id'    => $identity['product_id'],
				'variation_id'  => $identity['variation_id'],
				'sku'           => $identity['sku'],
				'name'          => $item->get_name(),
				'permalink'     => $identity['permalink'],

				'requires_shipping' => $identity['requires_shipping'],

				'quantity'      => $quantity,
				'unit_price'    => self::money( $unit_price ),
				'line_subtotal' => self::money( $subtotal ),

				'line_discount' => self::money( $subtotal - $line_total ),
				'line_total'    => self::money( $line_total ),

				'refunded_quantity' => $refunded_qty,
				'refunded_amount'   => self::money( $refunded_amount ),
			];
		}

		return $lines;
	}

	// The product as it was when the line was first reported: deleting the product or changing its
	// address afterwards must not change which product an existing order line is.
	private static function line_product( WC_Order_Item_Product $item, $product ): array {
		$saved = $item->get_meta( Settings::META_LINE_PRODUCT );
		if ( is_array( $saved ) ) {
			return $saved;
		}

		$identity = [
			'product_id'        => (string) $item->get_product_id(),
			'variation_id'      => $item->get_variation_id() ? (string) $item->get_variation_id() : null,
			'sku'               => $product ? $product->get_sku() : null,
			'permalink'         => self::product_permalink( $item ),
			'requires_shipping' => self::line_requires_shipping( $product ),
		];
		if ( $product instanceof WC_Product ) {
			$item->update_meta_data( Settings::META_LINE_PRODUCT, $identity );
			$item->save_meta_data();
		}
		return $identity;
	}

	// Use the parent product URL because variation query strings are removed during platform offer matching.
	private static function product_permalink( WC_Order_Item_Product $item ): ?string {
		$product_id = $item->get_product_id();
		if ( ! $product_id ) {
			return null;
		}
		$permalink = get_permalink( $product_id );
		return is_string( $permalink ) && '' !== $permalink ? $permalink : null;
	}

	private static function line_requires_shipping( $product ): bool {
		// Treat deleted products as physical because their shipping requirements are no longer available.
		if ( ! $product instanceof WC_Product ) {
			return true;
		}
		// Downloadable products can still require delivery; follow WooCommerce's shipping decision.
		return (bool) $product->needs_shipping();
	}

	private static function tracking_url( WC_Order $order ): ?string {
		$url = trim( (string) $order->get_meta( Settings::META_TRACKING_URL ) );
		return '' !== $url ? $url : null;
	}

	private static function click_id( WC_Order $order ): ?string {
		$stored = (string) $order->get_meta( Settings::META_CLICK_ID );
		return '' !== $stored ? $stored : null;
	}

	private static function click_missing_reason( WC_Order $order ): ?string {
		if ( null !== self::click_id( $order ) ) {
			return null;
		}
		$reason = (string) $order->get_meta( Settings::META_CLICK_MISSING );
		return '' !== $reason ? $reason : ClickCapture::REASON_NO_COOKIE;
	}

	// The API requires decimal strings to avoid JSON numeric precision loss and retain WooCommerce rounding.
	private static function money( $value ): string {
		return (string) wc_format_decimal( (float) $value, 2, false );
	}

	private static function now_iso(): string {
		return ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->format( DateTimeInterface::ATOM );
	}

	private static function order_created_iso( WC_Order $order ): string {
		$created = $order->get_date_created();
		if ( ! $created ) {
			return self::now_iso();
		}
		return ( new DateTimeImmutable( '@' . $created->getTimestamp() ) )
			->setTimezone( new DateTimeZone( 'UTC' ) )
			->format( DateTimeInterface::ATOM );
	}
}
