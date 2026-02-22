<?php
/**
 * Automation test: payment integrity alerting (A17)
 *
 * Run:
 *   docker compose exec -T php php /workspace/ips-dev-source/apps/xpolarcheckout/docs/automation/test_integrity_alerting.php
 *
 * Validates:
 *   1.  Extension class exists and has correct static properties
 *   2.  runChecksAndSendNotifications() handles all 4 alert types
 *   3.  title()/subtitle()/body() handle all 4 alert types
 *   4.  severity() returns HIGH for webhook_errors and replay_stale
 *   5.  dismissible() returns TEMPORARY for all types
 *   6.  link() returns integrity panel URL
 *   7.  selfDismiss() handles all 4 types
 *   8.  Monitor task exists and calls runChecksAndSendNotifications()
 *   9.  extensions.json registers AdminNotifications/PaymentIntegrity
 *  10.  tasks.json registers integrityMonitor with PT5M interval
 *  11.  collectAlertStats() static method exists on gateway class
 *  12.  All new lang strings present
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
$extensionPath = $appBase . '/extensions/core/AdminNotifications/PaymentIntegrity.php';
$taskPath = $appBase . '/tasks/integrityMonitor.php';
$gatewayPath = $appBase . '/sources/XPolarCheckout/XPolarCheckout.php';
$langPath = '/workspace/ips-dev-source/apps/xpolarcheckout/app-source/dev/lang.php';
$extensionsJsonPath = '/workspace/ips-dev-source/apps/xpolarcheckout/app-source/data/extensions.json';
$tasksJsonPath = '/workspace/ips-dev-source/apps/xpolarcheckout/app-source/data/tasks.json';

$extensionSource = file_get_contents( $extensionPath );
$taskSource = file_get_contents( $taskPath );
$gatewaySource = file_get_contents( $gatewayPath );
$langSource = file_get_contents( $langPath );
$extensionsJson = file_get_contents( $extensionsJsonPath );
$tasksJson = file_get_contents( $tasksJsonPath );

echo "=== Payment Integrity Alerting Tests (A17) ===\n\n";

$alertTypes = array( 'webhook_errors', 'replay_stale', 'mismatches', 'tax_not_collecting' );

// --- 1. Extension class structure ---
echo "1. Extension class structure\n";
assert_true(
	mb_strpos( $extensionSource, 'extends \\IPS\\core\\AdminNotification' ) !== FALSE,
	'extends \\IPS\\core\\AdminNotification'
);
assert_true(
	mb_strpos( $extensionSource, "public static \$group = 'commerce'" ) !== FALSE,
	'$group = commerce'
);
assert_true(
	mb_strpos( $extensionSource, 'public static $groupPriority' ) !== FALSE,
	'$groupPriority defined'
);
assert_true(
	mb_strpos( $extensionSource, 'public static $itemPriority' ) !== FALSE,
	'$itemPriority defined'
);
echo "\n";

// --- 2. runChecksAndSendNotifications ---
echo "2. runChecksAndSendNotifications() method\n";
assert_true(
	mb_strpos( $extensionSource, 'function runChecksAndSendNotifications()' ) !== FALSE,
	'runChecksAndSendNotifications() exists'
);
assert_true(
	mb_strpos( $extensionSource, 'collectAlertStats()' ) !== FALSE,
	'calls collectAlertStats()'
);
foreach ( $alertTypes as $type )
{
	assert_true(
		mb_strpos( $extensionSource, "'" . $type . "'" ) !== FALSE,
		"references alert type '{$type}'"
	);
}
echo "\n";

// --- 3. title/subtitle/body per type ---
echo "3. title/subtitle/body handle alert types\n";
assert_true(
	mb_strpos( $extensionSource, 'function title()' ) !== FALSE,
	'title() method exists'
);
assert_true(
	mb_strpos( $extensionSource, 'function subtitle()' ) !== FALSE,
	'subtitle() method exists'
);
assert_true(
	mb_strpos( $extensionSource, 'function body()' ) !== FALSE,
	'body() method exists'
);
// title uses dynamic lang key pattern
assert_true(
	mb_strpos( $extensionSource, "xsc_alert_' . \$this->extra . '_title" ) !== FALSE,
	'title() uses dynamic lang key from $this->extra'
);
// body uses dynamic lang key pattern
assert_true(
	mb_strpos( $extensionSource, "xsc_alert_' . \$this->extra . '_body" ) !== FALSE,
	'body() uses dynamic lang key from $this->extra'
);
echo "\n";

// --- 4. severity ---
echo "4. Severity mapping\n";
assert_true(
	mb_strpos( $extensionSource, 'function severity()' ) !== FALSE,
	'severity() method exists'
);
assert_true(
	mb_strpos( $extensionSource, 'SEVERITY_HIGH' ) !== FALSE,
	'uses SEVERITY_HIGH'
);
assert_true(
	mb_strpos( $extensionSource, 'SEVERITY_NORMAL' ) !== FALSE,
	'uses SEVERITY_NORMAL'
);
// webhook_errors and replay_stale are HIGH
assert_true(
	preg_match( "/webhook_errors.*replay_stale.*SEVERITY_HIGH/s", $extensionSource ) === 1
	|| preg_match( "/array\s*\(\s*'webhook_errors'\s*,\s*'replay_stale'\s*\)/", $extensionSource ) === 1,
	'webhook_errors and replay_stale mapped to HIGH'
);
echo "\n";

// --- 5. dismissible ---
echo "5. Dismissible mapping\n";
assert_true(
	mb_strpos( $extensionSource, 'function dismissible()' ) !== FALSE,
	'dismissible() method exists'
);
assert_true(
	mb_strpos( $extensionSource, 'DISMISSIBLE_TEMPORARY' ) !== FALSE,
	'uses DISMISSIBLE_TEMPORARY'
);
echo "\n";

// --- 6. link ---
echo "6. Link to integrity panel\n";
assert_true(
	mb_strpos( $extensionSource, 'function link()' ) !== FALSE,
	'link() method exists'
);
assert_true(
	mb_strpos( $extensionSource, 'app=xpolarcheckout&module=monitoring&controller=integrity' ) !== FALSE,
	'link() points to integrity panel'
);
echo "\n";

// --- 7. selfDismiss ---
echo "7. selfDismiss() per type\n";
assert_true(
	mb_strpos( $extensionSource, 'function selfDismiss()' ) !== FALSE,
	'selfDismiss() method exists'
);
foreach ( $alertTypes as $type )
{
	assert_true(
		mb_strpos( $extensionSource, "case '" . $type . "':" ) !== FALSE,
		"selfDismiss handles '{$type}'"
	);
}
echo "\n";

// --- 8. Monitor task ---
echo "8. integrityMonitor task\n";
assert_true(
	file_exists( $taskPath ),
	'integrityMonitor.php exists'
);
assert_true(
	mb_strpos( $taskSource, 'class _integrityMonitor extends \\IPS\\Task' ) !== FALSE,
	'extends \\IPS\\Task'
);
assert_true(
	mb_strpos( $taskSource, 'runChecksAndSendNotifications()' ) !== FALSE,
	'calls runChecksAndSendNotifications()'
);
echo "\n";

// --- 9. extensions.json ---
echo "9. extensions.json registration\n";
$extData = json_decode( $extensionsJson, TRUE );
assert_true(
	isset( $extData['core']['AdminNotifications']['PaymentIntegrity'] ),
	'AdminNotifications/PaymentIntegrity registered'
);
echo "\n";

// --- 10. tasks.json ---
echo "10. tasks.json registration\n";
$taskData = json_decode( $tasksJson, TRUE );
assert_true(
	isset( $taskData['integrityMonitor'] ),
	'integrityMonitor registered'
);
assert_true(
	$taskData['integrityMonitor'] === 'P0Y0M0DT0H5M0S',
	'integrityMonitor interval is 5 minutes (PT5M)'
);
echo "\n";

// --- 11. collectAlertStats ---
echo "11. collectAlertStats() on gateway class\n";
assert_true(
	mb_strpos( $gatewaySource, 'function collectAlertStats()' ) !== FALSE,
	'collectAlertStats() method exists on gateway'
);
assert_true(
	mb_strpos( $gatewaySource, "public static function collectAlertStats()" ) !== FALSE,
	'collectAlertStats() is public static'
);
assert_true(
	mb_strpos( $gatewaySource, "'webhook_error_count_24h'" ) !== FALSE,
	'collectAlertStats returns webhook_error_count_24h'
);
assert_true(
	mb_strpos( $gatewaySource, "'replay_recent_run'" ) !== FALSE,
	'collectAlertStats returns replay_recent_run'
);
assert_true(
	mb_strpos( $gatewaySource, "'mismatch_count_30d'" ) !== FALSE,
	'collectAlertStats returns mismatch_count_30d'
);
assert_true(
	mb_strpos( $gatewaySource, "'tax_readiness_status'" ) !== FALSE,
	'collectAlertStats returns tax_readiness_status'
);
echo "\n";

// --- 12. Lang strings ---
echo "12. Lang strings present\n";
$requiredLangKeys = array(
	'acp_notification_PaymentIntegrity',
	'xsc_alert_webhook_errors_title',
	'xsc_alert_replay_stale_title',
	'xsc_alert_mismatches_title',
	'xsc_alert_tax_not_collecting_title',
	'xsc_alert_webhook_errors_subtitle',
	'xsc_alert_replay_stale_subtitle',
	'xsc_alert_mismatches_subtitle',
	'xsc_alert_tax_not_collecting_subtitle',
	'xsc_alert_webhook_errors_body',
	'xsc_alert_replay_stale_body',
	'xsc_alert_mismatches_body',
	'xsc_alert_tax_not_collecting_body',
	'task__integrityMonitor',
);
foreach ( $requiredLangKeys as $key )
{
	assert_true(
		mb_strpos( $langSource, "'" . $key . "'" ) !== FALSE,
		"lang key '{$key}' present"
	);
}
echo "\n";

echo "=== Results: {$pass}/{$total} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );
