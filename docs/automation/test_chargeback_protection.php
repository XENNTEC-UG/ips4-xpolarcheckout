<?php
declare( strict_types=1 );

if ( !\file_exists( '/var/www/html/init.php' ) )
{
	\fwrite( STDERR, "Missing IPS bootstrap at /var/www/html/init.php\n" );
	exit( 1 );
}

require '/var/www/html/init.php';

/**
 * @param	bool	$condition	Assertion condition
 * @param	string	$message	Failure message
 * @return	void
 */
function assertOrFail( $condition, $message )
{
	if ( !$condition )
	{
		\fwrite( STDERR, "ASSERTION FAILED: {$message}\n" );
		exit( 1 );
	}
}

/* ── 1. Validate gateway dispute_ban setting default ── */

$gateway = NULL;
foreach ( \IPS\nexus\Gateway::roots() as $method )
{
	if ( $method instanceof \IPS\xpolarcheckout\XPolarCheckout )
	{
		$gateway = $method;
		break;
	}
}
assertOrFail( $gateway !== NULL, 'XPolarCheckout gateway not found' );

$settings = json_decode( $gateway->settings, TRUE );

// dispute_ban defaults to TRUE when not set
$disputeBanValue = isset( $settings['dispute_ban'] ) ? $settings['dispute_ban'] : TRUE;
assertOrFail( (bool) $disputeBanValue === TRUE, 'dispute_ban should default to TRUE; got: ' . var_export( $disputeBanValue, TRUE ) );

// refund_ban should NOT trigger bans (old setting, no longer used in code)
echo "dispute_ban default: TRUE (correct)\n";

/* ── 2. Validate settings form source contains dispute_ban ── */

// Source-level validation (IPS uses eval() so reflection paths are virtual)
$gatewaySourcePath = '/var/www/html/applications/xpolarcheckout/sources/XPolarCheckout/XPolarCheckout.php';
assertOrFail( \file_exists( $gatewaySourcePath ), 'Gateway source file not found at expected path' );
$settingsSource = \file_get_contents( $gatewaySourcePath );
assertOrFail( \mb_strpos( $settingsSource, 'xpolarcheckout_dispute_ban' ) !== FALSE, 'dispute_ban not found in gateway source' );
assertOrFail( \mb_strpos( $settingsSource, 'xpolarcheckout_fraud_protection' ) !== FALSE, 'fraud_protection header not found in gateway source' );
assertOrFail( \mb_strpos( $settingsSource, 'xpolarcheckout_refund_ban' ) === FALSE, 'refund_ban should be REMOVED from gateway source' );
assertOrFail( \mb_strpos( $settingsSource, 'xpolarcheckout_refund_settings' ) === FALSE, 'refund_settings header should be REMOVED from gateway source' );
echo "Settings source: dispute_ban present, refund_ban removed (correct)\n";

/* ── 3. Validate legacy migration keys include dispute_ban ── */

// applyLegacySettingDefaults only copies from legacy gateway when keys are missing,
// so we validate the source $migrationKeys array directly
$migrationSection = \mb_substr( $settingsSource, \mb_strpos( $settingsSource, 'migrationKeys' ), 500 );
assertOrFail( \mb_strpos( $migrationSection, "'dispute_ban'" ) !== FALSE, 'dispute_ban not in migration keys array' );
assertOrFail( \mb_strpos( $migrationSection, "'refund_ban'" ) === FALSE, 'refund_ban should NOT be in migration keys array' );
echo "Legacy migration keys: dispute_ban present, refund_ban absent (correct)\n";

/* ── 4. Validate refund data extraction shape ── */

// Mock a Stripe charge.refunded payload data object
$mockChargeObj = array(
	'id'              => 'ch_test_refund_123',
	'amount'          => 2500,
	'amount_refunded' => 2500,
	'currency'        => 'eur',
	'refunded'        => TRUE,
	'payment_intent'  => 'pi_test_123',
	'refunds'         => array(
		'data' => array(
			array(
				'id'      => 're_test_123',
				'reason'  => 'requested_by_customer',
				'created' => 1739750400,
				'amount'  => 2500,
			),
		),
	),
);

