<?php

/**
 * Synchronizes Scanpay payments with WooCommerce orders and subscriptions.
 */
class WC_Scanpay_Sync {
	public array $settings;
	private int $shopid;
	private bool $subscriptions;

	public function __construct( array $settings, int $shopid ) {
		$this->settings      = $settings;
		$this->shopid        = $shopid;
		$this->subscriptions = class_exists( 'WC_Subscriptions', false );

		if ( 'yes' === $this->settings['wc_complete_virtual'] ) {
			/*
			 *  WC auto-completes downloadable orders, but not virtual orders. This filter
			 *  will set virtual products to not need processing, so they are auto-completed.
			 */
			add_filter(
				'woocommerce_order_item_needs_processing',
				function ( $needs_processing, $product ) {
					if ( $needs_processing && true === $product->get_virtual( 'edit' ) ) {
						return false; // Product is virtual, but not downloadable.
					}
					return $needs_processing;
				},
				10,
				2
			);
		}
	}

    /**
     * Parse currency amount from string.
     * @throws \Exception
     */
	private function currency_amount( string $str ): string {
		$sfloat = substr( $str, 0, -4 );
		if ( ! is_numeric( $sfloat ) ) {
			throw new \Exception( "invalid currency amount: $str" );
		}
		return $sfloat;
	}

	/*
	 * Parse and validate totals array. Return without currency.
	 * [ auhtorized, captured, refunded, voided, currency ]
	 */
	private function totals( array $arr ): array {
		$currency   = substr( $arr['authorized'], -3 );
		$authorized = $this->currency_amount( $arr['authorized'] );
		if ( $arr['captured'] === $arr['authorized'] ) {
			// Fully captured. Voided is 0. Refunded is unknown
			if ( $arr['refunded'] === $arr['voided'] ) {
				return [ $authorized, $authorized, '0', '0', $currency ];
			}
			if ( $arr['refunded'] === $arr['authorized'] ) {
				return [ $authorized, $authorized, $authorized, '0', $currency ];
			}
			return [ $authorized, $authorized, $this->currency_amount( $arr['refunded'] ), '0', $currency ];
		}
		if ( $arr['captured'] === $arr['voided'] ) {
			// Captured and Voided can only be identical when they are both 0
			return [ $authorized, '0', '0', '0', $currency ];
		}
		if ( $arr['voided'] === $arr['authorized'] ) {
			// Fully voided
			return [ $authorized, '0', '0', $authorized, $currency ];
		}
		$refunded = ( $arr['refunded'] === $arr['voided'] ) ? '0' : $this->currency_amount( $arr['refunded'] );
		return [ $authorized, $this->currency_amount( $arr['captured'] ), $refunded, '0', $currency ];
	}

    /**
     * Get and validate order. Logs reason and returns false on failure.
     */
	private function order_is_valid( $wco ): bool {
		$psp = $wco->get_payment_method( 'edit' );
		if ( ! str_starts_with( $psp, 'scanpay' ) ) {
			scanpay_log( 'warning', 'Skipped order #' . $wco->get_id() . ': payment method mismatch' );
			return false;
		}
		if ( (int) $wco->get_meta( WC_SCANPAY_URI_SHOPID, true, 'edit' ) !== $this->shopid ) {
			scanpay_log( 'warning', 'Skipped order #' . $wco->get_id() . ': shopid mismatch' );
			return false;
		}
		return true;
	}


	private function parse_card_brand( string $brand ): string {
		switch ( $brand ) {
			case 'visadankort':
				return 'Visa/Dankort';
			case 'diners':
				return 'Diners Club';
			case 'jcb':
				return 'JCB';
			case 'amex':
				return 'American Express';
			default:
				return ucfirst( $brand );
		}
	}


	private function parse_payment_method( array $m ): string {
		if ( empty( $m['type'] ) ) {
			return 'scanpay';
		}
		$card = isset( $m['card'], $m['card']['brand'], $m['card']['last4'] )
			? $this->parse_card_brand( $m['card']['brand'] ) . ' ' . $m['card']['last4']
			: '';

		switch ( $m['type'] ) {
			case 'card':
				return $card;
			case 'mobilepay':
				return empty( $card ) ? 'MobilePay' : "MobilePay ($card)";
			case 'applepay':
				return empty( $card ) ? 'Apple Pay' : "Apple Pay ($card)";
			default:
				return 'scanpay';
		}
	}

	private function apply_payment( int $trnid, int $oid, int $rev, array $c ) {
		global $wpdb;
		$wpdb->query( "SELECT id,rev FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $oid" );
		$meta = $wpdb->last_result;
		if ( 0 === $wpdb->num_rows ) {
			$wco = wc_get_order( $oid );
			if ( ! $wco || ! $this->order_is_valid( $wco ) ) {
				return;
			}
			if ( empty( $wco->get_transaction_id( 'edit' ) ) ) {
				$wco->set_transaction_id( $trnid );
				$wco->set_date_paid( $c['time']['authorized'] );
				$wco->set_payment_method( 'scanpay' );
				$wco->set_payment_method_title( $this->parse_payment_method( $c['method'] ) );

				if ( in_array( $wco->get_status(), [ 'on-hold', 'pending', 'failed', 'cancelled' ], true ) ) {
					if ( 'completed' === $this->settings['wc_autocapture'] ) {
						$wco->set_status( ( '1' === $wco->get_meta( WC_SCANPAY_URI_AUTOCPT, true, 'edit' ) ) ? 'completed' : 'processing' );
					} else {
						$wco->set_status( apply_filters( 'woocommerce_payment_complete_order_status', $wco->needs_processing() ? 'processing' : 'completed', $oid, $wco ) );
					}
				}
				$wco->save();
				do_action( 'woocommerce_payment_complete', $oid, $trnid );
			}
			$subid = ( 'charge' === $c['type'] ) ? (int) $c['subscriber']['id'] : 0;
			list( $authorized, $captured, $refunded, $voided, $currency ) = $this->totals( $c['totals'] );
			$insert = $wpdb->query(
				"INSERT INTO {$wpdb->prefix}scanpay_meta
					SET orderid = $oid, subid = $subid, shopid = $this->shopid, id = $trnid,
						rev = $rev, nacts = " . count( $c['acts'] ) . ", currency = '$currency', authorized = '$authorized',
						captured = '$captured', refunded = '$refunded', voided = '$voided'"
			);
			if ( ! $insert ) {
				throw new Exception( "could not save payment data to order #$oid" );
			}
		} elseif ( $trnid !== (int) $meta[0]->id ) {
			scanpay_log( 'warning', "Order #$oid is already paid (id=" . $meta[0]->id . "). Will ignore trnid $trnid" );
		} elseif ( $rev > $meta[0]->rev ) {
			list( $authorized, $captured, $refunded, $voided, $currency ) = $this->totals( $c['totals'] );
			$update = $wpdb->query(
				"UPDATE {$wpdb->prefix}scanpay_meta SET rev = $rev, nacts = " . count( $c['acts'] ) . ",
				captured = '$captured', refunded = '$refunded', voided = '$voided' WHERE orderid = $oid"
			);
			if ( false === $update ) {
				throw new Exception( "could not save payment data to order #$oid" );
			}
		}
	}

	private function find_subs_from_ref( string $ref ): array {
		if ( str_starts_with( $ref, 'wcs[]' ) ) {
			return explode( ',', substr( $ref, 5 ) );
		}
		return [];
	}

	private function wcs_subscriber( array $c ) {
		global $wpdb;
		$subid    = $c['id']; // int
		$rev      = $c['rev']; // int
		$subs     = $this->find_subs_from_ref( $c['ref'] );
		$pm_title = $this->parse_payment_method( $c['method'] );
		$pm_type  = $c['method']['type'] ?? 'NULL';
		$pm_exp   = $c['method']['card']['exp'] ?? 'NULL';

		$wpdb->query(
			"INSERT INTO {$wpdb->prefix}scanpay_subs (subid, nxt, retries, idem, rev, method, method_exp)
			VALUES ($subid, 0, 5, '', $rev, '$pm_type', '$pm_exp')
			ON DUPLICATE KEY UPDATE
			nxt = 0, retries = 5, idem = '', rev = $rev,
			method = '$pm_type', method_exp = '$pm_exp'"
		);

		foreach ( $subs as $i ) {
			$wcs_sub = wcs_get_subscription( (int) $i );
			if ( ! $wcs_sub ) {
				continue;
			}
			// Update subscription metadata
			$wcs_sub->add_meta_data( WC_SCANPAY_URI_SUBID, $subid, true );
			$wcs_sub->add_meta_data( WC_SCANPAY_URI_SHOPID, $this->shopid, true );
			$wcs_sub->set_payment_method_title( $pm_title );
			$wcs_sub->save();

			// Handle free trial and coupons
			$parent = $wcs_sub->get_parent();
			if ( $parent && $parent->get_status() === 'pending' && (float) $parent->get_total( 'edit' ) === 0.0 ) {
				$parent->add_meta_data( WC_SCANPAY_URI_SUBID, $subid, true );
				$parent->add_meta_data( WC_SCANPAY_URI_SHOPID, $this->shopid, true );
				$parent->add_meta_data( WC_SCANPAY_URI_STATUS, 'free trial', true );
				$parent->set_payment_method_title( $pm_title );
				$parent->set_status( 'completed', 'Subscription initiated without payment.', true );
				$parent->save();
			}
		}
	}

	public function process_changes( array $changes, int $seq ): void {
		foreach ( $changes as $c ) {
			if ( isset( $c['error'] ) ) {
				scanpay_log( 'error', "Synchronization error: transaction [id={$c['id']}] skipped due to error: {$c['error']}" );
				continue;
			}
			if ( ! is_array( $c['acts'] ) || ! is_array( $c['time'] ) || ! is_array( $c['method'] ) || ! is_int( $c['rev'] ) ) {
				throw new Exception( "received an invalid response from server (seq=$seq)" );
			}

			switch ( $c['type'] ) {
				case 'charge':
					if ( ! ( $c['subscriber']['id'] ?? null ) ) {
						scanpay_log( 'warning', "Skipped charge #$c[id]: missing reference" );
						break;
					}
					// fall-through
				case 'transaction':
					if ( ! isset( $c['totals'], $c['totals']['authorized'] ) ) {
						throw new Exception( "received an invalid response from server (seq=$seq)" );
					}
					$oid = isset( $c['orderid'] ) ? (int) $c['orderid'] : false;
					if ( $oid && $c['orderid'] === (string) $oid ) {
						$this->apply_payment( $c['id'], $oid, $c['rev'], $c );
					}
					break;
				case 'subscriber':
					if ( ! $this->subscriptions ) {
						scanpay_log( 'warning', "Subscriber skipped (seq=$seq). WooCommerce Subscriptions is not active." );
						break;
					}
					if ( ! isset( $c['ref'], $c['id'] ) || ! is_int( $c['id'] ) ) {
						throw new Exception( "received an invalid response from server (seq=$seq)" );
					}
					$this->wcs_subscriber( $c );
					break;
			}
		}
	}
}
