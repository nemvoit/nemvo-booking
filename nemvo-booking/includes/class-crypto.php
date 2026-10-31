<?php
namespace NemvoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts stored OAuth tokens and signs OAuth state. Keys derive from the
 * WordPress salts, so rotating salts means employees must reconnect Google.
 */
class Crypto {

	private static function key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . 'nemvo-booking', true );
	}

	public static function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}
		$key    = self::key();
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		$mac    = hash_hmac( 'sha256', $iv . $cipher, $key, true );
		return 'v1:' . base64_encode( $iv . $mac . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public static function decrypt( ?string $value ): string {
		if ( ! $value || 0 !== strpos( $value, 'v1:' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $value, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) < 48 ) {
			return '';
		}
		$key    = self::key();
		$iv     = substr( $raw, 0, 16 );
		$mac    = substr( $raw, 16, 32 );
		$cipher = substr( $raw, 48 );
		if ( ! hash_equals( hash_hmac( 'sha256', $iv . $cipher, $key, true ), $mac ) ) {
			return '';
		}
		$plain = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return false === $plain ? '' : $plain;
	}

	public static function sign( array $data ): string {
		$payload = rtrim( strtr( base64_encode( wp_json_encode( $data ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return $payload . '.' . hash_hmac( 'sha256', $payload, wp_salt( 'nonce' ) );
	}

	public static function verify( string $signed ): ?array {
		$parts = explode( '.', $signed );
		if ( 2 !== count( $parts ) || ! hash_equals( hash_hmac( 'sha256', $parts[0], wp_salt( 'nonce' ) ), $parts[1] ) ) {
			return null;
		}
		$data = json_decode( base64_decode( strtr( $parts[0], '-_', '+/' ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( ! is_array( $data ) || empty( $data['exp'] ) || $data['exp'] < time() ) {
			return null;
		}
		return $data;
	}
}
