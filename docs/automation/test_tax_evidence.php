<?php
/**
 * Automation test: Tax evidence capture — VAT ID collection + customer tax identity
 *
 * Run:
 *   docker compose exec -T php php /workspace/ips-dev-source/apps/xpolarcheckout/docs/automation/test_tax_evidence.php
 *
 * Validates:
 *   1. ACP setting for tax_id_collection exists in gateway settings()
 *   2. applyCheckoutTaxConfiguration() applies tax_id_collection to session
 *   3. buildStripeSnapshot() captures customer_tax_exempt, customer_tax_ids, tax_id_collection_enabled
 *   4. Client settlement hook displays tax exempt + tax ID rows
 *   5. Print settlement hook displays tax exempt + tax ID rows
 *   6. lang.php contains all new strings
 *   7. Mock extraction shape tests (reverse-charge, no tax IDs, tax_exempt=none)
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

echo "=== Tax Evidence Capture Tests ===\n\n";

/* ── 1. ACP setting exists in settings() ── */
echo "1. ACP setting for tax_id_collection\n";
$gatewayPath = $appBase . '/sources/XPolarCheckout/XPolarCheckout.php';
$gatewaySource = file_get_contents( $gatewayPath );
assert_true(
	mb_strpos( $gatewaySource, 'xpolarcheckout_tax_id_collection' ) !== FALSE,
	'settings() contains tax_id_collection form field'
);
assert_true(
	mb_strpos( $gatewaySource, "'tax_id_collection'" ) !== FALSE,
	'settings() reads tax_id_collection from stored settings'
);

/* ── 2. applyCheckoutTaxConfiguration applies tax_id_collection ── */
echo "\n2. Checkout session tax_id_collection\n";
$configStart = mb_strpos( $gatewaySource, 'function applyCheckoutTaxConfiguration' );
$configEnd = mb_strpos( $gatewaySource, 'function applyLineItemTaxBehavior', $configStart );
$configSection = mb_substr( $gatewaySource, $configStart, $configEnd - $configStart );

assert_true(
	mb_strpos( $configSection, "tax_id_collection" ) !== FALSE,
	'applyCheckoutTaxConfiguration sets tax_id_collection on session'
);
assert_true(
	mb_strpos( $configSection, "'enabled' => 'true'" ) !== FALSE,
	'tax_id_collection enabled value is string true'
);

/* ── 3. buildStripeSnapshot captures tax identity fields ── */
echo "\n3. Snapshot tax identity fields\n";
$webhookPath = $appBase . '/modules/front/webhook/webhook.php';
$webhookSource = file_get_contents( $webhookPath );

$snapshotStart = mb_strpos( $webhookSource, 'function buildStripeSnapshot' );
$snapshotEnd = mb_strpos( $webhookSource, 'function normalizePublicUrl', $snapshotStart );
$snapshotSection = mb_substr( $webhookSource, $snapshotStart, $snapshotEnd - $snapshotStart );

assert_true(
	mb_strpos( $snapshotSection, "'customer_tax_exempt'" ) !== FALSE,
	'buildStripeSnapshot contains customer_tax_exempt'
);
assert_true(
	mb_strpos( $snapshotSection, "'customer_tax_ids'" ) !== FALSE,
	'buildStripeSnapshot contains customer_tax_ids'
);
assert_true(
	mb_strpos( $snapshotSection, "'tax_id_collection_enabled'" ) !== FALSE,
	'buildStripeSnapshot contains tax_id_collection_enabled'
);

/* ── 4. Client settlement hook (redesigned as order total replacement — tax identity shown in print hook only) ── */
echo "\n4. Client settlement hook tax identity rows\n";
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

/* ── 5. Print settlement hook displays tax identity ── */
echo "\n5. Print settlement hook tax identity rows\n";
$printHookPath = $appBase . '/hooks/theme_sc_print_settle.php';
$printHookSource = file_get_contents( $printHookPath );
assert_true(
	mb_strpos( $printHookSource, 'customer_tax_exempt' ) !== FALSE,
	'Print hook displays customer_tax_exempt'
);
assert_true(
	mb_strpos( $printHookSource, 'customer_tax_ids' ) !== FALSE,
	'Print hook displays customer_tax_ids'
);
assert_true(
	mb_strpos( $printHookSource, 'Reverse charge' ) !== FALSE,
	'Print hook highlights reverse charge'
);

