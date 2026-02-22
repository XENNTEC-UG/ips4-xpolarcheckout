<?php


namespace IPS\xpolarcheckout\modules\front\webhook;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * webhook
 */
class _webhook extends \IPS\Dispatcher\Controller
{
	/**
	 * Execute
	 *
	 * @return	void
	 */
	public function execute()
	{
		parent::execute();
	}

	/**
	 * Stripe webhook dispatcher.
	 *
	 * Event mapping (Stripe event -> IPS action):
	 *   charge.refunded                          -> STATUS_REFUNDED, optional member ban
	 *   charge.dispute.closed (lost)             -> STATUS_REFUNDED
	 *   charge.dispute.closed (won)              -> STATUS_PAID, markPaid()
	 *   charge.dispute.created                   -> STATUS_DISPUTED, markUnpaid(), admin notification
	 *   checkout.session.completed (succeeded)   -> checkFraudRulesAndCapture(), snapshot persist
	 *   checkout.session.completed (processing)  -> STATUS_GATEWAY_PENDING, notification
	 *   checkout.session.async_payment_succeeded -> checkFraudRulesAndCapture()
	 *   checkout.session.async_payment_failed    -> STATUS_REFUSED
	 *
	 * @return	void
	 */
	protected function manage()
	{
		$body = @file_get_contents('php://input');
		$decodedBody = json_decode( $body, TRUE );

		if ( !\is_array( $decodedBody ) OR !isset( $decodedBody['type'] ) )
		{
			$this->logForensicEvent( 'invalid_payload', 400, 'unknown', NULL, $body );
			\IPS\Output::i()->sendOutput( 'INVALID_PAYLOAD', 400 );
			return;
		}
		$eventId = $this->extractWebhookEventId( $decodedBody );

		if ( !isset( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) OR empty( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) )
		{
			$this->logForensicEvent( 'missing_signature', 403, $decodedBody['type'], $eventId, $body );
			\IPS\Output::i()->sendOutput( 'MISSING_SIGNATURE', 403 );
			return;
		}

		/* Phase 0: dispute automation is intentionally disabled for Polar baseline */
		if ( \in_array( $decodedBody['type'], array( 'charge.dispute.closed', 'charge.dispute.created' ), TRUE ) )
		{
			\IPS\Output::i()->sendOutput( 'IGNORED_EVENT', 200 );
			return;
		}

		// charge.refunded — mark transaction refunded, extract refund data
		if( $decodedBody['type'] == 'charge.refunded' )
		{
			try {

				// Resolve transaction first, then use its gateway settings for signature verification
				$trId = \IPS\Db::i()->select( 't_id', 'nexus_transactions', array( 't_gw_id=?', $decodedBody['data']['object']['payment_intent'] ) )->first();
				$transaction = \IPS\nexus\Transaction::load( $trId );
				$settings = json_decode( $transaction->method->settings, TRUE );

				// Check signature against the transaction's own gateway secret
				$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'];
				if ( !$this->checkSignature( $signature, $body, $settings['webhook_secret'], $decodedBody['type'], $eventId ) )
				{
					return;
				}
				if ( $this->isWebhookEventAlreadyProcessed( $transaction, $eventId ) )
				{
					\IPS\Output::i()->sendOutput( 'SUCCESS', 200 );
					return;
				}

				$lockAcquired = $this->acquireTransactionProcessingLock( (int) $transaction->id );
				if ( !$lockAcquired )
				{
					\IPS\Log::log( 'Concurrent webhook processing detected for charge.refunded on transaction #' . (int) $transaction->id . '; skipping duplicate.', 'xpolarcheckout_webhook_lock' );
					\IPS\Output::i()->sendOutput( 'SUCCESS', 200 );
					return;
				}

				try
				{
					// Extract refund details into transaction metadata
					$chargeObj = $decodedBody['data']['object'];
					$refundData = array(
						'charge_id'       => isset( $chargeObj['id'] ) ? $chargeObj['id'] : NULL,
						'amount'          => isset( $chargeObj['amount'] ) ? (int) $chargeObj['amount'] : NULL,
						'amount_refunded' => isset( $chargeObj['amount_refunded'] ) ? (int) $chargeObj['amount_refunded'] : NULL,
						'currency'        => isset( $chargeObj['currency'] ) ? $chargeObj['currency'] : NULL,
						'refunded'        => isset( $chargeObj['refunded'] ) ? (bool) $chargeObj['refunded'] : NULL,
						'captured_at'     => time(),
					);
					if ( isset( $chargeObj['refunds']['data'] ) AND \is_array( $chargeObj['refunds']['data'] ) AND \count( $chargeObj['refunds']['data'] ) > 0 )
					{
						$latestRefund = $chargeObj['refunds']['data'][0];
						$refundData['latest_refund'] = array(
							'id'      => isset( $latestRefund['id'] ) ? $latestRefund['id'] : NULL,
							'reason'  => isset( $latestRefund['reason'] ) ? $latestRefund['reason'] : NULL,
							'created' => isset( $latestRefund['created'] ) ? (int) $latestRefund['created'] : NULL,
							'amount'  => isset( $latestRefund['amount'] ) ? (int) $latestRefund['amount'] : NULL,
						);
					}
					$extra = $transaction->extra;
					$extra['xpolarcheckout_refund'] = $refundData;
					$transaction->extra = $extra;

					$transaction->status = $this->determineRefundTransactionStatus( $chargeObj );
					$transaction->save();
					$this->markWebhookEventProcessed( $transaction, $eventId, $decodedBody['type'] );

					\IPS\Output::i()->sendOutput( 'SUCCESS', 200 );
					return;
				}
				finally
				{
					$this->releaseTransactionProcessingLock( (int) $transaction->id );
				}

			} catch ( \UnderflowException | \OutOfRangeException $e ) {
				\IPS\Output::i()->sendOutput( 'TRANSACTION_NOT_FOUND', 200 );
				return;
			} catch ( \Throwable $e ) {
				\IPS\Log::log( $e, 'xpolarcheckout_webhook' );
				throw new \Exception( 'UNABLE_TO_PROCESS_REFUND' );
			}
		} 

		// charge.dispute.closed — resolve dispute: lost=refunded, won=paid
		if( $decodedBody['type'] == 'charge.dispute.closed' )
		{
			try {

				// Resolve transaction first, then use its gateway settings for signature verification
				$trId = \IPS\Db::i()->select( 't_id', 'nexus_transactions', array( 't_gw_id=?', $decodedBody['data']['object']['payment_intent'] ) )->first();
				$transaction = \IPS\nexus\Transaction::load( $trId );
				$settings = json_decode( $transaction->method->settings, TRUE );

				// Check signature against the transaction's own gateway secret
				$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'];
				if ( !$this->checkSignature( $signature, $body, $settings['webhook_secret'], $decodedBody['type'], $eventId ) )
				{
					return;
				}
				if ( $this->isWebhookEventAlreadyProcessed( $transaction, $eventId ) )
				{
					\IPS\Output::i()->sendOutput( 'SUCCESS', 200 );
					return;
				}

				$lockAcquired = $this->acquireTransactionProcessingLock( (int) $transaction->id );
				if ( !$lockAcquired )
				{
					\IPS\Log::log( 'Concurrent webhook processing detected for charge.dispute.closed on transaction #' . (int) $transaction->id . '; skipping duplicate.', 'xpolarcheckout_webhook_lock' );
					\IPS\Output::i()->sendOutput( 'SUCCESS', 200 );
					return;
				}

				try
				{
					// Update dispute metadata with closure details
					$disputeObj = $decodedBody['data']['object'];
					$extra = $transaction->extra;
					if ( isset( $extra['xpolarcheckout_dispute'] ) AND \is_array( $extra['xpolarcheckout_dispute'] ) )
					{
						$extra['xpolarcheckout_dispute']['status'] = isset( $disputeObj['status'] ) ? $disputeObj['status'] : NULL;
						$extra['xpolarcheckout_dispute']['closed_at'] = time();
					}
					else
					{
						$extra['xpolarcheckout_dispute'] = array(
							'id'        => isset( $disputeObj['id'] ) ? $disputeObj['id'] : NULL,
							'status'    => isset( $disputeObj['status'] ) ? $disputeObj['status'] : NULL,
							'closed_at' => time(),
						);
					}

					// Add history entry for dispute closure
					$closedStatus = ( isset( $disputeObj['status'] ) AND $disputeObj['status'] === 'lost' )
						? \IPS\nexus\Transaction::STATUS_REFUNDED
						: \IPS\nexus\Transaction::STATUS_PAID;
					$extra['history'][] = array(
						's'       => $closedStatus,
						'on'      => time(),
						'ref'     => isset( $disputeObj['id'] ) ? $disputeObj['id'] : NULL,
						'noteRaw' => 'dispute_closed_' . ( isset( $disputeObj['status'] ) ? $disputeObj['status'] : 'unknown' ),
					);
					$transaction->extra = $extra;

					if( $disputeObj['status'] == 'lost' )
					{
						$transaction->status = \IPS\nexus\Transaction::STATUS_REFUNDED;
						$transaction->save();
					}
					elseif( $disputeObj['status'] == 'won' )
					{
						$transaction->status = $transaction::STATUS_PAID;
						$transaction->save();
						if ( !$transaction->invoice->amountToPay()->amount->isGreaterThanZero() )
						{
							$transaction->invoice->markPaid();
						}
					}
					else
					{
						// Unknown dispute closure status — still persist metadata
						$transaction->save();
					}

					$this->markWebhookEventProcessed( $transaction, $eventId, $decodedBody['type'] );
					\IPS\Output::i()->sendOutput( 'SUCCESS', 200 );
					return;
				}
				finally
				{
					$this->releaseTransactionProcessingLock( (int) $transaction->id );
				}

			} catch ( \UnderflowException | \OutOfRangeException $e ) {
				\IPS\Output::i()->sendOutput( 'TRANSACTION_NOT_FOUND', 200 );
				return;
			} catch ( \Throwable $e ) {
				\IPS\Log::log( $e, 'xpolarcheckout_webhook' );
				throw new \Exception( 'UNABLE_TO_PROCESS_DISPUTE' );
			}
		}

		// charge.dispute.created — mark disputed, revoke benefits, notify admin, optional ban
		if( $decodedBody['type'] == 'charge.dispute.created' )
		{
			try {

				// Resolve transaction first, then use its gateway settings for signature verification
				$trId = \IPS\Db::i()->select( 't_id', 'nexus_transactions', array( 't_gw_id=?', $decodedBody['data']['object']['payment_intent'] ) )->first();
				$transaction = \IPS\nexus\Transaction::load( $trId );
				$settings = json_decode( $transaction->method->settings, TRUE );

				// Check signature against the transaction's own gateway secret
				$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'];
				if ( !$this->checkSignature( $signature, $body, $settings['webhook_secret'], $decodedBody['type'], $eventId ) )
				{
					return;
				}
				if ( $this->isWebhookEventAlreadyProcessed( $transaction, $eventId ) )
				{
					\IPS\Output::i()->sendOutput( 'SUCCESS', 200 );
					return;
				}

				$lockAcquired = $this->acquireTransactionProcessingLock( (int) $transaction->id );
				if ( !$lockAcquired )
				{
					\IPS\Log::log( 'Concurrent webhook processing detected for charge.dispute.created on transaction #' . (int) $transaction->id . '; skipping duplicate.', 'xpolarcheckout_webhook_lock' );
					\IPS\Output::i()->sendOutput( 'SUCCESS', 200 );
					return;
				}

				try
				{
					// Extract full dispute data into transaction metadata
					$disputeObj = $decodedBody['data']['object'];
					$transaction->status = \IPS\nexus\Transaction::STATUS_DISPUTED;
					$extra = $transaction->extra;
					$extra['xpolarcheckout_dispute'] = array(
						'id'                   => isset( $disputeObj['id'] ) ? $disputeObj['id'] : NULL,
						'reason'               => isset( $disputeObj['reason'] ) ? $disputeObj['reason'] : NULL,
						'status'               => isset( $disputeObj['status'] ) ? $disputeObj['status'] : NULL,
						'amount'               => isset( $disputeObj['amount'] ) ? (int) $disputeObj['amount'] : NULL,
						'currency'             => isset( $disputeObj['currency'] ) ? $disputeObj['currency'] : NULL,
						'created'              => isset( $disputeObj['created'] ) ? (int) $disputeObj['created'] : NULL,
						'evidence_due_by'      => isset( $disputeObj['evidence_details']['due_by'] ) ? (int) $disputeObj['evidence_details']['due_by'] : NULL,
						'is_charge_refundable' => isset( $disputeObj['is_charge_refundable'] ) ? (bool) $disputeObj['is_charge_refundable'] : NULL,
						'charge_id'            => isset( $disputeObj['charge'] ) ? $disputeObj['charge'] : NULL,
						'payment_intent'       => isset( $disputeObj['payment_intent'] ) ? $disputeObj['payment_intent'] : NULL,
					);
					$extra['history'][] = array( 's' => \IPS\nexus\Transaction::STATUS_DISPUTED, 'on' => $disputeObj['created'], 'ref' => $disputeObj['id'] );
					$transaction->extra = $extra;
					$transaction->save();

					if ( $transaction->member )
					{
						$transaction->member->log( 'transaction', array(
							'type'		=> 'status',
							'status'	=> \IPS\nexus\Transaction::STATUS_DISPUTED,
							'id'		=> $transaction->id
						) );
					}

					// Auto-ban on chargeback if enabled
					if ( !empty( $settings['dispute_ban'] ) AND $transaction->member )
					{
						$transaction->member->temp_ban = -1;
						$transaction->member->save();
						$transaction->member->log( 'transaction', array(
							'type'   => 'dispute_ban',
							'status' => \IPS\nexus\Transaction::STATUS_DISPUTED,
							'id'     => $transaction->id,
							'ref'    => isset( $disputeObj['id'] ) ? $disputeObj['id'] : NULL,
						) );
					}

					/* Mark the invoice as not paid (revoking benefits) */
					$transaction->invoice->markUnpaid( \IPS\nexus\Invoice::STATUS_CANCELED );

					/* Auto-populate dispute evidence as draft (B6).
					   Saves evidence to Stripe WITHOUT submitting — admin reviews and submits
					   manually in the Stripe Dashboard. Best-effort: failure here does not
					   block the dispute workflow. */
					try
					{
						$disputeId = isset( $disputeObj['id'] ) ? $disputeObj['id'] : NULL;
						if ( $disputeId AND !empty( $settings['secret'] ) )
						{
							$snapshot = isset( $extra['xpolarcheckout_snapshot'] ) ? $extra['xpolarcheckout_snapshot'] : array();
							$evidence = array();

							if ( !empty( $snapshot['customer_email'] ) )
							{
								$evidence['customer_email_address'] = $snapshot['customer_email'];
							}
							if ( !empty( $snapshot['customer_name'] ) )
							{
								$evidence['customer_name'] = $snapshot['customer_name'];
							}

							$evidence['product_description'] = \mb_substr( (string) $transaction->invoice->title, 0, 20000 );

							if ( $transaction->date )
							{
								$evidence['service_date'] = \date( 'Y-m-d', $transaction->date->getTimestamp() );
							}

							/* Billing address from checkout snapshot */
							if ( !empty( $snapshot['customer_address'] ) AND \is_array( $snapshot['customer_address'] ) )
							{
								$addr = $snapshot['customer_address'];
								$parts = \array_filter( array(
									isset( $addr['line1'] ) ? $addr['line1'] : '',
									isset( $addr['line2'] ) ? $addr['line2'] : '',
									isset( $addr['city'] ) ? $addr['city'] : '',
									isset( $addr['state'] ) ? $addr['state'] : '',
									isset( $addr['postal_code'] ) ? $addr['postal_code'] : '',
									isset( $addr['country'] ) ? $addr['country'] : '',
								) );
								if ( \count( $parts ) )
								{
									$evidence['billing_address'] = \implode( ', ', $parts );
								}
							}

							/* Customer purchase IP from PaymentIntent metadata */
							$piId = isset( $disputeObj['payment_intent'] ) ? $disputeObj['payment_intent'] : NULL;
							if ( $piId )
							{
								try
								{
									$pi = \IPS\Http\Url::external( 'https://api.stripe.com/v1/payment_intents/' . $piId )
										->request( 20 )
										->setHeaders( array( 'Authorization' => 'Bearer ' . $settings['secret'], 'Stripe-Version' => \IPS\xpolarcheckout\XPolarCheckout::STRIPE_VERSION ) )
										->get()
										->decodeJson();
									if ( isset( $pi['metadata']['customer_ip'] ) AND $pi['metadata']['customer_ip'] !== '' )
									{
										$evidence['customer_purchase_ip'] = $pi['metadata']['customer_ip'];
									}
								}
								catch ( \Exception $piErr ) {}
							}

							/* Supplementary context for uncategorized_text */
							$contextLines = array();
							if ( $transaction->member )
							{
								$contextLines[] = 'IPS Member ID: ' . (string) $transaction->member->member_id;
								if ( $transaction->member->joined )
								{
									$joinedTs = $transaction->member->joined instanceof \IPS\DateTime ? $transaction->member->joined->getTimestamp() : (int) $transaction->member->joined;
									$contextLines[] = 'Account created: ' . \date( 'Y-m-d', $joinedTs );
									$accountAgeDays = (int) ( ( \time() - $joinedTs ) / 86400 );
									$contextLines[] = 'Account age at dispute: ' . $accountAgeDays . ' days';
								}
								if ( $transaction->member->ip_address )
								{
									$contextLines[] = 'Registration IP: ' . (string) $transaction->member->ip_address;
								}
							}
							try
							{
								$prevCount = (int) \IPS\Db::i()->select( 'COUNT(*)', 'nexus_invoices', array( 'i_member=? AND i_status=?', $transaction->member->member_id, 'paid' ) )->first();
								$contextLines[] = 'Previous paid invoices: ' . $prevCount;
							}
							catch ( \Exception $countErr ) {}

							$contextLines[] = 'IPS Invoice ID: ' . (string) $transaction->invoice->id;
							$contextLines[] = 'IPS Transaction ID: ' . (string) $transaction->id;
							$contextLines[] = 'Delivery method: digital (instant access)';

							if ( !empty( $snapshot['risk_level'] ) )
							{
								$contextLines[] = 'Stripe Radar risk level: ' . $snapshot['risk_level'];
							}
							if ( isset( $snapshot['risk_score'] ) AND $snapshot['risk_score'] !== NULL )
							{
								$contextLines[] = 'Stripe Radar risk score: ' . $snapshot['risk_score'];
							}

							$evidence['uncategorized_text'] = \mb_substr( \implode( "\n", $contextLines ), 0, 20000 );

							/* POST as draft — no 'submit' key, admin reviews in Stripe Dashboard */
							\IPS\Http\Url::external( 'https://api.stripe.com/v1/disputes/' . $disputeId )
								->request( 20 )
								->setHeaders( array( 'Authorization' => 'Bearer ' . $settings['secret'], 'Stripe-Version' => \IPS\xpolarcheckout\XPolarCheckout::STRIPE_VERSION ) )
								->post( array( 'evidence' => $evidence ) );
						}
					}
					catch ( \Exception $evidenceErr )
					{
						\IPS\Log::log( $evidenceErr, 'xpolarcheckout_dispute_evidence' );
					}

					/* Send admin notification */
					\IPS\core\AdminNotification::send( 'nexus', 'Transaction', \IPS\nexus\Transaction::STATUS_DISPUTED, TRUE, $transaction );
					$this->markWebhookEventProcessed( $transaction, $eventId, $decodedBody['type'] );
					\IPS\Output::i()->sendOutput( 'SUCCESS', 200 );
					return;
				}
				finally
				{
					$this->releaseTransactionProcessingLock( (int) $transaction->id );
				}

			} catch ( \UnderflowException | \OutOfRangeException $e ) {
				\IPS\Output::i()->sendOutput( 'TRANSACTION_NOT_FOUND', 200 );
				return;
			} catch ( \Throwable $e ) {
				\IPS\Log::log( $e, 'xpolarcheckout_webhook' );
				throw new \Exception( 'UNABLE_TO_PROCESS_DISPUTE' );
			}
		}

		// checkout.session.completed — capture payment, persist Stripe snapshot
		if( $decodedBody['type'] == 'checkout.session.completed' )
		{
			try
			{
				$transaction = \IPS\nexus\Transaction::load( $decodedBody['data']['object']['metadata']['transaction'] );
			}
			catch ( \OutOfRangeException $e )
			{
				// throw new \Exception( 'UNABLE_TO_LOAD_TRANSACTION' );
				\IPS\Output::i()->sendOutput( 'SUCCESS', 200 );	return;
			}

			// Check status
			if ( $transaction->status === \IPS\nexus\Transaction::STATUS_PAID )
			{
				// throw new \Exception( 'ALREADY_GOT_IT' ); 
				\IPS\Output::i()->sendOutput( 'SUCCESS', 200 );	return;
			}

			if( $transaction->status !== \IPS\nexus\Transaction::STATUS_GATEWAY_PENDING AND $transaction->status !== \IPS\nexus\Transaction::STATUS_PENDING )
			{
				// throw new \Exception( 'BAD_STATUS' );
				\IPS\Output::i()->sendOutput( 'SUCCESS', 200 );	return;
			}

			$settings = json_decode( $transaction->method->settings, TRUE );

			// Check signature
			$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'];
			if ( !$this->checkSignature( $signature, $body, $settings['webhook_secret'], $decodedBody['type'], $eventId ) )
			{
				return;
			}
			if ( $this->isWebhookEventAlreadyProcessed( $transaction, $eventId ) )
			{
				\IPS\Output::i()->sendOutput( 'SUCCESS', 200 );
				return;
			}

			$lockAcquired = $this->acquireTransactionProcessingLock( (int) $transaction->id );
			if ( !$lockAcquired )
			{
				\IPS\Log::log( 'Concurrent webhook processing detected for transaction #' . (int) $transaction->id . '; skipping duplicate capture attempt.', 'xpolarcheckout_webhook_lock' );
				\IPS\Output::i()->sendOutput( 'SUCCESS', 200 );
				return;
			}

			try
			{
				// Now we must to check PaymentIntent status (expand latest_charge for payment method details)
				$intent = \IPS\Http\Url::external( 'https://api.stripe.com/v1/payment_intents/' . $decodedBody['data']['object']['payment_intent'] )
					->setQueryString( 'expand[]', 'latest_charge' )
					->request( 20 )
					->setHeaders( array( 'Authorization' => "Bearer " . $settings['secret'], 'Stripe-Version' => \IPS\xpolarcheckout\XPolarCheckout::STRIPE_VERSION ) )
					->get()
					->decodeJson();

				$this->persistStripeSnapshot( $transaction, $decodedBody, $intent, $settings );

				if( $intent['status'] == "succeeded" )
				{
					$transaction->gw_id = $decodedBody['data']['object']['payment_intent'];
					$transaction->save();
					$maxMind = NULL;
					if ( \IPS\Settings::i()->maxmind_key )
					{
						$maxMind = new \IPS\nexus\Fraud\MaxMind\Request;
						$maxMind->setTransaction( $transaction );
					}

					$transaction->checkFraudRulesAndCapture( $maxMind );
				}
				elseif( $intent['status'] == "processing" )
				{
					$transaction->status = \IPS\nexus\Transaction::STATUS_GATEWAY_PENDING;
					$extra = $transaction->extra;
					$extra['history'][] = array( 's' => \IPS\nexus\Transaction::STATUS_GATEWAY_PENDING );
					$transaction->extra = $extra;
					$transaction->save();

					/* Send Notification */
					$transaction->sendNotification();
					\IPS\core\AdminNotification::send( 'nexus', 'Transaction', \IPS\nexus\Transaction::STATUS_WAITING, TRUE, $transaction );
				}
				else
				{
					throw new \Exception( 'UNRECOGNIZED_INTENT_STATUS: ' . $intent['status'] );
				}

				$this->markWebhookEventProcessed( $transaction, $eventId, $decodedBody['type'] );
				\IPS\Output::i()->sendOutput( 'SUCCESS', 200 );	
				return;
			}
			finally
			{
				$this->releaseTransactionProcessingLock( (int) $transaction->id );
			}
		}

		// checkout.session.async_payment_succeeded/failed — async payment method resolution
		if( $decodedBody['type'] == 'checkout.session.async_payment_succeeded' OR $decodedBody['type'] == 'checkout.session.async_payment_failed' )
		{
			try
			{
				$transaction = \IPS\nexus\Transaction::load( $decodedBody['data']['object']['metadata']['transaction'] );
			}
			catch ( \OutOfRangeException $e )
			{
				\IPS\Output::i()->sendOutput( 'TRANSACTION_NOT_FOUND', 200 );
				return;
			}
			if ( $transaction->status === \IPS\nexus\Transaction::STATUS_PAID OR $transaction->status === \IPS\nexus\Transaction::STATUS_REFUSED )
			{
				\IPS\Output::i()->sendOutput( 'SUCCESS', 200 );
				return;
			}
			if ( $transaction->status !== \IPS\nexus\Transaction::STATUS_GATEWAY_PENDING AND $transaction->status !== \IPS\nexus\Transaction::STATUS_PENDING )
			{
				\IPS\Output::i()->sendOutput( 'SUCCESS', 200 );
				return;
			}

			try {

				$settings = json_decode( $transaction->method->settings, TRUE );

				// Check signature
				$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'];
				if ( !$this->checkSignature( $signature, $body, $settings['webhook_secret'], $decodedBody['type'], $eventId ) )
				{
					return;
				}
				if ( $this->isWebhookEventAlreadyProcessed( $transaction, $eventId ) )
				{
					\IPS\Output::i()->sendOutput( 'SUCCESS', 200 );
					return;
				}

				$lockAcquired = $this->acquireTransactionProcessingLock( (int) $transaction->id );
				if ( !$lockAcquired )
				{
					\IPS\Log::log( 'Concurrent webhook processing detected for async event on transaction #' . (int) $transaction->id . '; skipping duplicate resolution attempt.', 'xpolarcheckout_webhook_lock' );
					\IPS\Output::i()->sendOutput( 'SUCCESS', 200 );
					return;
				}

				try
				{
					if( $decodedBody['data']['object']['payment_status'] == "paid" )
					{
						$transaction->gw_id = $decodedBody['data']['object']['payment_intent'];
						$transaction->save();
						$maxMind = NULL;
						if ( \IPS\Settings::i()->maxmind_key )
						{
							$maxMind = new \IPS\nexus\Fraud\MaxMind\Request;
							$maxMind->setTransaction( $transaction );
						}

						$transaction->checkFraudRulesAndCapture( $maxMind );
					}
					elseif( $decodedBody['data']['object']['payment_status'] == "unpaid" )
					{
						$note = 'async_payment_failed';
						$transaction->gw_id = $decodedBody['data']['object']['payment_intent'];
						$transaction->status = $transaction::STATUS_REFUSED;
						$extra = $transaction->extra;
						$extra['history'][] = array( 's' => \IPS\nexus\Transaction::STATUS_REFUSED, 'noteRaw' => $note );
						$transaction->extra = $extra;
						$transaction->save();
					}
					else
					{
						\IPS\Log::log( 'Unexpected payment_status for ' . $decodedBody['type'] . ': ' . ( isset( $decodedBody['data']['object']['payment_status'] ) ? $decodedBody['data']['object']['payment_status'] : 'NULL' ), 'xpolarcheckout_webhook' );
						\IPS\Output::i()->sendOutput( 'SUCCESS', 200 );
						return;
					}

					$this->markWebhookEventProcessed( $transaction, $eventId, $decodedBody['type'] );
					\IPS\Output::i()->sendOutput( 'SUCCESS', 200 );
					return;
				}
				finally
				{
					$this->releaseTransactionProcessingLock( (int) $transaction->id );
				}

			} catch ( \UnderflowException | \OutOfRangeException $e ) {
				\IPS\Output::i()->sendOutput( 'TRANSACTION_NOT_FOUND', 200 );
				return;
			} catch ( \Throwable $e ) {
				\IPS\Log::log( $e, 'xpolarcheckout_webhook' );
				throw $e;
			}
		}

		/* Catch-all: unrecognized event type — verify signature before ACK */
		$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'];
		if ( !$this->verifyCatchAllSignature( $signature, $body ) )
		{
			$this->logForensicEvent( 'invalid_signature', 403, $decodedBody['type'], $eventId, $body );
			\IPS\Output::i()->sendOutput( 'INVALID_SIGNATURE', 403 );
			return;
		}

		\IPS\Log::log(
			'Unhandled Stripe webhook event type: ' . $decodedBody['type']
				. ( $eventId ? ' (event ' . $eventId . ')' : '' ),
			'xpolarcheckout_webhook'
		);
		\IPS\Output::i()->sendOutput( 'EVENT_TYPE_NOT_HANDLED', 200 );
	}

