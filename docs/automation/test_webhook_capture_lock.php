<?php
declare( strict_types=1 );

$webhookPath = '/workspace/ips-dev-source/apps/xpolarcheckout/app-source/modules/front/webhook/webhook.php';
if ( !\file_exists( $webhookPath ) )
{
	\fwrite( STDERR, "Missing webhook controller at {$webhookPath}\n" );
	exit( 1 );
}

$source = (string) \file_get_contents( $webhookPath );
if ( $source === '' )
{
	\fwrite( STDERR, "Webhook source is empty\n" );
	exit( 1 );
}

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

assertOrFail(
	\mb_strpos( $source, 'protected function acquireTransactionProcessingLock' ) !== FALSE,
	'acquireTransactionProcessingLock helper must exist.'
);
assertOrFail(
	\mb_strpos( $source, 'protected function releaseTransactionProcessingLock' ) !== FALSE,
	'releaseTransactionProcessingLock helper must exist.'
);
assertOrFail(
	\mb_strpos( $source, "SELECT GET_LOCK('" ) !== FALSE,
	'GET_LOCK SQL call must exist.'
);
assertOrFail(
	\mb_strpos( $source, "SELECT RELEASE_LOCK('" ) !== FALSE,
	'RELEASE_LOCK SQL call must exist.'
);

$acquireCalls = \substr_count( $source, 'acquireTransactionProcessingLock( (int) $transaction->id )' );
assertOrFail(
	$acquireCalls >= 2,
	'Transaction lock must be acquired in both checkout and async payment flows.'
);

$releaseCalls = \substr_count( $source, 'releaseTransactionProcessingLock( (int) $transaction->id )' );
assertOrFail(
	$releaseCalls >= 2,
	'Transaction lock must be released in both checkout and async payment flows.'
);

assertOrFail(
	\mb_strpos( $source, 'xpolarcheckout_webhook_lock' ) !== FALSE,
	'Lock category logging must exist for observability.'
);

\fwrite( STDOUT, "PASS: xpolarcheckout webhook capture lock checks\n" );
\fwrite( STDOUT, "Acquire call count: {$acquireCalls}\n" );
\fwrite( STDOUT, "Release call count: {$releaseCalls}\n" );
exit( 0 );
