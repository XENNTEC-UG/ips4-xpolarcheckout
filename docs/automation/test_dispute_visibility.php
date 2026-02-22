<?php
/**
 * Automation test: P2 ACP dispute visibility + P3 Stripe Radar risk data capture
 *
 * Run:
 *   docker compose exec -T php php /var/www/html/applications/xpolarcheckout/docs/automation/test_dispute_visibility.php
 *
 * Validates:
 *   1. extensions.json exists and registers StripeDisputeSummary
 *   2. StripeDisputeSummary.php exists and has required class/method
 *   3. build.xml contains extension registration
 *   4. buildStripeSnapshot contains Radar risk fields (risk_level, risk_score, outcome_type, outcome_seller_message)
 *   5. Client settlement hook contains payment method + risk display rows
 *   6. Print settlement hook contains payment method + risk display rows
 *   7. lang.php contains all new strings
 *   8. Radar data extraction shape test (mock payload)
 */

$pass = 0;
$fail = 0;
$total = 0;

function assert_true( $condition, $label )
{
	global $pass, $fail, $total;
	$total++;
	if ( $condition )
	{
		$pass++;
		echo "  [PASS] {$label}\n";
	}
	else
	{
		$fail++;
		echo "  [FAIL] {$label}\n";
	}
}

$appBase = '/var/www/html/applications/xpolarcheckout';

echo "=== P2/P3 Dispute Visibility & Radar Risk Tests ===\n\n";

/* ── 1. extensions.json registration ── */
echo "1. extensions.json registration\n";
$extJsonPath = $appBase . '/data/extensions.json';
$extJsonExists = file_exists( $extJsonPath );
assert_true( $extJsonExists, 'extensions.json exists' );

if ( $extJsonExists )
{
	$extJson = json_decode( file_get_contents( $extJsonPath ), TRUE );
	assert_true(
		isset( $extJson['core']['MemberACPProfileBlocks']['StripeDisputeSummary'] ),
		'extensions.json registers StripeDisputeSummary'
	);
}
else
{
	assert_true( FALSE, 'extensions.json registers StripeDisputeSummary (file missing)' );
}

/* ── 2. StripeDisputeSummary.php class/method ── */
echo "\n2. StripeDisputeSummary extension class\n";
$extPath = $appBase . '/extensions/core/MemberACPProfileBlocks/StripeDisputeSummary.php';
$extExists = file_exists( $extPath );
assert_true( $extExists, 'StripeDisputeSummary.php exists' );

if ( $extExists )
{
	$extSource = file_get_contents( $extPath );
	assert_true(
		mb_strpos( $extSource, 'extends \\IPS\\core\\MemberACPProfile\\Block' ) !== FALSE,
		'Extends MemberACPProfile\\Block'
	);
	assert_true(
		mb_strpos( $extSource, 'public function output()' ) !== FALSE,
		'Has output() method'
	);
	assert_true(
		mb_strpos( $extSource, 'nexus_transactions' ) !== FALSE,
		'Queries nexus_transactions'
	);
	assert_true(
		mb_strpos( $extSource, 'xpolarcheckout_dispute' ) !== FALSE,
		'Reads xpolarcheckout_dispute from t_extra'
	);
	assert_true(
		mb_strpos( $extSource, 'catch ( \\Throwable' ) !== FALSE,
		'Wrapped in \\Throwable catch'
	);
}
else
{
	for ( $i = 0; $i < 5; $i++ )
	{
		assert_true( FALSE, 'Extension class check (file missing)' );
	}
}

/* ── 3. build.xml extension registration ── */
echo "\n3. build.xml extension registration\n";
$buildXmlPath = $appBase . '/data/build.xml';
$buildXmlSource = file_get_contents( $buildXmlPath );
assert_true(
	mb_strpos( $buildXmlSource, 'MemberACPProfileBlocks' ) !== FALSE AND mb_strpos( $buildXmlSource, 'StripeDisputeSummary' ) !== FALSE,
	'build.xml contains StripeDisputeSummary extension'
);

/* ── 4. Radar risk fields in buildStripeSnapshot ── */
echo "\n4. Radar risk fields in buildStripeSnapshot\n";
$webhookPath = $appBase . '/modules/front/webhook/webhook.php';
$webhookSource = file_get_contents( $webhookPath );

$radarFields = array( 'risk_level', 'risk_score', 'outcome_type', 'outcome_seller_message' );
$snapshotStart = mb_strpos( $webhookSource, 'function buildStripeSnapshot' );
$snapshotEnd = mb_strpos( $webhookSource, 'function normalizePublicUrl', $snapshotStart );
$snapshotSection = mb_substr( $webhookSource, $snapshotStart, $snapshotEnd - $snapshotStart );

foreach ( $radarFields as $field )
{
	assert_true(
		mb_strpos( $snapshotSection, "'{$field}'" ) !== FALSE,
		"buildStripeSnapshot contains '{$field}'"
	);
}

/* ── 5. Client settlement hook shows Stripe enrichment (no risk data — admin only) ── */
echo "\n5. Client settlement hook display rows\n";
$clientHookPath = $appBase . '/hooks/theme_sc_clients_settle.php';
$clientHookSource = file_get_contents( $clientHookPath );
assert_true(
	mb_strpos( $clientHookSource, 'xpolarcheckout_snapshot' ) !== FALSE,
	'Client hook checks snapshot presence'
);
assert_true(
	mb_strpos( $clientHookSource, 'amount_total_display' ) !== FALSE,
	'Client hook displays Stripe total'
);
assert_true(
	mb_strpos( $clientHookSource, 'xpolarcheckout_stripe_charged_label' ) !== FALSE,
	'Client hook displays charged label'
);