	/**
	 * Extract Stripe webhook event id from payload.
	 *
	 * @param	array	$eventPayload	Decoded webhook payload
	 * @return	string|NULL
	 */
	protected function extractWebhookEventId( array $eventPayload )
	{
		if ( isset( $eventPayload['id'] ) AND \is_string( $eventPayload['id'] ) )
		{
			$eventId = \trim( $eventPayload['id'] );
			if ( $eventId !== '' )
			{
				return $eventId;
			}
		}

		return NULL;
	}

	/**
	 * Check if webhook event was already processed for this transaction.
	 *
	 * @param	\IPS\nexus\Transaction	$transaction	Transaction
	 * @param	string|NULL				$eventId		Stripe event id
	 * @return	bool
	 */
	protected function isWebhookEventAlreadyProcessed( \IPS\nexus\Transaction $transaction, $eventId )
	{
		if ( !\is_string( $eventId ) OR $eventId === '' )
		{
			return FALSE;
		}

		$extra = $transaction->extra;
		return ( \is_array( $extra )
			AND isset( $extra['xpolarcheckout_webhook_events'] )
			AND \is_array( $extra['xpolarcheckout_webhook_events'] )
			AND isset( $extra['xpolarcheckout_webhook_events'][ $eventId ] ) );
	}