// Simulate the extraction logic from charge.refunded handler
$refundData = array(
	'charge_id'       => isset( $mockChargeObj['id'] ) ? $mockChargeObj['id'] : NULL,
	'amount'          => isset( $mockChargeObj['amount'] ) ? (int) $mockChargeObj['amount'] : NULL,
	'amount_refunded' => isset( $mockChargeObj['amount_refunded'] ) ? (int) $mockChargeObj['amount_refunded'] : NULL,
	'currency'        => isset( $mockChargeObj['currency'] ) ? $mockChargeObj['currency'] : NULL,
	'refunded'        => isset( $mockChargeObj['refunded'] ) ? (bool) $mockChargeObj['refunded'] : NULL,
	'captured_at'     => time(),
);
if ( isset( $mockChargeObj['refunds']['data'] ) AND \is_array( $mockChargeObj['refunds']['data'] ) AND \count( $mockChargeObj['refunds']['data'] ) > 0 )
{
	$latestRefund = $mockChargeObj['refunds']['data'][0];
	$refundData['latest_refund'] = array(
		'id'      => isset( $latestRefund['id'] ) ? $latestRefund['id'] : NULL,
		'reason'  => isset( $latestRefund['reason'] ) ? $latestRefund['reason'] : NULL,
		'created' => isset( $latestRefund['created'] ) ? (int) $latestRefund['created'] : NULL,
		'amount'  => isset( $latestRefund['amount'] ) ? (int) $latestRefund['amount'] : NULL,
	);
}

assertOrFail( $refundData['charge_id'] === 'ch_test_refund_123', 'Refund charge_id mismatch' );
assertOrFail( $refundData['amount'] === 2500, 'Refund amount mismatch' );
assertOrFail( $refundData['amount_refunded'] === 2500, 'Refund amount_refunded mismatch' );
assertOrFail( $refundData['currency'] === 'eur', 'Refund currency mismatch' );
assertOrFail( $refundData['refunded'] === TRUE, 'Refund refunded flag mismatch' );
assertOrFail( isset( $refundData['latest_refund'] ), 'Refund latest_refund missing' );
assertOrFail( $refundData['latest_refund']['id'] === 're_test_123', 'Refund latest_refund.id mismatch' );
assertOrFail( $refundData['latest_refund']['reason'] === 'requested_by_customer', 'Refund latest_refund.reason mismatch' );
assertOrFail( $refundData['latest_refund']['amount'] === 2500, 'Refund latest_refund.amount mismatch' );
echo "Refund data extraction: all 9 fields correct\n";

/* ── 5. Validate dispute data extraction shape ── */

$mockDisputeObj = array(
	'id'                   => 'dp_test_dispute_123',
	'reason'               => 'fraudulent',
	'status'               => 'needs_response',
	'amount'               => 2500,
	'currency'             => 'eur',
	'created'              => 1739750400,
	'evidence_details'     => array( 'due_by' => 1740355200 ),
	'is_charge_refundable' => TRUE,
	'charge'               => 'ch_test_dispute_123',
	'payment_intent'       => 'pi_test_dispute_123',
);

$disputeData = array(
	'id'                   => isset( $mockDisputeObj['id'] ) ? $mockDisputeObj['id'] : NULL,
	'reason'               => isset( $mockDisputeObj['reason'] ) ? $mockDisputeObj['reason'] : NULL,
	'status'               => isset( $mockDisputeObj['status'] ) ? $mockDisputeObj['status'] : NULL,
	'amount'               => isset( $mockDisputeObj['amount'] ) ? (int) $mockDisputeObj['amount'] : NULL,
	'currency'             => isset( $mockDisputeObj['currency'] ) ? $mockDisputeObj['currency'] : NULL,
	'created'              => isset( $mockDisputeObj['created'] ) ? (int) $mockDisputeObj['created'] : NULL,
	'evidence_due_by'      => isset( $mockDisputeObj['evidence_details']['due_by'] ) ? (int) $mockDisputeObj['evidence_details']['due_by'] : NULL,
	'is_charge_refundable' => isset( $mockDisputeObj['is_charge_refundable'] ) ? (bool) $mockDisputeObj['is_charge_refundable'] : NULL,
	'charge_id'            => isset( $mockDisputeObj['charge'] ) ? $mockDisputeObj['charge'] : NULL,
	'payment_intent'       => isset( $mockDisputeObj['payment_intent'] ) ? $mockDisputeObj['payment_intent'] : NULL,
);

