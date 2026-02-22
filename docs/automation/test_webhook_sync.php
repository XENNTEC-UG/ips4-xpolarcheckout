<?php
/**
 * Automation test: Webhook endpoint sync & drift detection (A12)
 *
 * Run:
 *   docker compose exec -T php php /workspace/ips-dev-source/apps/xpolarcheckout/docs/automation/test_webhook_sync.php
 *
 * Validates:
 *   1.  REQUIRED_WEBHOOK_EVENTS constant exists on gateway class with 6 events
 *   2.  All 6 specific event strings present in the constant
 *   3.  testSettings() references REQUIRED_WEBHOOK_EVENTS (not inline array)
 *   4.  testSettings() stores webhook_endpoint_id
 *   5.  fetchWebhookEndpoint() method exists on gateway class
 *   6.  syncWebhookEvents() method exists on gateway class
 *   7.  Replay task references gateway constant (not a local constant)
 *   8.  Integrity controller has syncEvents method
 *   9.  syncEvents calls csrfCheck()
 *   10. collectIntegrityStats references webhook_events_missing and webhook_events_extra
 *   11. All new lang strings present
 *   12. Mock drift logic: required=[A,B,C] + stripe=[B,C,D] → missing=[A], extra=[D]
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

echo "=== Webhook Endpoint Sync & Drift Detection Tests (A12) ===\n\n";

/* ── 1. REQUIRED_WEBHOOK_EVENTS constant exists with 6 events ── */
echo "1. REQUIRED_WEBHOOK_EVENTS constant\n";
$gatewayPath = $appBase . '/sources/XPolarCheckout/XPolarCheckout.php';
$gatewaySource = file_get_contents( $gatewayPath );
assert_true(
	mb_strpos( $gatewaySource, 'REQUIRED_WEBHOOK_EVENTS' ) !== FALSE,
	'REQUIRED_WEBHOOK_EVENTS constant exists in gateway class'
);

/* Extract constant array via regex */
$hasConstArray = preg_match( '/const\s+REQUIRED_WEBHOOK_EVENTS\s*=\s*array\s*\((.*?)\)/s', $gatewaySource, $constMatch );
assert_true(
	$hasConstArray === 1,
	'REQUIRED_WEBHOOK_EVENTS is defined as a const array'
);

$constBody = isset( $constMatch[1] ) ? $constMatch[1] : '';
$eventCount = preg_match_all( "/'/", $constBody );
$eventCount = intdiv( $eventCount, 2 ); /* each event string has 2 quote marks */
assert_true(
	$eventCount === 6,
	'Constant contains exactly 6 event strings'
);
echo "\n";

/* ── 2. All 6 specific event strings ── */
echo "2. Required event strings\n";
$requiredEvents = array(
	'charge.dispute.closed',
	'charge.dispute.created',
	'charge.refunded',
	'checkout.session.completed',
	'checkout.session.async_payment_succeeded',
	'checkout.session.async_payment_failed',
);
foreach ( $requiredEvents as $event )
{
	assert_true(
		mb_strpos( $constBody, $event ) !== FALSE,
		"Event '{$event}' present in constant"
	);
}
echo "\n";

/* ── 3. testSettings() references REQUIRED_WEBHOOK_EVENTS ── */
echo "3. testSettings() uses constant\n";
$testSettingsMatch = preg_match( '/function\s+testSettings\s*\(.*?\n\t\}/s', $gatewaySource, $tsMatch );
$tsBody = isset( $tsMatch[0] ) ? $tsMatch[0] : '';
assert_true(
	mb_strpos( $tsBody, 'REQUIRED_WEBHOOK_EVENTS' ) !== FALSE,
	'testSettings() references REQUIRED_WEBHOOK_EVENTS constant'
);
assert_true(
	preg_match( "/\\\$events\s*=\s*array\s*\(\s*'charge/", $tsBody ) === 0,
	'testSettings() does NOT contain inline events array'
);
echo "\n";

/* ── 4. testSettings() stores webhook_endpoint_id ── */
echo "4. testSettings() stores endpoint ID\n";
assert_true(
	mb_strpos( $tsBody, 'webhook_endpoint_id' ) !== FALSE,
	'testSettings() stores webhook_endpoint_id'
);
echo "\n";