	/**
	 * Mark webhook event as processed in transaction extra metadata.
	 *
	 * @param	\IPS\nexus\Transaction	$transaction	Transaction
	 * @param	string|NULL				$eventId		Stripe event id
	 * @param	string|NULL				$eventType		Stripe event type
	 * @return	void
	 */
	protected function markWebhookEventProcessed( \IPS\nexus\Transaction $transaction, $eventId, $eventType )
	{
		if ( !\is_string( $eventId ) OR $eventId === '' )
		{
			return;
		}

		$extra = $transaction->extra;
		if ( !\is_array( $extra ) )
		{
			$extra = array();
		}

		$processedEvents = array();
		if ( isset( $extra['xpolarcheckout_webhook_events'] ) AND \is_array( $extra['xpolarcheckout_webhook_events'] ) )
		{
			$processedEvents = $extra['xpolarcheckout_webhook_events'];
		}

		$processedEvents[ $eventId ] = array(
			'type'			=> \is_string( $eventType ) ? $eventType : NULL,
			'processed_at'	=> time()
		);

		if ( \count( $processedEvents ) > 50 )
		{
			$processedEvents = \array_slice( $processedEvents, -50, NULL, TRUE );
		}

		$extra['xpolarcheckout_webhook_events'] = $processedEvents;
		$transaction->extra = $extra;
		$transaction->save();
	}

