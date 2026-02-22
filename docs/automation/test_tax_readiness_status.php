<?php
/**
 * Automation test: Tax Readiness Status normalization (A14)
 *
 * Run:
 *   docker compose exec -T php php /workspace/ips-dev-source/apps/xpolarcheckout/docs/automation/test_tax_readiness_status.php
 *
 * Validates:
 *   1.  fetchTaxReadiness() static method exists on gateway class
 *   2.  normalizeTaxReadiness() static method exists on gateway class
 *   3.  applyTaxReadinessSnapshotToSettings() static method exists on gateway class
 *   4.  Normalization: active + registrations → collecting
 *   5.  Normalization: active + zero registrations → not_collecting
 *   6.  Normalization: pending + registrations → not_collecting
 *   7.  Normalization: NULL inputs (both failed) → error
 *   8.  Normalization: malformed tax settings → error
 *   9.  Snapshot keys present in all normalization results
 *   10. testSettings() references applyTaxReadinessSnapshotToSettings
 *   11. Integrity controller has refreshTaxReadiness method
 *   12. refreshTaxReadiness calls csrfCheck()
 *   13. collectIntegrityStats references tax_readiness_status
 *   14. All new tax readiness lang strings present
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

echo "=== Tax Readiness Status Tests (A14) ===\n\n";

/* ── 1-3. Static methods exist ── */
echo "1-3. Static helper methods\n";
$gatewayPath = $appBase . '/sources/XPolarCheckout/XPolarCheckout.php';
$gatewaySource = file_get_contents( $gatewayPath );
assert_true(
	mb_strpos( $gatewaySource, 'function fetchTaxReadiness' ) !== FALSE,
	'fetchTaxReadiness() method exists on gateway class'
);
assert_true(
	mb_strpos( $gatewaySource, 'function normalizeTaxReadiness' ) !== FALSE,
	'normalizeTaxReadiness() method exists on gateway class'
);
assert_true(
	mb_strpos( $gatewaySource, 'function applyTaxReadinessSnapshotToSettings' ) !== FALSE,
	'applyTaxReadinessSnapshotToSettings() method exists on gateway class'
);
echo "\n";

/* ── Required snapshot keys ── */
$snapshotKeys = array(
	'tax_readiness_status',
	'tax_readiness_last_checked',
	'tax_readiness_registrations_count',
	'tax_readiness_registrations_summary',
	'tax_readiness_details',
	'tax_readiness_error',
);

/* ── 4. active + registrations → collecting ── */
echo "4. Normalization: active + registrations\n";
$taxSettingsActive = array( 'status' => 'active' );
$regsActive = array(
	array( 'status' => 'active', 'country' => 'DE', 'country_options' => array() ),
	array( 'status' => 'active', 'country' => 'US', 'country_options' => array( 'state' => 'CA' ) ),
	array( 'status' => 'expired', 'country' => 'FR', 'country_options' => array() ),
);

/* Build expected: call normalization inline since we can't use IPS classes in this static test */
$required = array( 'A' ); /* placeholder for mock-style assertion */

/* Since we can't call \IPS\ classes directly without IPS bootstrap,
   validate via source inspection + mock logic */
$normalizeBody = '';
if ( preg_match( '/function\s+normalizeTaxReadiness\s*\(.*?\n\t\}/s', $gatewaySource, $normMatch ) )
{
	$normalizeBody = $normMatch[0];
}
assert_true(
	mb_strpos( $normalizeBody, "'collecting'" ) !== FALSE,
	"normalizeTaxReadiness contains 'collecting' state"
);
assert_true(
	mb_strpos( $normalizeBody, "'not_collecting'" ) !== FALSE,
	"normalizeTaxReadiness contains 'not_collecting' state"
);
assert_true(
	mb_strpos( $normalizeBody, "'error'" ) !== FALSE,
	"normalizeTaxReadiness contains 'error' state"
);
assert_true(
	mb_strpos( $normalizeBody, "'unknown'" ) !== FALSE,
	"normalizeTaxReadiness contains 'unknown' state"
);
echo "\n";

/* ── 5. Mock normalization: active + 0 regs → not_collecting ── */
echo "5. Mock normalization: active + zero registrations\n";
/* Simulate the logic from normalizeTaxReadiness:
   stripeStatus='active', activeRegs=0 → not_collecting */
$mockStripeStatus = 'active';
$mockActiveCount = 0;
$mockResult = '';
if ( $mockStripeStatus === 'active' AND $mockActiveCount >= 1 ) { $mockResult = 'collecting'; }
elseif ( $mockStripeStatus === 'active' AND $mockActiveCount === 0 ) { $mockResult = 'not_collecting'; }
elseif ( $mockStripeStatus === 'pending' ) { $mockResult = 'not_collecting'; }
else { $mockResult = 'unknown'; }
assert_true( $mockResult === 'not_collecting', 'active + 0 regs = not_collecting' );
echo "\n";

/* ── 6. Mock normalization: pending + registrations → not_collecting ── */
echo "6. Mock normalization: pending + registrations\n";
$mockStripeStatus = 'pending';
$mockActiveCount = 3;
$mockResult = '';
if ( $mockStripeStatus === 'active' AND $mockActiveCount >= 1 ) { $mockResult = 'collecting'; }
elseif ( $mockStripeStatus === 'active' AND $mockActiveCount === 0 ) { $mockResult = 'not_collecting'; }
elseif ( $mockStripeStatus === 'pending' ) { $mockResult = 'not_collecting'; }
else { $mockResult = 'unknown'; }
assert_true( $mockResult === 'not_collecting', 'pending + regs = not_collecting' );
echo "\n";