/* ── 5. fetchWebhookEndpoint() method exists ── */
echo "5. fetchWebhookEndpoint() method\n";
assert_true(
	mb_strpos( $gatewaySource, 'function fetchWebhookEndpoint' ) !== FALSE,
	'fetchWebhookEndpoint() method exists on gateway class'
);
echo "\n";

/* ── 6. syncWebhookEvents() method exists ── */
echo "6. syncWebhookEvents() method\n";
assert_true(
	mb_strpos( $gatewaySource, 'function syncWebhookEvents' ) !== FALSE,
	'syncWebhookEvents() method exists on gateway class'
);
echo "\n";

/* ── 7. Replay task uses gateway constant ── */
echo "7. Replay task references gateway constant\n";
$replayPath = $appBase . '/tasks/webhookReplay.php';
$replaySource = file_get_contents( $replayPath );
assert_true(
	mb_strpos( $replaySource, '\\IPS\\xpolarcheckout\\XPolarCheckout::REQUIRED_WEBHOOK_EVENTS' ) !== FALSE,
	'Replay task references \\IPS\\xpolarcheckout\\XPolarCheckout::REQUIRED_WEBHOOK_EVENTS'
);
assert_true(
	mb_strpos( $replaySource, 'const REPLAY_EVENT_TYPES' ) === FALSE,
	'Replay task does NOT contain local REPLAY_EVENT_TYPES constant'
);
echo "\n";

/* ── 8. Integrity controller has syncEvents method ── */
echo "8. Integrity controller syncEvents\n";
$integrityPath = $appBase . '/modules/admin/monitoring/integrity.php';
$integritySource = file_get_contents( $integrityPath );
assert_true(
	mb_strpos( $integritySource, 'function syncEvents' ) !== FALSE,
	'Integrity controller has syncEvents method'
);
echo "\n";

/* ── 9. syncEvents calls csrfCheck() ── */
echo "9. syncEvents CSRF protection\n";
$syncMatch = preg_match( '/function\s+syncEvents\s*\(.*?\n\t\}/s', $integritySource, $syncBody );
$syncBodyStr = isset( $syncBody[0] ) ? $syncBody[0] : '';
assert_true(
	mb_strpos( $syncBodyStr, 'csrfCheck()' ) !== FALSE,
	'syncEvents() calls csrfCheck()'
);
echo "\n";

/* ── 10. collectIntegrityStats references drift fields ── */
echo "10. collectIntegrityStats drift fields\n";
assert_true(
	mb_strpos( $integritySource, 'webhook_events_missing' ) !== FALSE,
	'collectIntegrityStats references webhook_events_missing'
);
assert_true(
	mb_strpos( $integritySource, 'webhook_events_extra' ) !== FALSE,
	'collectIntegrityStats references webhook_events_extra'
);
echo "\n";

/* ── 11. All new lang strings present ── */
echo "11. Lang strings\n";
$langPath = $appBase . '/dev/lang.php';
$langSource = file_get_contents( $langPath );
$langStrings = array(
	'xpolarcheckout_webhook_endpoint',
	'xpolarcheckout_webhook_events_ok',
	'xpolarcheckout_webhook_events_drift',
	'xpolarcheckout_webhook_endpoint_not_found',
	'xpolarcheckout_webhook_sync_events',
	'xpolarcheckout_webhook_sync_success',
	'xpolarcheckout_webhook_sync_failed',
	'xpolarcheckout_webhook_sync_not_found',
	'acplogs__xpolarcheckout_webhook_sync',
);
foreach ( $langStrings as $key )
{
	assert_true(
		mb_strpos( $langSource, $key ) !== FALSE,
		"Lang string '{$key}' present"
	);
}
echo "\n";

/* ── 12. Mock drift logic ── */
echo "12. Mock drift logic\n";
$required = array( 'A', 'B', 'C' );
$stripe = array( 'B', 'C', 'D' );
$missing = array_values( array_diff( $required, $stripe ) );
$extra = array_values( array_diff( $stripe, $required ) );
assert_true(
	$missing === array( 'A' ),
	'Drift logic: missing = [A]'
);
assert_true(
	$extra === array( 'D' ),
	'Drift logic: extra = [D]'
);
echo "\n";

/* ── Summary ── */
echo "=== Results: {$pass}/{$total} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