	/**
	 * Acquire non-blocking processing lock for a transaction.
	 *
	 * Fail-open design: if lock query fails, returns TRUE so normal payment flow is never blocked.
	 *
	 * @param	int	$transactionId	Transaction ID
	 * @return	bool
	 */
	protected function acquireTransactionProcessingLock( $transactionId )
	{
		$transactionId = (int) $transactionId;
		if ( $transactionId <= 0 )
		{
			return TRUE;
		}

		$lockName = $this->buildTransactionProcessingLockName( $transactionId );
		try
		{
			$result = \IPS\Db::i()->query( "SELECT GET_LOCK('" . \addslashes( $lockName ) . "', 0) AS lock_acquired" );
			$row = $result->fetch_assoc();
			return ( \is_array( $row ) AND isset( $row['lock_acquired'] ) AND (int) $row['lock_acquired'] === 1 );
		}
		catch ( \Throwable $e )
		{
			\IPS\Log::log( $e, 'xpolarcheckout_webhook_lock' );
			return TRUE;
		}
	}

	/**
	 * Release processing lock for a transaction.
	 *
	 * @param	int	$transactionId	Transaction ID
	 * @return	void
	 */
	protected function releaseTransactionProcessingLock( $transactionId )
	{
		$transactionId = (int) $transactionId;
		if ( $transactionId <= 0 )
		{
			return;
		}

		$lockName = $this->buildTransactionProcessingLockName( $transactionId );
		try
		{
			\IPS\Db::i()->query( "SELECT RELEASE_LOCK('" . \addslashes( $lockName ) . "') AS lock_released" );
		}
		catch ( \Throwable $e )
		{
			\IPS\Log::log( $e, 'xpolarcheckout_webhook_lock' );
		}
	}

	/**
	 * Build stable lock name for transaction processing mutex.
	 *
	 * @param	int	$transactionId	Transaction ID
	 * @return	string
	 */
	protected function buildTransactionProcessingLockName( $transactionId )
	{
		return 'xpolarcheckout_tx_' . (string) (int) $transactionId;
	}