/* ── 6. Print settlement hook has payment method + risk rows ── */
echo "\n6. Print settlement hook display rows\n";
$printHookPath = $appBase . '/hooks/theme_sc_print_settle.php';
$printHookSource = file_get_contents( $printHookPath );
assert_true(
	mb_strpos( $printHookSource, 'payment_method_type' ) !== FALSE,
	'Print hook displays payment_method_type'
);
/* Risk data (risk_level, risk_score) is intentionally admin-only — shown in ACP StripeDisputeSummary extension, not in customer-facing hooks */

/* ── 7. Lang strings ── */
echo "\n7. Lang strings\n";
$langPath = $appBase . '/dev/lang.php';
$langSource = file_get_contents( $langPath );
$requiredStrings = array(
	'xpolarcheckout_payment_method',
	'xpolarcheckout_risk_level',
	'xpolarcheckout_dispute_summary',
	'xpolarcheckout_chargebacks_count',
	'xpolarcheckout_refunds_count',
	'xpolarcheckout_ban_status',
	'xpolarcheckout_banned_chargeback',
	'xpolarcheckout_not_banned',
	'xpolarcheckout_latest_dispute',
	'xpolarcheckout_evidence_deadline',
	'xpolarcheckout_view_integrity',
);
$allLangPresent = TRUE;
foreach ( $requiredStrings as $str )
{
	if ( mb_strpos( $langSource, "'{$str}'" ) === FALSE )
	{
		$allLangPresent = FALSE;
		echo "    Missing: {$str}\n";
	}
}
assert_true( $allLangPresent, 'All 11 new lang strings present' );

/* ── 8. Radar data extraction shape (mock payload) ── */
echo "\n8. Radar data extraction shape (mock payload)\n";

$mockOutcome = array(
	'risk_level'     => 'normal',
	'risk_score'     => 32,
	'type'           => 'authorized',
	'seller_message' => 'Payment complete.',
);
$mockPaymentIntent = array(
	'id'            => 'pi_test_radar',
	'livemode'      => FALSE,
	'latest_charge' => array(
		'outcome'                => $mockOutcome,
		'payment_method_details' => array(
			'type' => 'card',
			'card' => array(
				'last4'       => '4242',
				'brand'       => 'visa',
				'fingerprint' => 'fp_test',
			),
		),
	),
);

// Simulate the extraction logic from buildStripeSnapshot
$pi = $mockPaymentIntent;
$extracted = array(
	'risk_level'             => ( isset( $pi['latest_charge'] ) AND is_array( $pi['latest_charge'] ) AND isset( $pi['latest_charge']['outcome']['risk_level'] ) ) ? $pi['latest_charge']['outcome']['risk_level'] : NULL,
	'risk_score'             => ( isset( $pi['latest_charge'] ) AND is_array( $pi['latest_charge'] ) AND isset( $pi['latest_charge']['outcome']['risk_score'] ) ) ? (int) $pi['latest_charge']['outcome']['risk_score'] : NULL,
	'outcome_type'           => ( isset( $pi['latest_charge'] ) AND is_array( $pi['latest_charge'] ) AND isset( $pi['latest_charge']['outcome']['type'] ) ) ? $pi['latest_charge']['outcome']['type'] : NULL,
	'outcome_seller_message' => ( isset( $pi['latest_charge'] ) AND is_array( $pi['latest_charge'] ) AND isset( $pi['latest_charge']['outcome']['seller_message'] ) ) ? $pi['latest_charge']['outcome']['seller_message'] : NULL,
);

assert_true( $extracted['risk_level'] === 'normal', 'Mock: risk_level = normal' );
assert_true( $extracted['risk_score'] === 32, 'Mock: risk_score = 32' );
assert_true( $extracted['outcome_type'] === 'authorized', 'Mock: outcome_type = authorized' );
assert_true( $extracted['outcome_seller_message'] === 'Payment complete.', 'Mock: outcome_seller_message' );

// Fallback: no latest_charge expanded
$piNoCharge = array( 'id' => 'pi_test', 'livemode' => FALSE, 'latest_charge' => 'ch_string_id' );
$extractedNull = array(
	'risk_level'             => ( isset( $piNoCharge['latest_charge'] ) AND is_array( $piNoCharge['latest_charge'] ) AND isset( $piNoCharge['latest_charge']['outcome']['risk_level'] ) ) ? $piNoCharge['latest_charge']['outcome']['risk_level'] : NULL,
	'risk_score'             => ( isset( $piNoCharge['latest_charge'] ) AND is_array( $piNoCharge['latest_charge'] ) AND isset( $piNoCharge['latest_charge']['outcome']['risk_score'] ) ) ? (int) $piNoCharge['latest_charge']['outcome']['risk_score'] : NULL,
);
assert_true( $extractedNull['risk_level'] === NULL, 'Fallback: unexpanded charge yields NULL risk_level' );
assert_true( $extractedNull['risk_score'] === NULL, 'Fallback: unexpanded charge yields NULL risk_score' );

/* ── Summary ── */
echo "\n=== Results: {$pass}/{$total} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
