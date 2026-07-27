<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

/**
 * Exact arithmetic for decimal money amounts represented as strings.
 *
 * The public arithmetic helpers validate their inputs, align both operands to
 * equal-length digit strings, and then use elementary addition or subtraction.
 * This avoids floating-point rounding and does not require the BCMath extension.
 */

/**
 * Check whether a string uses the supported decimal money syntax: an optional minus
 * sign, one or more integer digits, and an optional fractional part.
 *
 * Whitespace, plus signs and exponent notation are deliberately rejected because the
 * digit helpers below cannot process them safely. The D modifier anchors `$` at the
 * absolute end of the string, or an amount ending in a newline would be accepted.
 */
function wc_scanpay_is_money( string $s ): bool {
	return 1 === preg_match( '/^-?[0-9]+(\.[0-9]+)?$/D', $s );
}

/**
 * Align two money amounts for digit-by-digit arithmetic and comparison.
 *
 * For example, `123.4` and `56.78` become the equal-length digit strings
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
 * Restore an aligned digit string to a decimal money amount.
 *
 * Leading integer zeros are removed. Fractional trailing zeros are retained
 * unless the entire fractional part is zero, and negative zero is normalized
 * to `0`. For example, `012340` with a fraction length of 2 becomes `123.40`.
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
 * Subtract one equal-length, unsigned digit string from another.
 *
 * The value represented by `$a` must be greater than or equal to `$b`.
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
 * Add two decimal money amounts without floating-point arithmetic.
 *
 * @throws \InvalidArgumentException If either amount is invalid.
 */
function wc_scanpay_addmoney( string $a, string $b ): string {
	$h = wc_scanpay_dighomogenize( $a, $b );
	// Opposite signs make this a subtraction. digsub() requires a >= b, so subtract the
	// smaller magnitude from the larger and take the sign of the larger.
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
	// a - b is a + (-b).
	return wc_scanpay_addmoney( $a, ( '-' === $b[0] ) ? substr( $b, 1 ) : ( '-' . $b ) );
}

/**
 * Compare two decimal money amounts.
 *
 * @return int A value below zero if `$a < $b`, zero if equal, or a value above
 *             zero if `$a > $b`.
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
 * Check two decimal money amounts for numeric equality.
 *
 * Equivalent representations such as `1.2` and `1.20`, or `0` and `-0.00`,
 * compare as equal.
 *
 * @throws \InvalidArgumentException If either amount is invalid.
 */
function wc_scanpay_money_equals( string $a, string $b ): bool {
	$h = wc_scanpay_dighomogenize( $a, $b );
	return $h['as'] === $h['bs'] && $h['a'] === $h['b'];
}

/**
 * Check whether a valid decimal money amount represents zero.
 *
 * The digit scan is only reliable after validation: unsupported strings such
 * as `+0` or ` 0` also contain no non-zero digits.
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