	/**
	 * Determine IPS transaction refund status from Stripe charge object.
	 *
	 * @param	array	$chargeObject	Stripe charge payload (`data.object`)
	 * @return	string
	 */
	protected function determineRefundTransactionStatus( array $chargeObject )
	{
		$amount = isset( $chargeObject['amount'] ) ? (int) $chargeObject['amount'] : NULL;
		$amountRefunded = isset( $chargeObject['amount_refunded'] ) ? (int) $chargeObject['amount_refunded'] : NULL;
		$isFullyRefunded = ( isset( $chargeObject['refunded'] ) AND (bool) $chargeObject['refunded'] === TRUE );

		if ( !$isFullyRefunded AND $amount !== NULL AND $amountRefunded !== NULL AND $amount > 0 AND $amountRefunded >= $amount )
		{
			$isFullyRefunded = TRUE;
		}

		if ( $isFullyRefunded )
		{
			return \IPS\nexus\Transaction::STATUS_REFUNDED;
		}

		if ( $amountRefunded !== NULL AND $amountRefunded > 0 )
		{
			return \IPS\nexus\Transaction::STATUS_PART_REFUNDED;
		}

		// Fallback to full refund when Stripe payload omits specific amount fields.
		return \IPS\nexus\Transaction::STATUS_REFUNDED;
	}

	/**
	 * Persist Stripe session/invoice totals to transaction+invoice metadata.
	 *
	 * @param	\IPS\nexus\Transaction	$transaction		Transaction
	 * @param	array					$eventPayload		Decoded Stripe webhook payload
	 * @param	array					$paymentIntent		Decoded Stripe PaymentIntent payload
	 * @param	array					$settings			Gateway settings
	 * @return	void
	 */
	protected function persistStripeSnapshot( \IPS\nexus\Transaction $transaction, array $eventPayload, array $paymentIntent, array $settings )
	{
		$snapshot = $this->buildStripeSnapshot( $eventPayload, $paymentIntent, $settings );
		$snapshot = $this->applyIpsInvoiceTotalComparison( $snapshot, $transaction );
		$extra = $transaction->extra;
		if ( !\is_array( $extra ) )
		{
			$extra = array();
		}
		$extra['xpolarcheckout_snapshot'] = $snapshot;
		$transaction->extra = $extra;
		$transaction->save();

		try
		{
			$invoice = $transaction->invoice;
			$statusExtra = $invoice->status_extra;
			if ( !\is_array( $statusExtra ) )
			{
				$statusExtra = array();
			}

			$statusExtra['xpolarcheckout_snapshot'] = $snapshot;
			$invoice->status_extra = $statusExtra;
			$cleanedNotes = $this->removeLegacyStripeSummaryFromNotes( (string) $invoice->notes );
			if ( $cleanedNotes !== (string) $invoice->notes )
			{
				$invoice->notes = $cleanedNotes;
			}
			$invoice->save();
		}
		catch ( \Exception $e )
		{
			\IPS\Log::log( $e, 'xpolarcheckout_snapshot' );
		}
	}

	/**
	 * Build Stripe tax/amount snapshot from checkout session + invoice payload.
	 *
	 * @param	array	$eventPayload		Decoded Stripe webhook payload
	 * @param	array	$paymentIntent		Decoded Stripe PaymentIntent payload
	 * @param	array	$settings			Gateway settings
	 * @return	array
	 */
	protected function buildStripeSnapshot( array $eventPayload, array $paymentIntent, array $settings )
	{
		$session = isset( $eventPayload['data']['object'] ) ? $eventPayload['data']['object'] : array();
		$currency = isset( $session['currency'] ) ? \mb_strtoupper( (string) $session['currency'] ) : NULL;
		$amountSubtotal = isset( $session['amount_subtotal'] ) ? (int) $session['amount_subtotal'] : NULL;
		$amountTotal = isset( $session['amount_total'] ) ? (int) $session['amount_total'] : NULL;
		$amountTax = isset( $session['total_details']['amount_tax'] ) ? (int) $session['total_details']['amount_tax'] : NULL;
		$stripeInvoice = array();

		if ( !empty( $session['invoice'] ) )
		{
			try
			{
				$stripeInvoice = \IPS\Http\Url::external( 'https://api.stripe.com/v1/invoices/' . $session['invoice'] )
					->request( 20 )
					->setHeaders( array( 'Authorization' => "Bearer " . $settings['secret'], 'Stripe-Version' => \IPS\xpolarcheckout\XPolarCheckout::STRIPE_VERSION ) )
					->get()
					->decodeJson();
			}
			catch ( \Exception $e ) {}
		}

		if ( isset( $stripeInvoice['currency'] ) )
		{
			$currency = \mb_strtoupper( (string) $stripeInvoice['currency'] );
		}
		if ( isset( $stripeInvoice['subtotal'] ) )
		{
			$amountSubtotal = (int) $stripeInvoice['subtotal'];
		}
		if ( isset( $stripeInvoice['total'] ) )
		{
			$amountTotal = (int) $stripeInvoice['total'];
		}
		if ( isset( $stripeInvoice['tax'] ) )
		{
			$amountTax = (int) $stripeInvoice['tax'];
		}
		$customerInvoiceUrl = $this->normalizePublicUrl( ( isset( $stripeInvoice['hosted_invoice_url'] ) AND \is_string( $stripeInvoice['hosted_invoice_url'] ) ) ? $stripeInvoice['hosted_invoice_url'] : NULL );
		$customerInvoicePdfUrl = $this->normalizePublicUrl( ( isset( $stripeInvoice['invoice_pdf'] ) AND \is_string( $stripeInvoice['invoice_pdf'] ) ) ? $stripeInvoice['invoice_pdf'] : NULL );
		$customerReceiptUrl = $this->normalizePublicUrl( $this->resolveStripeReceiptUrl( $paymentIntent, $settings ) );
		$taxSnapshot = $this->buildStripeTaxBreakdown( $stripeInvoice, $currency, $settings );

		$livemode = NULL;
		if ( isset( $stripeInvoice['livemode'] ) )
		{
			$livemode = (bool) $stripeInvoice['livemode'];
		}
		elseif ( isset( $paymentIntent['livemode'] ) )
		{
			$livemode = (bool) $paymentIntent['livemode'];
		}
		$dashboardPrefix = ( $livemode === FALSE ) ? '/test' : '';

		return array(
			'captured_at'			=> time(),
			'captured_at_iso'		=> \date( 'c' ),
			'event_id'				=> isset( $eventPayload['id'] ) ? $eventPayload['id'] : NULL,
			'event_type'			=> isset( $eventPayload['type'] ) ? $eventPayload['type'] : NULL,
			'session_id'			=> isset( $session['id'] ) ? $session['id'] : NULL,
			'payment_intent_id'		=> isset( $paymentIntent['id'] ) ? $paymentIntent['id'] : NULL,
			'invoice_id'			=> isset( $session['invoice'] ) ? $session['invoice'] : NULL,
			'currency'				=> $currency,
			'amount_subtotal_minor'	=> $amountSubtotal,
			'amount_tax_minor'		=> $amountTax,
			'amount_total_minor'	=> $amountTotal,
			'amount_subtotal_display'=> $this->formatStripeAmountMinor( $amountSubtotal, $currency ),
			'amount_tax_display'	=> $this->formatStripeAmountMinor( $amountTax, $currency ),
			'amount_total_display'	=> $this->formatStripeAmountMinor( $amountTotal, $currency ),
			'automatic_tax_enabled'	=> isset( $session['automatic_tax']['enabled'] ) ? (bool) $session['automatic_tax']['enabled'] : NULL,
			'automatic_tax_status'	=> isset( $session['automatic_tax']['status'] ) ? $session['automatic_tax']['status'] : NULL,
			'taxability_reason'		=> isset( $taxSnapshot['taxability_reason'] ) ? $taxSnapshot['taxability_reason'] : NULL,
			'taxability_reasons'	=> isset( $taxSnapshot['taxability_reasons'] ) ? $taxSnapshot['taxability_reasons'] : array(),
			'tax_breakdown'			=> isset( $taxSnapshot['tax_breakdown'] ) ? $taxSnapshot['tax_breakdown'] : array(),
			'livemode'				=> $livemode,
			'customer_invoice_url'	=> $customerInvoiceUrl,
			'customer_invoice_pdf_url'	=> $customerInvoicePdfUrl,
			'customer_receipt_url'	=> $customerReceiptUrl,
			'dashboard_invoice_url'	=> $this->normalizePublicUrl( ( !empty( $session['invoice'] ) ) ? "https://dashboard.stripe.com{$dashboardPrefix}/invoices/{$session['invoice']}" : NULL ),
			'dashboard_payment_url'	=> $this->normalizePublicUrl( ( !empty( $paymentIntent['id'] ) ) ? "https://dashboard.stripe.com{$dashboardPrefix}/payments/{$paymentIntent['id']}" : NULL ),
			// Customer details (evidence for disputes)
			'customer_email'		=> isset( $session['customer_details']['email'] ) ? $session['customer_details']['email'] : NULL,
			'customer_name'			=> isset( $session['customer_details']['name'] ) ? $session['customer_details']['name'] : NULL,
			'customer_address'		=> isset( $session['customer_details']['address'] ) ? $session['customer_details']['address'] : NULL,
			// Customer tax identity (from Stripe Checkout tax_id_collection)
			'customer_tax_exempt'	=> isset( $session['customer_details']['tax_exempt'] ) ? $session['customer_details']['tax_exempt'] : NULL,
			'customer_tax_ids'		=> ( isset( $session['customer_details']['tax_ids'] ) AND \is_array( $session['customer_details']['tax_ids'] ) ) ? $session['customer_details']['tax_ids'] : array(),
			'tax_id_collection_enabled'	=> isset( $session['tax_id_collection']['enabled'] ) ? (bool) $session['tax_id_collection']['enabled'] : NULL,
			// Payment method details (from expanded latest_charge)
			'payment_method_type'	=> ( isset( $paymentIntent['latest_charge'] ) AND \is_array( $paymentIntent['latest_charge'] ) AND isset( $paymentIntent['latest_charge']['payment_method_details']['type'] ) ) ? $paymentIntent['latest_charge']['payment_method_details']['type'] : NULL,
			'card_last4'			=> ( isset( $paymentIntent['latest_charge'] ) AND \is_array( $paymentIntent['latest_charge'] ) AND isset( $paymentIntent['latest_charge']['payment_method_details']['card']['last4'] ) ) ? $paymentIntent['latest_charge']['payment_method_details']['card']['last4'] : NULL,
			'card_brand'			=> ( isset( $paymentIntent['latest_charge'] ) AND \is_array( $paymentIntent['latest_charge'] ) AND isset( $paymentIntent['latest_charge']['payment_method_details']['card']['brand'] ) ) ? $paymentIntent['latest_charge']['payment_method_details']['card']['brand'] : NULL,
			'card_fingerprint'		=> ( isset( $paymentIntent['latest_charge'] ) AND \is_array( $paymentIntent['latest_charge'] ) AND isset( $paymentIntent['latest_charge']['payment_method_details']['card']['fingerprint'] ) ) ? $paymentIntent['latest_charge']['payment_method_details']['card']['fingerprint'] : NULL,
			// Stripe Radar risk assessment (read-only)
			'risk_level'			=> ( isset( $paymentIntent['latest_charge'] ) AND \is_array( $paymentIntent['latest_charge'] ) AND isset( $paymentIntent['latest_charge']['outcome']['risk_level'] ) ) ? $paymentIntent['latest_charge']['outcome']['risk_level'] : NULL,
			'risk_score'			=> ( isset( $paymentIntent['latest_charge'] ) AND \is_array( $paymentIntent['latest_charge'] ) AND isset( $paymentIntent['latest_charge']['outcome']['risk_score'] ) ) ? (int) $paymentIntent['latest_charge']['outcome']['risk_score'] : NULL,
			'outcome_type'			=> ( isset( $paymentIntent['latest_charge'] ) AND \is_array( $paymentIntent['latest_charge'] ) AND isset( $paymentIntent['latest_charge']['outcome']['type'] ) ) ? $paymentIntent['latest_charge']['outcome']['type'] : NULL,
			'outcome_seller_message'=> ( isset( $paymentIntent['latest_charge'] ) AND \is_array( $paymentIntent['latest_charge'] ) AND isset( $paymentIntent['latest_charge']['outcome']['seller_message'] ) ) ? $paymentIntent['latest_charge']['outcome']['seller_message'] : NULL,
		);
	}

