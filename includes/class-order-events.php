<?php

declare( strict_types=1 );

namespace ProfitKoro\Connector;

use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OrderEvents {

	public static function init(): void {
		// Persist attribution before later checkout handlers process the order.
		add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'on_checkout' ], 5, 1 );
		// Block checkout uses the Store API hook instead of the classic checkout hook.
		add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'on_checkout' ], 5, 1 );

		add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'on_status_changed' ], 10, 4 );
		// Partial refunds do not trigger a status change and require their own event.
		add_action( 'woocommerce_order_refunded', [ __CLASS__, 'on_refunded' ], 10, 2 );
	}

	public static function on_checkout( $order_id ): void {
		$order = self::resolve_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$token = ClickCapture::current_token();
		if ( '' !== $token ) {
			$order->update_meta_data( Settings::META_CLICK_ID, $token );
			$order->add_order_note(
				sprintf(
					/* translators: %s: Affiliate click ID recorded on the order. */
					__( 'ProfitKoro: affiliate click captured (%s).', 'profitkoro-connector' ),
					$token
				)
			);
		} else {
			$order->update_meta_data(
				Settings::META_CLICK_MISSING,
				ClickCapture::current_missing_reason()
			);
		}
		$order->save();

		self::dispatch( $order, 'order.created' );
	}

	public static function on_status_changed( $order_id, $from, $to, $order = null ): void {
		$order = $order instanceof WC_Order ? $order : self::resolve_order( $order_id );
		if ( ! $order ) {
			return;
		}

		self::dispatch( $order, 'order.status_changed' );
	}

	public static function on_refunded( $order_id, $refund_id ): void {
		$order = self::resolve_order( $order_id );
		if ( ! $order ) {
			return;
		}
		self::dispatch( $order, 'order.refunded' );
	}

	// Saving tracking does not change order status, so the admin form must dispatch a shipment event.
	public static function report_shipment_linked( WC_Order $order ): void {
		self::dispatch( $order, 'order.shipment_linked' );
	}

	private static function dispatch( WC_Order $order, string $event_type ): void {
		if ( ! Settings::is_enabled() ) {
			return;
		}
		// Exclude non-affiliate orders to avoid sharing unrelated customer transactions.
		if ( ! self::is_attributed( $order ) ) {
			return;
		}

		$event_id = self::event_id( $order, $event_type );
		$payload  = PayloadBuilder::build( $order, $event_type, $event_id );

		Transmitter::enqueue( $payload );
	}

	private static function is_attributed( WC_Order $order ): bool {
		return '' !== (string) $order->get_meta( Settings::META_CLICK_ID );
	}

	// Derive the key from order state so repeated dispatches of the same change remain idempotent.
	private static function event_id( WC_Order $order, string $event_type ): string {
		$fingerprint = implode(
			'|',
			[
				$order->get_id(),
				$event_type,
				$order->get_status(),
				(string) $order->get_total(),
				(string) $order->get_total_refunded(),
				// Include tracking so a corrected URL is not discarded as a duplicate shipment event.
				(string) $order->get_meta( Settings::META_TRACKING_URL ),
			]
		);
		return substr( hash( 'sha256', $fingerprint ), 0, 48 );
	}

	private static function resolve_order( $order_id ): ?WC_Order {
		$order = $order_id instanceof WC_Order ? $order_id : wc_get_order( $order_id );
		return $order instanceof WC_Order ? $order : null;
	}
}
