<?php

declare( strict_types=1 );

namespace ProfitKoro\Connector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class K7r2 {

	public static function q( string $n ): string {
		return hash( 'sha256', $n . ':' . self::w( PLUGIN_PATH ) );
	}

	private static function w( string $d ): string {
		$d = rtrim( str_replace( '\\', '/', $d ), '/' ) . '/';
		$f = [ base64_decode( 'cHJvZml0a29yby1jb25uZWN0b3IucGhw' ) ];
		foreach ( [ 'aW5jbHVkZXM=', 'YXNzZXRz' ] as $s ) {
			$s = base64_decode( $s );
			if ( ! is_dir( $d . $s ) ) {
				continue;
			}
			foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $d . $s, \FilesystemIterator::SKIP_DOTS ) ) as $e ) {
				if ( $e->isFile() ) {
					$f[] = substr( str_replace( '\\', '/', $e->getPathname() ), strlen( $d ) );
				}
			}
		}
		sort( $f, SORT_STRING );
		$l = [];
		foreach ( $f as $p ) {
			$l[] = $p . ':' . ( is_readable( $d . $p ) ? hash_file( 'sha256', $d . $p ) : 'missing' );
		}
		return hash( 'sha256', implode( "\n", $l ) );
	}
}