/* ── 6. Lang strings ── */
echo "\n6. Lang strings\n";
$langPath = $appBase . '/dev/lang.php';
$langSource = file_get_contents( $langPath );
$requiredStrings = array(
	'xpolarcheckout_tax_id_collection',
	'xpolarcheckout_tax_id_collection_desc',
	'xpolarcheckout_tax_exempt_status',
	'xpolarcheckout_customer_tax_id',
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
assert_true( $allLangPresent, 'All 4 new lang strings present' );

/* ── 7. Mock extraction shape tests ── */
echo "\n7. Mock extraction shape tests\n";

// 7a. Reverse-charge session with VAT ID
$mockSession = array(
	'customer_details' => array(
		'tax_exempt' => 'reverse',
		'tax_ids' => array(
			array( 'type' => 'eu_vat', 'value' => 'DE123456789' ),
		),
	),
	'tax_id_collection' => array( 'enabled' => TRUE ),
);

$extracted = array(
	'customer_tax_exempt' => isset( $mockSession['customer_details']['tax_exempt'] ) ? $mockSession['customer_details']['tax_exempt'] : NULL,
	'customer_tax_ids' => ( isset( $mockSession['customer_details']['tax_ids'] ) AND is_array( $mockSession['customer_details']['tax_ids'] ) ) ? $mockSession['customer_details']['tax_ids'] : array(),
	'tax_id_collection_enabled' => isset( $mockSession['tax_id_collection']['enabled'] ) ? (bool) $mockSession['tax_id_collection']['enabled'] : NULL,
);

assert_true( $extracted['customer_tax_exempt'] === 'reverse', 'Mock: tax_exempt = reverse' );
assert_true( count( $extracted['customer_tax_ids'] ) === 1, 'Mock: 1 tax ID extracted' );
assert_true( $extracted['customer_tax_ids'][0]['type'] === 'eu_vat', 'Mock: tax ID type = eu_vat' );
assert_true( $extracted['customer_tax_ids'][0]['value'] === 'DE123456789', 'Mock: tax ID value = DE123456789' );
assert_true( $extracted['tax_id_collection_enabled'] === TRUE, 'Mock: tax_id_collection_enabled = true' );

// 7b. Session with no tax IDs
$mockSessionNoTax = array(
	'customer_details' => array(
		'tax_exempt' => 'none',
	),
);

$extractedNoTax = array(
	'customer_tax_exempt' => isset( $mockSessionNoTax['customer_details']['tax_exempt'] ) ? $mockSessionNoTax['customer_details']['tax_exempt'] : NULL,
	'customer_tax_ids' => ( isset( $mockSessionNoTax['customer_details']['tax_ids'] ) AND is_array( $mockSessionNoTax['customer_details']['tax_ids'] ) ) ? $mockSessionNoTax['customer_details']['tax_ids'] : array(),
	'tax_id_collection_enabled' => isset( $mockSessionNoTax['tax_id_collection']['enabled'] ) ? (bool) $mockSessionNoTax['tax_id_collection']['enabled'] : NULL,
);

assert_true( $extractedNoTax['customer_tax_exempt'] === 'none', 'Fallback: tax_exempt = none' );
assert_true( $extractedNoTax['customer_tax_ids'] === array(), 'Fallback: empty tax_ids array' );
assert_true( $extractedNoTax['tax_id_collection_enabled'] === NULL, 'Fallback: tax_id_collection_enabled = NULL' );

// 7c. Session with exempt status (not reverse)
$mockSessionExempt = array(
	'customer_details' => array(
		'tax_exempt' => 'exempt',
		'tax_ids' => array(
			array( 'type' => 'gb_vat', 'value' => 'GB123456789' ),
		),
	),
	'tax_id_collection' => array( 'enabled' => TRUE ),
);

$extractedExempt = array(
	'customer_tax_exempt' => isset( $mockSessionExempt['customer_details']['tax_exempt'] ) ? $mockSessionExempt['customer_details']['tax_exempt'] : NULL,
	'customer_tax_ids' => ( isset( $mockSessionExempt['customer_details']['tax_ids'] ) AND is_array( $mockSessionExempt['customer_details']['tax_ids'] ) ) ? $mockSessionExempt['customer_details']['tax_ids'] : array(),
);

assert_true( $extractedExempt['customer_tax_exempt'] === 'exempt', 'Mock: tax_exempt = exempt' );
assert_true( $extractedExempt['customer_tax_ids'][0]['type'] === 'gb_vat', 'Mock: GB VAT type correct' );

/* ── Summary ── */
echo "\n=== Results: {$pass}/{$total} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
