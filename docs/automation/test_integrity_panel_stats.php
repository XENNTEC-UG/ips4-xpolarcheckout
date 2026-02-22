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

$controllerClass = new \ReflectionClass( \IPS\xpolarcheckout\modules\admin\monitoring\integrity::class );
$controller = $controllerClass->newInstanceWithoutConstructor();
$collectMethod = $controllerClass->getMethod( 'collectIntegrityStats' );
$collectMethod->setAccessible( TRUE );
$replayMethod = $controllerClass->getMethod( 'executeReplayTaskNow' );
$replayMethod->setAccessible( TRUE );

$stats = $collectMethod->invoke( $controller );
assertOrFail( \is_array( $stats ), 'Integrity stats payload must be an array.' );

$requiredKeys = array(
	'gateway_webhook_configured',
	'replay_last_run_at',
	'replay_last_event_created',
	'replay_last_replayed_count',
	'replay_recent_run',
	'webhook_error_count_24h',
	'mismatch_count_all_time',
	'mismatch_count_30d',
	'recent_webhook_errors',
	'recent_mismatch_rows',
	'webhook_endpoint',
	'webhook_events_missing',
	'webhook_events_extra',
	'webhook_endpoint_url_match',
	'webhook_api_version_match',
	'webhook_endpoint_status',
	'tax_readiness_status',
	'tax_readiness_last_checked',
	'tax_readiness_registrations_count',
	'tax_readiness_registrations_summary',
	'tax_readiness_error',
);

foreach ( $requiredKeys as $key )
{
	assertOrFail( \array_key_exists( $key, $stats ), "Missing integrity key: {$key}" );
}

assertOrFail( \is_bool( $stats['gateway_webhook_configured'] ), 'gateway_webhook_configured should be boolean.' );
assertOrFail( \is_bool( $stats['replay_recent_run'] ), 'replay_recent_run should be boolean.' );
assertOrFail( \is_array( $stats['recent_webhook_errors'] ), 'recent_webhook_errors should be an array.' );
assertOrFail( \is_array( $stats['recent_mismatch_rows'] ), 'recent_mismatch_rows should be an array.' );
assertOrFail( \is_numeric( $stats['webhook_error_count_24h'] ), 'webhook_error_count_24h should be numeric.' );
assertOrFail( \is_numeric( $stats['mismatch_count_all_time'] ), 'mismatch_count_all_time should be numeric.' );
assertOrFail( \is_numeric( $stats['mismatch_count_30d'] ), 'mismatch_count_30d should be numeric.' );

$replayResult = $replayMethod->invoke( $controller );
assertOrFail( \is_array( $replayResult ), 'executeReplayTaskNow should return an array.' );
assertOrFail( isset( $replayResult['message'] ) && \is_string( $replayResult['message'] ), 'executeReplayTaskNow missing message key.' );
assertOrFail( \in_array( $replayResult['message'], array( 'xpolarcheckout_integrity_replay_success', 'xpolarcheckout_integrity_replay_no_events' ), TRUE ), 'executeReplayTaskNow returned unknown message key.' );

\fwrite( STDOUT, "PASS: xpolarcheckout integrity panel stats checks\n" );
\fwrite( STDOUT, "Gateway configured: " . ( $stats['gateway_webhook_configured'] ? 'yes' : 'no' ) . "\n" );
\fwrite( STDOUT, "Replay recent run: " . ( $stats['replay_recent_run'] ? 'yes' : 'no' ) . "\n" );
\fwrite( STDOUT, "Webhook errors (24h): " . (string) $stats['webhook_error_count_24h'] . "\n" );
\fwrite( STDOUT, "Mismatch rows (all time): " . (string) $stats['mismatch_count_all_time'] . "\n" );
\fwrite( STDOUT, "Manual replay message: " . $replayResult['message'] . "\n" );
exit( 0 );