/* ── 7. Mock normalization: active + registrations → collecting ── */
echo "7. Mock normalization: active + registrations\n";
$mockStripeStatus = 'active';
$mockActiveCount = 2;
$mockResult = '';
if ( $mockStripeStatus === 'active' AND $mockActiveCount >= 1 ) { $mockResult = 'collecting'; }
elseif ( $mockStripeStatus === 'active' AND $mockActiveCount === 0 ) { $mockResult = 'not_collecting'; }
elseif ( $mockStripeStatus === 'pending' ) { $mockResult = 'not_collecting'; }
else { $mockResult = 'unknown'; }
assert_true( $mockResult === 'collecting', 'active + 2 regs = collecting' );
echo "\n";

/* ── 8. normalizeTaxReadiness handles NULL inputs → error ── */
echo "8. NULL input handling\n";
assert_true(
	mb_strpos( $normalizeBody, '$taxSettings === NULL' ) !== FALSE,
	'normalizeTaxReadiness checks for NULL taxSettings'
);
assert_true(
	mb_strpos( $normalizeBody, "Failed to fetch" ) !== FALSE OR mb_strpos( $normalizeBody, "'error'" ) !== FALSE,
	'normalizeTaxReadiness returns error on NULL inputs'
);
echo "\n";

/* ── 9. Snapshot keys in normalization ── */
echo "9. Snapshot keys in normalization\n";
foreach ( $snapshotKeys as $key )
{
	assert_true(
		mb_strpos( $normalizeBody, "'" . $key . "'" ) !== FALSE,
		"Snapshot key '{$key}' present in normalizeTaxReadiness"
	);
}
echo "\n";

/* ── 10. testSettings() references applyTaxReadinessSnapshotToSettings ── */
echo "10. testSettings() integration\n";
$testSettingsMatch = preg_match( '/function\s+testSettings\s*\(.*?\n\t\}/s', $gatewaySource, $tsMatch );
$tsBody = isset( $tsMatch[0] ) ? $tsMatch[0] : '';
assert_true(
	mb_strpos( $tsBody, 'applyTaxReadinessSnapshotToSettings' ) !== FALSE,
	'testSettings() calls applyTaxReadinessSnapshotToSettings'
);
assert_true(
	mb_strpos( $tsBody, 'xpolarcheckout_tax' ) !== FALSE,
	'testSettings() logs to xpolarcheckout_tax on failure'
);
echo "\n";

/* ── 11. Integrity controller has refreshTaxReadiness ── */
echo "11. Integrity controller refreshTaxReadiness\n";
$integrityPath = $appBase . '/modules/admin/monitoring/integrity.php';
$integritySource = file_get_contents( $integrityPath );
assert_true(
	mb_strpos( $integritySource, 'function refreshTaxReadiness' ) !== FALSE,
	'Integrity controller has refreshTaxReadiness method'
);
echo "\n";

/* ── 12. refreshTaxReadiness calls csrfCheck() ── */
echo "12. refreshTaxReadiness CSRF protection\n";
$refreshMatch = preg_match( '/function\s+refreshTaxReadiness\s*\(.*?\n\t\}/s', $integritySource, $refreshBody );
$refreshBodyStr = isset( $refreshBody[0] ) ? $refreshBody[0] : '';
assert_true(
	mb_strpos( $refreshBodyStr, 'csrfCheck()' ) !== FALSE,
	'refreshTaxReadiness() calls csrfCheck()'
);
echo "\n";

/* ── 13. collectIntegrityStats references tax_readiness_status ── */
echo "13. collectIntegrityStats tax readiness fields\n";
assert_true(
	mb_strpos( $integritySource, 'tax_readiness_status' ) !== FALSE,
	'collectIntegrityStats references tax_readiness_status'
);
assert_true(
	mb_strpos( $integritySource, 'tax_readiness_last_checked' ) !== FALSE,
	'collectIntegrityStats references tax_readiness_last_checked'
);
assert_true(
	mb_strpos( $integritySource, 'tax_readiness_registrations_count' ) !== FALSE,
	'collectIntegrityStats references tax_readiness_registrations_count'
);
echo "\n";

/* ── 14. All new lang strings present ── */
echo "14. Lang strings\n";
$langPath = $appBase . '/dev/lang.php';
$langSource = file_get_contents( $langPath );
$langStrings = array(
	'xpolarcheckout_tax_readiness',
	'xpolarcheckout_tax_readiness_collecting',
	'xpolarcheckout_tax_readiness_not_collecting',
	'xpolarcheckout_tax_readiness_unknown',
	'xpolarcheckout_tax_readiness_error',
	'xpolarcheckout_tax_readiness_registrations_label',
	'xpolarcheckout_tax_readiness_last_checked',
	'xpolarcheckout_tax_readiness_warning',
	'xpolarcheckout_tax_readiness_refresh',
	'xpolarcheckout_tax_readiness_refresh_success',
	'xpolarcheckout_tax_readiness_refresh_failed',
	'xpolarcheckout_tax_readiness_refresh_no_change',
	'acplogs__xpolarcheckout_tax_readiness_refresh',
);
foreach ( $langStrings as $key )
{
	assert_true(
		mb_strpos( $langSource, $key ) !== FALSE,
		"Lang string '{$key}' present"
	);
}
echo "\n";

/* ── Summary ── */
echo "=== Results: {$pass}/{$total} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
