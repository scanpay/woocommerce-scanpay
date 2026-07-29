<?php

/**
 * Exact arithmetic on decimal money amounts held as strings. Each public helper validates
 * its inputs, aligns both operands to equal-length digit strings and does elementary
 * addition or subtraction -- no float rounding, and no BCMath extension.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

/**
 * Optional minus, integer digits, optional fraction. Whitespace, plus signs and exponent
 * notation are rejected because the digit helpers cannot process them. The D modifier
 * anchors `$` at the absolute end, or an amount ending in a newline would pass.
 */
function wc_scanpay_is_money( string $s ): bool {
	return 1 === preg_match( '/^-?[0-9]+(\.[0-9]+)?$/D', $s );
}

/**
 * Align two money amounts for digit-by-digit arithmetic: `123.4` and `56.78` become
 * `12340` and `05678`.
 *
 * @internal
 *
 * @return array{a: string, b: string, as: bool, bs: bool, il: int, fl: int}
 * @throws \InvalidArgumentException If either amount is invalid.
 */
function wc_scanpay_dighomogenize( string $a, string $b ): array {
	if ( ! wc_scanpay_is_money( $a ) || ! wc_scanpay_is_money( $b ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
		throw new \InvalidArgumentException( "invalid money amount: '$a' or '$b'" );
	}
	$h       = [];
	$h['as'] = ( substr( $a, 0, 1 ) === '-' );
	$h['bs'] = ( substr( $b, 0, 1 ) === '-' );
	$aa      = explode( '.', ( $h['as'] ? substr( $a, 1 ) : $a ) . '.' ); // Appended dot: never fewer than 2 parts.
	$bb      = explode( '.', ( $h['bs'] ? substr( $b, 1 ) : $b ) . '.' );
	$h['il'] = max( strlen( $aa[0] ), strlen( $bb[0] ) );
	$h['fl'] = max( strlen( $aa[1] ), strlen( $bb[1] ) );
	$h['a']  = str_pad( $aa[0], $h['il'], '0', STR_PAD_LEFT ) . str_pad( $aa[1], $h['fl'], '0' );
	$h['b']  = str_pad( $bb[0], $h['il'], '0', STR_PAD_LEFT ) . str_pad( $bb[1], $h['fl'], '0' );
	// '-0' and '-0.00' are zero: drop the sign so comparisons treat them as '0'.
	if ( $h['as'] && '' === ltrim( $h['a'], '0' ) ) {
		$h['as'] = false;
	}
	if ( $h['bs'] && '' === ltrim( $h['b'], '0' ) ) {
		$h['bs'] = false;
	}
	return $h;
}

/**
 * Restore an aligned digit string to a decimal money amount: `012340` with $fl 2 becomes
 * `123.40`. Trailing fractional zeros survive unless the whole fraction is zero, and
 * negative zero normalizes to `0`.
 *
 * @internal
 */
function wc_scanpay_digformat( bool $sign, string $s, int $fl ): string {
	$il = strlen( $s ) - $fl;
	$s  = ltrim( substr( $s, 0, $il ), '0' ) . '.' . substr( $s, $il );
	if ( '.' === $s[0] ) {
		$s = '0' . $s;
	}
	for ($d = strlen( $s ) - 1; $d > 0 && '0' === $s[ $d ]; $d--);
	$s = ( '.' === $s[ $d ] ) ? substr( $s, 0, $d ) : $s;
	return ( $sign && '0' !== $s ) ? '-' . $s : $s; // never '-0'
}

/**
 * Add two equal-length, unsigned digit strings.
 *
 * @internal
 */
function wc_scanpay_digadd( string $a, string $b ): string {
	for ( $s = '', $rem = 0, $i = strlen( $a ) - 1; $i >= 0; $i-- ) {
		$r = intval( $a[ $i ] ) + intval( $b[ $i ] ) + $rem;
		if ( $r >= 10 ) {
			$r  -= 10;
			$rem = 1;
		} else {
			$rem = 0;
		}
		$s[ $i ] = (string) $r;
	}
	return ( $rem > 0 ) ? (string) $rem . $s : $s;
}

/**
 * Subtract one equal-length, unsigned digit string from another; `$a` must be >= `$b`.
 *
 * @internal
 */
function wc_scanpay_digsub( string $a, string $b ): string {
	for ( $s = '', $rem = 0, $i = strlen( $a ) - 1; $i >= 0; $i-- ) {
		if ( $a[ $i ] < $b[ $i ] + $rem ) {
			$s[ $i ] = 10 + $a[ $i ] - ( $b[ $i ] + $rem );
			$rem     = 1;
		} else {
			$s[ $i ] = $a[ $i ] - ( $b[ $i ] + $rem );
			$rem     = 0;
		}
	}
	return $s;
}

/**
 * Add two decimal money amounts.
 *
 * @throws \InvalidArgumentException If either amount is invalid.
 */
function wc_scanpay_addmoney( string $a, string $b ): string {
	$h = wc_scanpay_dighomogenize( $a, $b );
	// Opposite signs make this a subtraction, and digsub() requires a >= b.
	if ( $h['as'] !== $h['bs'] ) {
		if ( strcmp( $h['a'], $h['b'] ) < 0 ) {
			$s       = wc_scanpay_digsub( $h['b'], $h['a'] );
			$h['as'] = ! $h['as'];
		} else {
			$s = wc_scanpay_digsub( $h['a'], $h['b'] );
		}
	} else {
		$s = wc_scanpay_digadd( $h['a'], $h['b'] );
	}
	return wc_scanpay_digformat( $h['as'], $s, $h['fl'] );
}

/**
 * Subtract one decimal money amount from another.
 *
 * @throws \InvalidArgumentException If either amount is invalid.
 */
function wc_scanpay_submoney( string $a, string $b ): string {
	// Validate before negating: stripping the '-' would launder '--5' into a valid '-5'.
	if ( ! wc_scanpay_is_money( $b ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
		throw new \InvalidArgumentException( "invalid money amount: '$b'" );
	}
	return wc_scanpay_addmoney( $a, ( '-' === $b[0] ) ? substr( $b, 1 ) : ( '-' . $b ) );
}

/**
 * Compare two decimal money amounts.
 *
 * @return int Below zero if `$a < $b`, zero if equal, above zero if `$a > $b`.
 * @throws \InvalidArgumentException If either amount is invalid.
 */
function wc_scanpay_cmpmoney( string $a, string $b ): int {
	$h = wc_scanpay_dighomogenize( $a, $b );
	if ( $h['as'] && $h['bs'] ) {
		return strcmp( $h['b'], $h['a'] );
	}
	if ( $h['as'] ) {
		return -1;
	}
	if ( $h['bs'] ) {
		return 1;
	}
	return strcmp( $h['a'], $h['b'] );
}

/**
 * Numeric equality: `1.2` equals `1.20`, and `0` equals `-0.00`.
 *
 * @throws \InvalidArgumentException If either amount is invalid.
 */
function wc_scanpay_money_equals( string $a, string $b ): bool {
	$h = wc_scanpay_dighomogenize( $a, $b );
	return $h['as'] === $h['bs'] && $h['a'] === $h['b'];
}

/**
 * Whether a money amount is zero. The digit scan is only reliable after validation:
 * unsupported strings such as `+0` or ` 0` also hold no non-zero digit.
 *
 * @throws \InvalidArgumentException If the amount is invalid.
 */
function wc_scanpay_is_zero( string $s ): bool {
	if ( ! wc_scanpay_is_money( $s ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
		throw new \InvalidArgumentException( "invalid money amount: '$s'" );
	}
	return false === strpbrk( $s, '123456789' );
}