	/**
	 * Normalize public URL values persisted into Stripe snapshot payloads.
	 *
	 * @param	string|NULL	$url	Candidate URL
	 * @return	string|NULL
	 */
	protected function normalizePublicUrl( $url )
	{
		if ( !\is_string( $url ) )
		{
			return NULL;
		}

		$url = \trim( $url );
		if ( $url === '' )
		{
			return NULL;
		}

		if ( \filter_var( $url, FILTER_VALIDATE_URL ) === FALSE )
		{
			return NULL;
		}

		$parts = \parse_url( $url );
		if ( !\is_array( $parts ) OR empty( $parts['scheme'] ) OR empty( $parts['host'] ) )
		{
			return NULL;
		}

		$scheme = \mb_strtolower( (string) $parts['scheme'] );
		if ( !\in_array( $scheme, array( 'https', 'http' ), TRUE ) )
		{
			return NULL;
		}

		return $url;
	}

	/**
	 * Build taxability + jurisdiction/rate breakdown from Stripe invoice totals.
	 *
	 * @param	array	$stripeInvoice	Decoded Stripe invoice payload
	 * @param	string|NULL	$currency	Currency code
	 * @param	array	$settings		Gateway settings
	 * @return	array
	 */
	protected function buildStripeTaxBreakdown( array $stripeInvoice, $currency, array $settings )
	{
		$taxEntries = array();
		if ( isset( $stripeInvoice['total_taxes'] ) AND \is_array( $stripeInvoice['total_taxes'] ) )
		{
			$taxEntries = $stripeInvoice['total_taxes'];
		}
		elseif ( isset( $stripeInvoice['total_tax_amounts'] ) AND \is_array( $stripeInvoice['total_tax_amounts'] ) )
		{
			$taxEntries = $stripeInvoice['total_tax_amounts'];
		}

		$reasons = array();
		$rows = array();
		$taxRateCache = array();

		foreach ( $taxEntries as $entry )
		{
			if ( !\is_array( $entry ) )
			{
				continue;
			}

			$taxRateId = NULL;
			if ( isset( $entry['tax_rate_details']['tax_rate'] ) AND \is_string( $entry['tax_rate_details']['tax_rate'] ) )
			{
				$taxRateId = $entry['tax_rate_details']['tax_rate'];
			}
			elseif ( isset( $entry['tax_rate'] ) AND \is_string( $entry['tax_rate'] ) )
			{
				$taxRateId = $entry['tax_rate'];
			}

			$taxabilityReason = NULL;
			if ( isset( $entry['taxability_reason'] ) AND \is_string( $entry['taxability_reason'] ) )
			{
				$trimmedReason = \trim( $entry['taxability_reason'] );
				if ( $trimmedReason !== '' )
				{
					$taxabilityReason = $trimmedReason;
					$reasons[ $trimmedReason ] = TRUE;
				}
			}

			$inclusive = isset( $entry['inclusive'] ) ? (bool) $entry['inclusive'] : NULL;
			$taxBehavior = NULL;
			if ( isset( $entry['tax_behavior'] ) AND \is_string( $entry['tax_behavior'] ) )
			{
				$taxBehavior = $entry['tax_behavior'];
			}
			elseif ( $inclusive !== NULL )
			{
				$taxBehavior = $inclusive ? 'inclusive' : 'exclusive';
			}

			$amountMinor = ( isset( $entry['amount'] ) AND \is_numeric( $entry['amount'] ) ) ? (int) $entry['amount'] : NULL;
			$taxableAmountMinor = ( isset( $entry['taxable_amount'] ) AND \is_numeric( $entry['taxable_amount'] ) ) ? (int) $entry['taxable_amount'] : NULL;
			$taxRateDetails = $this->loadStripeTaxRateDetails( $taxRateId, $settings, $taxRateCache );

			$rows[] = array(
				'tax_rate_id'				=> $taxRateId,
				'taxability_reason'			=> $taxabilityReason,
				'tax_behavior'				=> $taxBehavior,
				'inclusive'					=> $inclusive,
				'amount_minor'				=> $amountMinor,
				'amount_display'			=> $this->formatStripeAmountMinor( $amountMinor, $currency ),
				'taxable_amount_minor'		=> $taxableAmountMinor,
				'taxable_amount_display'	=> $this->formatStripeAmountMinor( $taxableAmountMinor, $currency ),
				'rate_display_name'			=> isset( $taxRateDetails['display_name'] ) ? $taxRateDetails['display_name'] : NULL,
				'rate_percentage'			=> isset( $taxRateDetails['percentage'] ) ? $taxRateDetails['percentage'] : NULL,
				'jurisdiction'				=> isset( $taxRateDetails['jurisdiction'] ) ? $taxRateDetails['jurisdiction'] : NULL,
				'country'					=> isset( $taxRateDetails['country'] ) ? $taxRateDetails['country'] : NULL,
				'state'						=> isset( $taxRateDetails['state'] ) ? $taxRateDetails['state'] : NULL,
			);

			if ( \count( $rows ) >= 25 )
			{
				break;
			}
		}

		$reasonList = \array_keys( $reasons );

		return array(
			'taxability_reason'		=> \count( $reasonList ) ? $reasonList[0] : NULL,
			'taxability_reasons'	=> $reasonList,
			'tax_breakdown'		=> $rows,
		);
	}

