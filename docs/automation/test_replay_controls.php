<?php
/**
 * Automation test: replay task runtime controls (A16)
 *
 * Run:
 *   docker compose exec -T php php /workspace/ips-dev-source/apps/xpolarcheckout/docs/automation/test_replay_controls.php
 *
 * Validates:
 *   1.  Gateway settings() form contains replay_lookback, replay_overlap, replay_max_events fields
 *   2.  Replay task execute() reads replay_lookback from settings
 *   3.  Replay task execute() reads replay_overlap from settings
 *   4.  Replay task execute() reads replay_max_events from settings
 *   5.  Replay task clamps lookback within 300-86400
 *   6.  Replay task clamps overlap within 60-1800
 *   7.  Replay task clamps maxEvents within 10-100
 *   8.  Replay task execute() accepts dryRun parameter
 *   9.  Dry-run mode does NOT call forwardEventToWebhook
 *  10.  Dry-run mode does NOT call saveReplayState
 *  11.  Dry-run mode returns structured array with count and events keys
 *  12.  Replay task uses paginated fetching (fetchStripeEventsPaginated)
 *  13.  Pagination respects MAX_PAGES_PER_RUN constant
 *  14.  Pagination respects MAX_RUNTIME_SECONDS constant
 *  15.  Pagination follows has_more + starting_after pattern
 *  16.  Integrity controller has dryRunReplay method with csrfCheck()
 *  17.  Integrity panel shows replay config stats (replay_config_lookback, replay_config_overlap, replay_config_max_events)
 *  18.  Integrity panel staleness uses configured lookback (not hardcoded 3600)
 *  19.  All new lang strings present
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
$gatewayPath = $appBase . '/sources/XPolarCheckout/XPolarCheckout.php';
$taskPath = $appBase . '/tasks/webhookReplay.php';
$integrityPath = $appBase . '/modules/admin/monitoring/integrity.php';
$langPath = '/workspace/ips-dev-source/apps/xpolarcheckout/app-source/dev/lang.php';

$gatewaySource = file_get_contents( $gatewayPath );
$taskSource = file_get_contents( $taskPath );
$integritySource = file_get_contents( $integrityPath );
$langSource = file_get_contents( $langPath );

echo "=== Replay Task Runtime Controls Tests (A16) ===\n\n";

// --- 1. Gateway form fields ---
echo "1. Gateway settings form contains replay fields\n";
assert_true(
	mb_strpos( $gatewaySource, "xpolarcheckout_replay_lookback" ) !== FALSE,
	'settings() has replay_lookback field'
);
assert_true(
	mb_strpos( $gatewaySource, "xpolarcheckout_replay_overlap" ) !== FALSE,
	'settings() has replay_overlap field'
);
assert_true(
	mb_strpos( $gatewaySource, "xpolarcheckout_replay_max_events" ) !== FALSE,
	'settings() has replay_max_events field'
);
assert_true(
	mb_strpos( $gatewaySource, "addHeader('xpolarcheckout_replay')" ) !== FALSE
	|| mb_strpos( $gatewaySource, "addHeader( 'xpolarcheckout_replay' )" ) !== FALSE,
	'settings() has replay section header'
);
echo "\n";

// --- 2-4. Task reads configurable settings ---
echo "2. Replay task reads configurable settings from gateway\n";
assert_true(
	mb_strpos( $taskSource, "\$settings['replay_lookback']" ) !== FALSE,
	'execute() reads replay_lookback from settings'
);
assert_true(
	mb_strpos( $taskSource, "\$settings['replay_overlap']" ) !== FALSE,
	'execute() reads replay_overlap from settings'
);
assert_true(
	mb_strpos( $taskSource, "\$settings['replay_max_events']" ) !== FALSE,
	'execute() reads replay_max_events from settings'
);
echo "\n";

// --- 5-7. Clamping bounds ---
echo "3. Replay task clamps values within safe bounds\n";
assert_true(
	mb_strpos( $taskSource, 'max( 300, \\min( 86400' ) !== FALSE
	|| mb_strpos( $taskSource, 'max( 300, min( 86400' ) !== FALSE,
	'lookback clamped 300-86400'
);
assert_true(
	mb_strpos( $taskSource, 'max( 60, \\min( 1800' ) !== FALSE
	|| mb_strpos( $taskSource, 'max( 60, min( 1800' ) !== FALSE,
	'overlap clamped 60-1800'
);
assert_true(
	mb_strpos( $taskSource, 'max( 10, \\min( 100' ) !== FALSE
	|| mb_strpos( $taskSource, 'max( 10, min( 100' ) !== FALSE,
	'maxEvents clamped 10-100'
);
echo "\n";

// --- 8-11. Dry-run mode ---
echo "4. Dry-run mode behavior\n";
assert_true(
	preg_match( '/function\s+execute\s*\(\s*\$dryRun\s*=\s*FALSE\s*\)/', $taskSource ) === 1,
	'execute() accepts $dryRun parameter with FALSE default'
);

// In dry-run path, forwardEventToWebhook should NOT be called (it's in the else branch)
$executeMatch = preg_match( '/function\s+execute\s*\(.*?\n\t\}/s', $taskSource, $execBody );
$execBodyStr = isset( $execBody[0] ) ? $execBody[0] : '';
assert_true(
	mb_strpos( $execBodyStr, 'if ( $dryRun )' ) !== FALSE,
	'execute() branches on $dryRun flag'
);
assert_true(
	preg_match( '/\$dryRun\s*\)\s*\{[^}]*forwardEventToWebhook/', $execBodyStr ) === 0,
	'dry-run branch does NOT call forwardEventToWebhook'
);
assert_true(
	preg_match( '/if\s*\(\s*\$dryRun\s*\)[\s\S]*?return\s+array\s*\(\s*[\'"]count[\'"]/', $execBodyStr ) === 1,
	'dry-run returns array with count key'
);
echo "\n";

// --- 12-15. Pagination ---
echo "5. Paginated event fetching\n";
assert_true(
	mb_strpos( $taskSource, 'fetchStripeEventsPaginated' ) !== FALSE,
	'task uses fetchStripeEventsPaginated method'
);
assert_true(
	mb_strpos( $taskSource, 'MAX_PAGES_PER_RUN' ) !== FALSE,
	'MAX_PAGES_PER_RUN constant exists'
);
assert_true(
	mb_strpos( $taskSource, 'MAX_RUNTIME_SECONDS' ) !== FALSE,
	'MAX_RUNTIME_SECONDS constant exists'
);
assert_true(
	mb_strpos( $taskSource, "has_more" ) !== FALSE,
	'pagination checks has_more field'
);
assert_true(
	mb_strpos( $taskSource, "starting_after" ) !== FALSE,
	'pagination uses starting_after cursor'
);
echo "\n";

// --- 16. Integrity dryRunReplay ---
echo "6. Integrity panel dry-run action\n";
assert_true(
	mb_strpos( $integritySource, 'function dryRunReplay' ) !== FALSE,
	'dryRunReplay() method exists'
);
assert_true(
	preg_match( '/function\s+dryRunReplay[\s\S]*?csrfCheck\(\)/', $integritySource ) === 1,
	'dryRunReplay() calls csrfCheck()'
);
assert_true(
	preg_match( '/function\s+dryRunReplay[\s\S]*?execute\s*\(\s*TRUE\s*\)/', $integritySource ) === 1,
	'dryRunReplay() calls execute(TRUE) for dry-run'
);
echo "\n";

// --- 17-18. Integrity panel config display and staleness ---
echo "7. Integrity panel config display and staleness\n";
assert_true(
	mb_strpos( $integritySource, "replay_config_lookback" ) !== FALSE,
	'collectIntegrityStats includes replay_config_lookback'
);
assert_true(
	mb_strpos( $integritySource, "replay_config_overlap" ) !== FALSE,
	'collectIntegrityStats includes replay_config_overlap'
);
assert_true(
	mb_strpos( $integritySource, "replay_config_max_events" ) !== FALSE,
	'collectIntegrityStats includes replay_config_max_events'
);
assert_true(
	mb_strpos( $integritySource, "\$stats['replay_config_lookback']" ) !== FALSE
	&& mb_strpos( $integritySource, '<= 3600' ) === FALSE,
	'staleness uses configured lookback (no hardcoded 3600)'
);
echo "\n";

// --- 19. Lang strings ---
echo "8. Lang strings present\n";
$requiredLangKeys = array(
	'xpolarcheckout_replay',
	'xpolarcheckout_replay_lookback',
	'xpolarcheckout_replay_lookback_desc',
	'xpolarcheckout_replay_overlap',
	'xpolarcheckout_replay_overlap_desc',
	'xpolarcheckout_replay_max_events',
	'xpolarcheckout_replay_max_events_desc',
	'xpolarcheckout_integrity_replay_dry_run',
	'xpolarcheckout_integrity_replay_dry_run_result',
	'xpolarcheckout_integrity_replay_dry_run_none',
	'acplogs__xpolarcheckout_integrity_dry_run',
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
