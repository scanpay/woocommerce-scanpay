<?php

function wc_scanpay_initial_charge( object $client, array $queue, array $settings ): void {
	if ( 'no' === $settings['subscriptions_enabled'] || ! class_exists( 'WC_Subscriptions' ) ) {
		throw new \Exception( 'Initial charge failed: Subscriptions is not active' );
	}
	foreach ( $queue as $order_id => $subid ) {
		scanpay_log( 'info', 'initial charge on order #' . $order_id );
		$wc_order = wc_get_order( $order_id );
		/*
			Retry strategy:
			* Retry every 15 mins until we receive a valid response.
			* Permanent errors (e.g. card expired) should not be retried.
			* Failed charges should be retried after 24 hours with new idem.
			* Only retry for 5 days (?exponential backoff?)
		*/
		$idem = (array) $wc_order->get_meta( WC_SCANPAY_URI_IDEM, true, 'edit' );
		if ( empty( $idem['next'] ) ) {
			$idem = [
				'retries' => 5,
				'key'     => rtrim( base64_encode( random_bytes( 32 ) ) ),
			];
		} elseif ( ! $idem['retries'] || $idem['next'] > time() ) {
			continue; // Skip: no retries left OR not yet time for a retry.
		} elseif ( isset( $idem['err'] ) ) {
			// Retry: 24h and transient error (e.g. insufficient funds)
			$idem['err'] = null;
			$idem['key'] = rtrim( base64_encode( random_bytes( 32 ) ) );
		}
		$idem['next'] = time() + 900;
		$wc_order->add_meta_data( WC_SCANPAY_URI_IDEM, $idem, true );
		$wc_order->save(); // save immediately to reduce risk of races

		$data = [
			'orderid'     => $order_id,
			'items'       => [
				[ 'total' => $wc_order->get_total() . ' ' . $wc_order->get_currency() ],
			],
			'autocapture' => false,
			'billing'     => array_filter(
				[
					'name'    => $wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name(),
					'email'   => $wc_order->get_billing_email(),
					'phone'   => $wc_order->get_billing_phone(),
					'address' => array_filter( [ $wc_order->get_billing_address_1(), $wc_order->get_billing_address_2() ] ),
					'city'    => $wc_order->get_billing_city(),
					'zip'     => $wc_order->get_billing_postcode(),
					'country' => $wc_order->get_billing_country(),
					'state'   => $wc_order->get_billing_state(),
					'company' => $wc_order->get_billing_company(),
				]
			),
		];

		try {
			$res = $client->charge( $subid, $data, [ 'headers' => [ 'Idempotency-Key' => $idem['key'] ] ] );
			// $wc_order->add_meta_data( WC_SCANPAY_URI_TRNID, $res['id'] );
			// $wc_order->delete_meta_data( WC_SCANPAY_URI_REV ); // indicate out of sync.
			$wc_order->payment_complete( $res['id'] );
			$idem['retries'] = 0;
		} catch ( \Exception $e ) {
			$idem['err']     = $e->getMessage();
			$idem['retries'] = $idem['retries'] - 1;
			/*
				TODO: implement error_type: transient/permanent
				when this is enabled in the backend
			*/
			$idem['next'] = time() + 86400; // next retry in 24 hours
			scanpay_log( 'error', 'scanpay client exception: ' . $e->getMessage() );
		}
		$wc_order->add_meta_data( WC_SCANPAY_URI_IDEM, $idem, true );
		$wc_order->save();
	}
}