	/**
	 * Load Stripe tax rate metadata for jurisdiction and percentage.
	 *
	 * @param	string|NULL	$taxRateId	Stripe tax rate id
	 * @param	array		$settings	Gateway settings
	 * @param	array		$taxRateCache	Local cache by id
	 * @return	array
	 */
	protected function loadStripeTaxRateDetails( $taxRateId, array $settings, array &$taxRateCache )
	{
		if ( !\is_string( $taxRateId ) OR $taxRateId === '' )
		{
			return array();
		}

		if ( isset( $taxRateCache[ $taxRateId ] ) AND \is_array( $taxRateCache[ $taxRateId ] ) )
		{
			return $taxRateCache[ $taxRateId ];
		}

		if ( empty( $settings['secret'] ) )
		{
			$taxRateCache[ $taxRateId ] = array();
			return $taxRateCache[ $taxRateId ];
		}

		$taxRate = array();
		try
		{
			$taxRate = \IPS\Http\Url::external( 'https://api.stripe.com/v1/tax_rates/' . $taxRateId )
				->request( 20 )
				->setHeaders( array( 'Authorization' => "Bearer " . $settings['secret'], 'Stripe-Version' => \IPS\xpolarcheckout\XPolarCheckout::STRIPE_VERSION ) )
				->get()
				->decodeJson();
		}
		catch ( \Exception $e ) {}

		if ( !\is_array( $taxRate ) )
		{
			$taxRate = array();
		}

		$taxRateCache[ $taxRateId ] = array(
			'display_name'	=> ( isset( $taxRate['display_name'] ) AND \is_string( $taxRate['display_name'] ) AND $taxRate['display_name'] !== '' ) ? $taxRate['display_name'] : NULL,
			'jurisdiction'	=> ( isset( $taxRate['jurisdiction'] ) AND \is_string( $taxRate['jurisdiction'] ) AND $taxRate['jurisdiction'] !== '' ) ? $taxRate['jurisdiction'] : NULL,
			'country'		=> ( isset( $taxRate['country'] ) AND \is_string( $taxRate['country'] ) AND $taxRate['country'] !== '' ) ? $taxRate['country'] : NULL,
			'state'			=> ( isset( $taxRate['state'] ) AND \is_string( $taxRate['state'] ) AND $taxRate['state'] !== '' ) ? $taxRate['state'] : NULL,
			'percentage'	=> ( isset( $taxRate['percentage'] ) AND \is_numeric( $taxRate['percentage'] ) ) ? (string) $taxRate['percentage'] : NULL,
		);

		return $taxRateCache[ $taxRateId ];
	}

	/**
	 * Append Stripe-vs-IPS invoice total comparison fields to the snapshot.
	 *
	 * @param	array					$snapshot		Current Stripe snapshot
	 * @param	\IPS\nexus\Transaction	$transaction	Transaction instance
	 * @return	array
	 */
	protected function applyIpsInvoiceTotalComparison( array $snapshot, \IPS\nexus\Transaction $transaction )
	{
		try
		{
			$invoice = $transaction->invoice;
			if ( !$invoice OR !( $invoice->total instanceof \IPS\nexus\Money ) )
			{
				return $snapshot;
			}

			$ipsTotalMinor = $this->moneyToMinorUnit( $invoice->total );
			$ipsCurrency = \mb_strtoupper( (string) $invoice->total->currency );
			$stripeTotalMinor = ( isset( $snapshot['amount_total_minor'] ) AND \is_numeric( $snapshot['amount_total_minor'] ) ) ? (int) $snapshot['amount_total_minor'] : NULL;
			$taxMinor = ( isset( $snapshot['amount_tax_minor'] ) AND \is_numeric( $snapshot['amount_tax_minor'] ) ) ? (int) $snapshot['amount_tax_minor'] : 0;

			$snapshot['ips_invoice_total_minor'] = $ipsTotalMinor;
			$snapshot['ips_invoice_total_display'] = $this->formatStripeAmountMinor( $ipsTotalMinor, $ipsCurrency );

			if ( $stripeTotalMinor !== NULL )
			{
				$differenceMinor = $stripeTotalMinor - $ipsTotalMinor;

				/* Always store the raw difference for informational display */
				$snapshot['total_difference_minor'] = $differenceMinor;
				$snapshot['total_difference_display'] = ( $differenceMinor !== 0 ) ? $this->formatStripeAmountMinor( $differenceMinor, $ipsCurrency ) : NULL;

				/* Check if the difference is fully explained by Stripe Tax */
				$taxExplained = ( $differenceMinor !== 0 AND $differenceMinor === $taxMinor );
				$snapshot['total_difference_tax_explained'] = $taxExplained;

				/* Only flag as mismatch when there is an unexplained difference */
				$snapshot['has_total_mismatch'] = ( $differenceMinor !== 0 AND !$taxExplained );
				$snapshot['total_mismatch_minor'] = $differenceMinor;
				$snapshot['total_mismatch_display'] = ( $differenceMinor !== 0 ) ? $this->formatStripeAmountMinor( $differenceMinor, $ipsCurrency ) : NULL;
			}
			else
			{
				$snapshot['has_total_mismatch'] = NULL;
				$snapshot['total_mismatch_minor'] = NULL;
				$snapshot['total_mismatch_display'] = NULL;
				$snapshot['total_difference_minor'] = NULL;
				$snapshot['total_difference_display'] = NULL;
				$snapshot['total_difference_tax_explained'] = NULL;
			}
		}
		catch ( \Exception $e ) {}

		return $snapshot;
	}

	/**
	 * Convert IPS money object to Stripe-style minor unit integer.
	 *
	 * @param	\IPS\nexus\Money	$money	Money
	 * @return	int
	 */
	protected function moneyToMinorUnit( \IPS\nexus\Money $money )
	{
		$decimals = \IPS\nexus\Money::numberOfDecimalsForCurrency( $money->currency );
		$multiplier = new \IPS\Math\Number( '1' . \str_repeat( '0', $decimals ) );
		$minor = $money->amount->multiply( $multiplier );

		return (int) (string) $minor;
	}

	/**
	 * Resolve Stripe customer-facing receipt URL from PaymentIntent.
	 *
	 * @param	array	$paymentIntent	Decoded Stripe PaymentIntent payload
	 * @param	array	$settings		Gateway settings
	 * @return	string|NULL
	 */
	protected function resolveStripeReceiptUrl( array $paymentIntent, array $settings )
	{
		if ( isset( $paymentIntent['latest_charge']['receipt_url'] ) AND \is_string( $paymentIntent['latest_charge']['receipt_url'] ) AND $paymentIntent['latest_charge']['receipt_url'] !== '' )
		{
			return $paymentIntent['latest_charge']['receipt_url'];
		}

		if ( isset( $paymentIntent['charges']['data'][0]['receipt_url'] ) AND \is_string( $paymentIntent['charges']['data'][0]['receipt_url'] ) AND $paymentIntent['charges']['data'][0]['receipt_url'] !== '' )
		{
			return $paymentIntent['charges']['data'][0]['receipt_url'];
		}

		$chargeId = NULL;
		if ( isset( $paymentIntent['latest_charge'] ) )
		{
			if ( \is_array( $paymentIntent['latest_charge'] ) AND !empty( $paymentIntent['latest_charge']['id'] ) )
			{
				$chargeId = $paymentIntent['latest_charge']['id'];
			}
			elseif ( \is_string( $paymentIntent['latest_charge'] ) AND $paymentIntent['latest_charge'] !== '' )
			{
				$chargeId = $paymentIntent['latest_charge'];
			}
		}
		if ( $chargeId === NULL AND isset( $paymentIntent['charges']['data'][0]['id'] ) AND \is_string( $paymentIntent['charges']['data'][0]['id'] ) AND $paymentIntent['charges']['data'][0]['id'] !== '' )
		{
			$chargeId = $paymentIntent['charges']['data'][0]['id'];
		}
		if ( $chargeId === NULL )
		{
			return NULL;
		}

		try
		{
			$charge = \IPS\Http\Url::external( 'https://api.stripe.com/v1/charges/' . $chargeId )
				->request( 20 )
				->setHeaders( array( 'Authorization' => "Bearer " . $settings['secret'], 'Stripe-Version' => \IPS\xpolarcheckout\XPolarCheckout::STRIPE_VERSION ) )
				->get()
				->decodeJson();

			if ( isset( $charge['receipt_url'] ) AND \is_string( $charge['receipt_url'] ) AND $charge['receipt_url'] !== '' )
			{
				return $charge['receipt_url'];
			}
		}
		catch ( \Exception $e ) {}

		return NULL;
	}

