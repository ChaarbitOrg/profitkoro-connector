<?php

declare( strict_types=1 );

namespace ProfitKoro\Connector;

use WP_REST_Request;
use WP_REST_Response;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Categories {

	public const REST_ROUTE = '/categories';

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_route' ] );
	}

	public static function register_route(): void {
		register_rest_route(
			Ping::REST_NAMESPACE,
			self::REST_ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle' ],
				// Signed by the platform and checked in handle(), like the ping route.
				'permission_callback' => '__return_true',
			]
		);
	}

	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$refused = Ping::authenticate( $request );
		if ( null !== $refused ) {
			return $refused;
		}

		$terms = get_terms(
			[
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			]
		);
		if ( ! is_array( $terms ) ) {
			return new WP_REST_Response( [ 'status' => 'error', 'detail' => 'Product categories could not be read.' ], 500 );
		}

		$categories = [];
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$categories[] = [
				'id'     => (string) $term->term_id,
				'name'   => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
				'slug'   => $term->slug,
				'parent' => $term->parent ? (string) $term->parent : null,
				'count'  => (int) $term->count,
			];
		}

		return new WP_REST_Response(
			[
				'status'     => 'ok',
				'connector'  => VERSION,
				'categories' => $categories,
			]
		);
	}
}