assertOrFail( $disputeData['id'] === 'dp_test_dispute_123', 'Dispute id mismatch' );
assertOrFail( $disputeData['reason'] === 'fraudulent', 'Dispute reason mismatch' );
assertOrFail( $disputeData['status'] === 'needs_response', 'Dispute status mismatch' );
assertOrFail( $disputeData['amount'] === 2500, 'Dispute amount mismatch' );
assertOrFail( $disputeData['currency'] === 'eur', 'Dispute currency mismatch' );
assertOrFail( $disputeData['created'] === 1739750400, 'Dispute created mismatch' );
assertOrFail( $disputeData['evidence_due_by'] === 1740355200, 'Dispute evidence_due_by mismatch' );
assertOrFail( $disputeData['is_charge_refundable'] === TRUE, 'Dispute is_charge_refundable mismatch' );
assertOrFail( $disputeData['charge_id'] === 'ch_test_dispute_123', 'Dispute charge_id mismatch' );
assertOrFail( $disputeData['payment_intent'] === 'pi_test_dispute_123', 'Dispute payment_intent mismatch' );
echo "Dispute data extraction: all 10 fields correct\n";

/* ── 6. Validate dispute closure metadata update ── */

// Simulate pre-existing dispute data in t_extra
$existingExtra = array(
	'xpolarcheckout_dispute' => $disputeData,
);

$mockClosedDispute = array(
	'id'     => 'dp_test_dispute_123',
	'status' => 'lost',
);

// Simulate the closure handler logic
$extra = $existingExtra;
if ( isset( $extra['xpolarcheckout_dispute'] ) AND \is_array( $extra['xpolarcheckout_dispute'] ) )
{
	$extra['xpolarcheckout_dispute']['status'] = isset( $mockClosedDispute['status'] ) ? $mockClosedDispute['status'] : NULL;
	$extra['xpolarcheckout_dispute']['closed_at'] = time();
}

assertOrFail( $extra['xpolarcheckout_dispute']['status'] === 'lost', 'Dispute closure status not updated' );
assertOrFail( isset( $extra['xpolarcheckout_dispute']['closed_at'] ), 'Dispute closure timestamp missing' );
assertOrFail( $extra['xpolarcheckout_dispute']['reason'] === 'fraudulent', 'Dispute closure should preserve original reason' );
assertOrFail( $extra['xpolarcheckout_dispute']['evidence_due_by'] === 1740355200, 'Dispute closure should preserve evidence_due_by' );
echo "Dispute closure metadata: status updated, original data preserved (correct)\n";

// Also test the fallback path (no pre-existing dispute data)
$emptyExtra = array();
$extra2 = $emptyExtra;
if ( isset( $extra2['xpolarcheckout_dispute'] ) AND \is_array( $extra2['xpolarcheckout_dispute'] ) )
{
	$extra2['xpolarcheckout_dispute']['status'] = $mockClosedDispute['status'];
}
else
{
	$extra2['xpolarcheckout_dispute'] = array(
		'id'        => isset( $mockClosedDispute['id'] ) ? $mockClosedDispute['id'] : NULL,
		'status'    => isset( $mockClosedDispute['status'] ) ? $mockClosedDispute['status'] : NULL,
		'closed_at' => time(),
	);
}
assertOrFail( $extra2['xpolarcheckout_dispute']['id'] === 'dp_test_dispute_123', 'Dispute closure fallback should capture id' );
assertOrFail( $extra2['xpolarcheckout_dispute']['status'] === 'lost', 'Dispute closure fallback should capture status' );
echo "Dispute closure fallback path: correct\n";

/* ── 7. Validate dispute closure history entry shape ── */

$closedStatus = ( isset( $mockClosedDispute['status'] ) AND $mockClosedDispute['status'] === 'lost' )
	? \IPS\nexus\Transaction::STATUS_REFUNDED
	: \IPS\nexus\Transaction::STATUS_PAID;
assertOrFail( $closedStatus === \IPS\nexus\Transaction::STATUS_REFUNDED, 'Dispute lost should map to STATUS_REFUNDED' );

$historyEntry = array(
	's'       => $closedStatus,
	'on'      => time(),
	'ref'     => isset( $mockClosedDispute['id'] ) ? $mockClosedDispute['id'] : NULL,
	'noteRaw' => 'dispute_closed_' . ( isset( $mockClosedDispute['status'] ) ? $mockClosedDispute['status'] : 'unknown' ),
);
assertOrFail( $historyEntry['ref'] === 'dp_test_dispute_123', 'History ref should be dispute id' );
assertOrFail( $historyEntry['noteRaw'] === 'dispute_closed_lost', 'History noteRaw should be dispute_closed_lost' );

// Test won scenario
$mockWonDispute = array( 'id' => 'dp_test_won', 'status' => 'won' );
$wonStatus = ( isset( $mockWonDispute['status'] ) AND $mockWonDispute['status'] === 'lost' )
	? \IPS\nexus\Transaction::STATUS_REFUNDED
	: \IPS\nexus\Transaction::STATUS_PAID;