	/**
	 * Remove legacy Stripe settlement block from invoice notes.
	 *
	 * @param	string	$existingNotes	Current invoice notes
	 * @return	string
	 */
	protected function removeLegacyStripeSummaryFromNotes( $existingNotes )
	{
		$existingNotes = \preg_replace( '/\[\[(?:STRIPECHECKOUT|XSTRIPECHECKOUT)_SETTLEMENT_BEGIN\]\].*?\[\[(?:STRIPECHECKOUT|XSTRIPECHECKOUT)_SETTLEMENT_END\]\]\s*/s', '', (string) $existingNotes );
		return \trim( (string) $existingNotes );
	}

	/**
	 * Convert Stripe minor-unit amount to readable string.
	 *
	 * @param	int|float|string	$amountMinor	Amount in Stripe minor units
	 * @param	string|NULL			$currency		Currency code
	 * @return	string|NULL
	 */
	protected function formatStripeAmountMinor( $amountMinor, $currency )
	{
		if ( $currency === NULL OR $amountMinor === NULL OR !\is_numeric( $amountMinor ) )
		{
			return NULL;
		}

		$currency = \mb_strtoupper( (string) $currency );
		$amountMinor = (int) $amountMinor;
		$zeroDecimalCurrencies = array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );
		$isZeroDecimal = \in_array( $currency, $zeroDecimalCurrencies, TRUE );
		$negative = $amountMinor < 0;
		$absolute = \abs( $amountMinor );

		if ( $isZeroDecimal )
		{
			$value = (string) $absolute;
		}
		else
		{
			$major = \intdiv( $absolute, 100 );
			$minor = $absolute % 100;
			$value = $major . '.' . \str_pad( (string) $minor, 2, '0', STR_PAD_LEFT );
		}

		return $currency . ' ' . ( $negative ? '-' : '' ) . $value;
	}
	
	/**
	 * Log a webhook validation failure to the forensics table.
	 *
	 * Best-effort: failures here must never break the webhook response flow.
	 *
	 * @param	string		$failureReason	One of: invalid_payload, missing_signature, invalid_signature, timestamp_too_old
	 * @param	int			$httpStatus		HTTP response status code
	 * @param	string|NULL	$eventType		Stripe event type if parseable
	 * @param	string|NULL	$eventId		Stripe event id if parseable
	 * @param	string|NULL	$body			Raw request body (first 500 chars stored)
	 * @return	void
	 */
	protected function logForensicEvent( $failureReason, $httpStatus, $eventType = NULL, $eventId = NULL, $body = NULL )
	{
		try
		{
			$snippet = NULL;
			if ( \is_string( $body ) && $body !== '' )
			{
				$snippet = \mb_substr( $body, 0, 500 );
			}

			\IPS\Db::i()->insert( 'xpc_webhook_forensics', array(
				'event_type'      => \is_string( $eventType ) ? \mb_substr( $eventType, 0, 64 ) : '',
				'event_id'        => \is_string( $eventId ) ? \mb_substr( $eventId, 0, 64 ) : NULL,
				'failure_reason'  => \mb_substr( (string) $failureReason, 0, 64 ),
				'ip_address'      => \IPS\Request::i()->ipAddress(),
				'http_status'     => (int) $httpStatus,
				'payload_snippet' => $snippet,
				'created_at'      => \time(),
			) );
		}
		catch ( \Throwable $e )
		{
			\IPS\Log::log( $e, 'xpolarcheckout_forensics' );
		}
	}

	protected function checkSignature( $signature, $body, $secret, $eventType = NULL, $eventId = NULL )
	{
		if ( !\is_string( $signature ) OR !\is_string( $body ) OR !\is_string( $secret ) OR $signature === '' OR $secret === '' )
		{
			$this->logForensicEvent( 'invalid_signature', 403, $eventType, $eventId, $body );
			\IPS\Output::i()->sendOutput( 'INVALID_SIGNATURE', 403 );
			return FALSE;
		}

		$timestamp = NULL;
		$v1Signatures = array();
		foreach ( \explode( ',', $signature ) as $rawPart )
		{
			$part = \trim( $rawPart );
			if ( $part === '' )
			{
				continue;
			}

			$pair = \explode( '=', $part, 2 );
			if ( \count( $pair ) !== 2 )
			{
				continue;
			}

			$key = \trim( $pair[0] );
			$value = \trim( $pair[1] );
			if ( $key === 't' AND $timestamp === NULL AND $value !== '' )
			{
				$timestamp = $value;
			}
			elseif ( $key === 'v1' AND $value !== '' )
			{
				$v1Signatures[] = $value;
			}
		}

		if ( $timestamp === NULL OR \count( $v1Signatures ) === 0 )
		{
			$this->logForensicEvent( 'invalid_signature', 403, $eventType, $eventId, $body );
			\IPS\Output::i()->sendOutput( 'INVALID_SIGNATURE', 403 );
			return FALSE;
		}

		/* Reject timestamps older than 10 minutes to prevent replay attacks */
		$tolerance = 600;
		if ( \abs( \time() - (int) $timestamp ) > $tolerance )
		{
			\IPS\Log::log( 'Webhook signature timestamp too old: ' . (int) $timestamp . ' (server time: ' . \time() . ', drift: ' . \abs( \time() - (int) $timestamp ) . 's)', 'xpolarcheckout_webhook' );
			$this->logForensicEvent( 'timestamp_too_old', 403, $eventType, $eventId, $body );
			\IPS\Output::i()->sendOutput( 'TIMESTAMP_TOO_OLD', 403 );
			return FALSE;
		}

		$signed = \hash_hmac( 'sha256', "{$timestamp}.{$body}", $secret );
		foreach ( $v1Signatures as $candidate )
		{
			if ( \hash_equals( $signed, $candidate ) )
			{
				return TRUE;
			}
		}

		$this->logForensicEvent( 'invalid_signature', 403, $eventType, $eventId, $body );
		\IPS\Output::i()->sendOutput( 'INVALID_SIGNATURE', 403 );
		return FALSE;
	}

	/**
	 * Verify Stripe signature for catch-all events using all configured gateway secrets.
	 *
	 * Unlike checkSignature() which has one known secret, this tries every
	 * configured XPolarCheckout paymethod webhook_secret and returns TRUE
	 * if any produces a valid HMAC match.
	 *
	 * @param	string	$signature	Stripe-Signature header value
	 * @param	string	$body		Raw request body
	 * @return	bool
	 */
	protected function verifyCatchAllSignature( $signature, $body )
	{
		if ( !\is_string( $signature ) OR !\is_string( $body ) OR $signature === '' )
		{
			return FALSE;
		}

		$timestamp = NULL;
		$v1Signatures = array();
		foreach ( \explode( ',', $signature ) as $rawPart )
		{
			$part = \trim( $rawPart );
			if ( $part === '' )
			{
				continue;
			}

			$pair = \explode( '=', $part, 2 );
			if ( \count( $pair ) !== 2 )
			{
				continue;
			}

			$key = \trim( $pair[0] );
			$value = \trim( $pair[1] );
			if ( $key === 't' AND $timestamp === NULL AND $value !== '' )
			{
				$timestamp = $value;
			}
			elseif ( $key === 'v1' AND $value !== '' )
			{
				$v1Signatures[] = $value;
			}
		}

		if ( $timestamp === NULL OR \count( $v1Signatures ) === 0 )
		{
			return FALSE;
		}

		if ( \abs( \time() - (int) $timestamp ) > 600 )
		{
			return FALSE;
		}

		try
		{
			$rows = \IPS\Db::i()->select( 'm_settings', 'nexus_paymethods', array( 'm_gateway=?', 'IPS\\xpolarcheckout\\XPolarCheckout' ) );
			foreach ( $rows as $settingsJson )
			{
				$settings = \json_decode( $settingsJson, TRUE );
				if ( !\is_array( $settings ) OR empty( $settings['webhook_secret'] ) )
				{
					continue;
				}

				$signed = \hash_hmac( 'sha256', "{$timestamp}.{$body}", $settings['webhook_secret'] );
				foreach ( $v1Signatures as $candidate )
				{
					if ( \hash_equals( $signed, $candidate ) )
					{
						return TRUE;
					}
				}
			}
		}
		catch ( \Throwable $e )
		{
			\IPS\Log::log( $e, 'xpolarcheckout_webhook' );
		}

		return FALSE;
	}
}