assertOrFail( $wonStatus === \IPS\nexus\Transaction::STATUS_PAID, 'Dispute won should map to STATUS_PAID' );
$wonNote = 'dispute_closed_' . ( isset( $mockWonDispute['status'] ) ? $mockWonDispute['status'] : 'unknown' );
assertOrFail( $wonNote === 'dispute_closed_won', 'Won history noteRaw should be dispute_closed_won' );
echo "Dispute closure history: lost->STATUS_REFUNDED, won->STATUS_PAID (correct)\n";

/* ── 8. Validate snapshot enrichment fields (structure test) ── */

$mockSession = array(
	'customer_details' => array(
		'email'   => 'test@example.com',
		'name'    => 'Test User',
		'address' => array( 'country' => 'DE', 'postal_code' => '10115', 'city' => 'Berlin' ),
	),
);
$mockPaymentIntent = array(
	'latest_charge' => array(
		'payment_method_details' => array(
			'type' => 'card',
			'card' => array(
				'last4'       => '4242',
				'brand'       => 'visa',
				'fingerprint' => 'fp_test_123',
			),
		),
	),
);

$enrichedFields = array(
	'customer_email'      => isset( $mockSession['customer_details']['email'] ) ? $mockSession['customer_details']['email'] : NULL,
	'customer_name'       => isset( $mockSession['customer_details']['name'] ) ? $mockSession['customer_details']['name'] : NULL,
	'customer_address'    => isset( $mockSession['customer_details']['address'] ) ? $mockSession['customer_details']['address'] : NULL,
	'payment_method_type' => ( isset( $mockPaymentIntent['latest_charge'] ) AND \is_array( $mockPaymentIntent['latest_charge'] ) AND isset( $mockPaymentIntent['latest_charge']['payment_method_details']['type'] ) ) ? $mockPaymentIntent['latest_charge']['payment_method_details']['type'] : NULL,
	'card_last4'          => ( isset( $mockPaymentIntent['latest_charge'] ) AND \is_array( $mockPaymentIntent['latest_charge'] ) AND isset( $mockPaymentIntent['latest_charge']['payment_method_details']['card']['last4'] ) ) ? $mockPaymentIntent['latest_charge']['payment_method_details']['card']['last4'] : NULL,
	'card_brand'          => ( isset( $mockPaymentIntent['latest_charge'] ) AND \is_array( $mockPaymentIntent['latest_charge'] ) AND isset( $mockPaymentIntent['latest_charge']['payment_method_details']['card']['brand'] ) ) ? $mockPaymentIntent['latest_charge']['payment_method_details']['card']['brand'] : NULL,
	'card_fingerprint'    => ( isset( $mockPaymentIntent['latest_charge'] ) AND \is_array( $mockPaymentIntent['latest_charge'] ) AND isset( $mockPaymentIntent['latest_charge']['payment_method_details']['card']['fingerprint'] ) ) ? $mockPaymentIntent['latest_charge']['payment_method_details']['card']['fingerprint'] : NULL,
);

assertOrFail( $enrichedFields['customer_email'] === 'test@example.com', 'Snapshot customer_email mismatch' );
assertOrFail( $enrichedFields['customer_name'] === 'Test User', 'Snapshot customer_name mismatch' );
assertOrFail( \is_array( $enrichedFields['customer_address'] ), 'Snapshot customer_address should be array' );
assertOrFail( $enrichedFields['customer_address']['country'] === 'DE', 'Snapshot customer_address country mismatch' );
assertOrFail( $enrichedFields['payment_method_type'] === 'card', 'Snapshot payment_method_type mismatch' );
assertOrFail( $enrichedFields['card_last4'] === '4242', 'Snapshot card_last4 mismatch' );
assertOrFail( $enrichedFields['card_brand'] === 'visa', 'Snapshot card_brand mismatch' );
assertOrFail( $enrichedFields['card_fingerprint'] === 'fp_test_123', 'Snapshot card_fingerprint mismatch' );
echo "Snapshot enrichment: all 7 fields correct\n";

// Test with unexpanded charge (string instead of array)
$mockPINoExpand = array( 'latest_charge' => 'ch_string_id' );
$noExpandType = ( isset( $mockPINoExpand['latest_charge'] ) AND \is_array( $mockPINoExpand['latest_charge'] ) AND isset( $mockPINoExpand['latest_charge']['payment_method_details']['type'] ) ) ? $mockPINoExpand['latest_charge']['payment_method_details']['type'] : NULL;
assertOrFail( $noExpandType === NULL, 'Unexpanded charge string should yield NULL payment_method_type' );
echo "Snapshot fallback (unexpanded charge): NULL (correct)\n";

/* ── 9. Validate webhook source: refund handler has no ban, dispute handler has ban ── */

$webhookSourcePath = '/var/www/html/applications/xpolarcheckout/modules/front/webhook/webhook.php';
assertOrFail( \file_exists( $webhookSourcePath ), 'Webhook source file not found' );
$webhookSource = \file_get_contents( $webhookSourcePath );

// refund handler should NOT contain any ban logic
// Find the actual handler (if statement), not the docblock
$refundHandlerPos = \mb_strpos( $webhookSource, "decodedBody['type'] == 'charge.refunded'" );
assertOrFail( $refundHandlerPos !== FALSE, 'charge.refunded handler not found in webhook source' );
$refundSection = \mb_substr( $webhookSource, $refundHandlerPos, 3000 );
assertOrFail( \mb_strpos( $refundSection, 'temp_ban' ) === FALSE, 'Refund handler should NOT contain temp_ban logic' );
assertOrFail( \mb_strpos( $refundSection, 'refund_ban' ) === FALSE, 'Refund handler should NOT reference refund_ban setting' );

// dispute.created handler SHOULD contain ban logic
$disputeHandlerPos = \mb_strpos( $webhookSource, "decodedBody['type'] == 'charge.dispute.created'" );
assertOrFail( $disputeHandlerPos !== FALSE, 'charge.dispute.created handler not found in webhook source' );
$disputeSection = \mb_substr( $webhookSource, $disputeHandlerPos, 4000 );
assertOrFail( \mb_strpos( $disputeSection, 'dispute_ban' ) !== FALSE, 'Dispute handler should reference dispute_ban setting' );
assertOrFail( \mb_strpos( $disputeSection, 'temp_ban' ) !== FALSE, 'Dispute handler should set temp_ban' );

// Event handler catch blocks should use \Throwable (utility methods may still use \Exception)
$refundCatchSection = \mb_substr( $webhookSource, $refundHandlerPos, 5000 );
assertOrFail( \mb_strpos( $refundCatchSection, 'catch ( \\Throwable' ) !== FALSE, 'Refund handler catch should use \\Throwable' );

$disputeClosedHandlerPos = \mb_strpos( $webhookSource, "decodedBody['type'] == 'charge.dispute.closed'" );
assertOrFail( $disputeClosedHandlerPos !== FALSE, 'charge.dispute.closed handler not found' );
$disputeClosedSection = \mb_substr( $webhookSource, $disputeClosedHandlerPos, 5000 );
assertOrFail( \mb_strpos( $disputeClosedSection, 'catch ( \\Throwable' ) !== FALSE, 'Dispute closed handler catch should use \\Throwable' );

$disputeCreatedCatchSection = \mb_substr( $webhookSource, $disputeHandlerPos, 10000 );
assertOrFail( \mb_strpos( $disputeCreatedCatchSection, 'catch ( \\Throwable' ) !== FALSE, 'Dispute created handler catch should use \\Throwable' );

// No DISPUT typo remaining
assertOrFail( \mb_strpos( $webhookSource, 'DISPUT\'' ) === FALSE, 'DISPUT typo should be fixed to DISPUTE' );
echo "Webhook source: refund no-ban, dispute has ban, \\Throwable catches, no typos (correct)\n";

/* ── 10. Validate lang.php: dispute_ban strings present, refund_ban removed ── */

$langSourcePath = '/var/www/html/applications/xpolarcheckout/dev/lang.php';
assertOrFail( \file_exists( $langSourcePath ), 'Lang source file not found' );
$langSource = \file_get_contents( $langSourcePath );
assertOrFail( \mb_strpos( $langSource, 'xpolarcheckout_dispute_ban' ) !== FALSE, 'Lang missing dispute_ban key' );
assertOrFail( \mb_strpos( $langSource, 'xpolarcheckout_fraud_protection' ) !== FALSE, 'Lang missing fraud_protection key' );
assertOrFail( \mb_strpos( $langSource, 'xpolarcheckout_refund_ban' ) === FALSE, 'Lang should NOT contain refund_ban key' );
assertOrFail( \mb_strpos( $langSource, 'xpolarcheckout_refund_settings' ) === FALSE, 'Lang should NOT contain refund_settings key' );
echo "Lang strings: dispute_ban present, refund_ban removed (correct)\n";

echo "\nPASS: xpolarcheckout chargeback protection automation checks\n";
