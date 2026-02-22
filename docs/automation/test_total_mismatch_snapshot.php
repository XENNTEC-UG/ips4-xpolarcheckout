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

$gateway = NULL;
foreach ( \IPS\nexus\Gateway::roots() as $method )
{
	if ( $method instanceof \IPS\xpolarcheckout\XPolarCheckout )
	{
		$gateway = $method;
		break;
	}
}
assertOrFail( $gateway !== NULL, 'XPolarCheckout gateway not found in Nexus payment methods.' );

try
{
	$transactionId = \IPS\Db::i()->select(
		't_id',
		'nexus_transactions',
		array( 't_method=?', $gateway->id ),
		't_id DESC',
		1
	)->first();
}
catch ( \UnderflowException $e )
{
	\fwrite( STDERR, "ASSERTION FAILED: No xpolarcheckout transactions found for mismatch test.\n" );
	exit( 1 );
}

$transaction = \IPS\nexus\Transaction::load( $transactionId );
assertOrFail( $transaction->invoice instanceof \IPS\nexus\Invoice, 'Transaction invoice is not available.' );
assertOrFail( $transaction->invoice->total instanceof \IPS\nexus\Money, 'Invoice total is not a money object.' );

$controllerClass = new \ReflectionClass( \IPS\xpolarcheckout\modules\front\webhook\webhook::class );
$controller = $controllerClass->newInstanceWithoutConstructor();

$moneyToMinorMethod = $controllerClass->getMethod( 'moneyToMinorUnit' );
$moneyToMinorMethod->setAccessible( TRUE );
$applyComparisonMethod = $controllerClass->getMethod( 'applyIpsInvoiceTotalComparison' );
$applyComparisonMethod->setAccessible( TRUE );

$invoiceTotalMinor = (int) $moneyToMinorMethod->invoke( $controller, $transaction->invoice->total );

/* --- Part 1: Exact match (no difference) --- */
$matchingSnapshot = array(
	'currency' => $transaction->invoice->total->currency,
	'amount_total_minor' => $invoiceTotalMinor,
	'amount_tax_minor' => 0,
);
$matchingResult = $applyComparisonMethod->invoke( $controller, $matchingSnapshot, $transaction );
assertOrFail( isset( $matchingResult['ips_invoice_total_minor'] ) && (int) $matchingResult['ips_invoice_total_minor'] === $invoiceTotalMinor, 'Matching snapshot missing ips_invoice_total_minor.' );
assertOrFail( isset( $matchingResult['has_total_mismatch'] ) && $matchingResult['has_total_mismatch'] === FALSE, 'Matching snapshot should not report mismatch.' );
assertOrFail( isset( $matchingResult['total_difference_minor'] ) && (int) $matchingResult['total_difference_minor'] === 0, 'Matching snapshot difference minor should be zero.' );
assertOrFail( isset( $matchingResult['total_difference_tax_explained'] ) && $matchingResult['total_difference_tax_explained'] === FALSE, 'Matching snapshot should not be tax-explained (no difference).' );
\fwrite( STDOUT, "[PASS] Part 1: exact match\n" );

/* --- Part 2: Difference equals tax (tax-explained, not a mismatch) --- */
$taxAmount = 190;
$taxExplainedSnapshot = array(
	'currency' => $transaction->invoice->total->currency,
	'amount_total_minor' => $invoiceTotalMinor + $taxAmount,
	'amount_tax_minor' => $taxAmount,
);
$taxExplainedResult = $applyComparisonMethod->invoke( $controller, $taxExplainedSnapshot, $transaction );
assertOrFail( $taxExplainedResult['total_difference_tax_explained'] === TRUE, 'Tax-explained snapshot should set total_difference_tax_explained=TRUE.' );
assertOrFail( $taxExplainedResult['has_total_mismatch'] === FALSE, 'Tax-explained snapshot should NOT report mismatch.' );
assertOrFail( (int) $taxExplainedResult['total_difference_minor'] === $taxAmount, 'Tax-explained difference should equal tax amount.' );
assertOrFail( \is_string( $taxExplainedResult['total_difference_display'] ) && $taxExplainedResult['total_difference_display'] !== '', 'Tax-explained snapshot should have difference display.' );
\fwrite( STDOUT, "[PASS] Part 2: tax-explained difference\n" );

/* --- Part 3: Unexplained mismatch (difference != tax) --- */
$mismatchSnapshot = array(
	'currency' => $transaction->invoice->total->currency,
	'amount_total_minor' => $invoiceTotalMinor + 290,
	'amount_tax_minor' => 190,
);
$mismatchResult = $applyComparisonMethod->invoke( $controller, $mismatchSnapshot, $transaction );
assertOrFail( $mismatchResult['has_total_mismatch'] === TRUE, 'Unexplained difference should report mismatch.' );
assertOrFail( $mismatchResult['total_difference_tax_explained'] === FALSE, 'Unexplained difference should NOT be tax-explained.' );
assertOrFail( (int) $mismatchResult['total_difference_minor'] === 290, 'Unexplained difference minor should be 290.' );
assertOrFail( (int) $mismatchResult['total_mismatch_minor'] === 290, 'Mismatch minor should be 290.' );
assertOrFail( \is_string( $mismatchResult['total_mismatch_display'] ) && $mismatchResult['total_mismatch_display'] !== '', 'Mismatch display should be populated.' );
\fwrite( STDOUT, "[PASS] Part 3: unexplained mismatch\n" );

/* --- Part 4: Real data integrity scan --- */
/* Verify every stored snapshot has consistent mismatch/tax-explained state */
$scanned = 0;
$inconsistent = array();
foreach ( \IPS\Db::i()->select( 't_id, t_extra', 'nexus_transactions', array( 't_method=? AND t_extra LIKE ?', $gateway->id, '%xpolarcheckout_snapshot%' ), 't_id DESC', 50 ) as $row )
{
	$extra = \json_decode( $row['t_extra'], TRUE );
	if ( !\is_array( $extra ) || !isset( $extra['xpolarcheckout_snapshot'] ) )
	{
		continue;
	}
	$snap = $extra['xpolarcheckout_snapshot'];
	$scanned++;

	/* Skip rows without comparison data (pre-comparison era) */
	if ( !isset( $snap['amount_total_minor'] ) || !isset( $snap['ips_invoice_total_minor'] ) )
	{
		continue;
	}

	$stripeTotalMinor = (int) $snap['amount_total_minor'];
	$ipsTotalMinor = (int) $snap['ips_invoice_total_minor'];
	$taxMinor = isset( $snap['amount_tax_minor'] ) ? (int) $snap['amount_tax_minor'] : 0;
	$diff = $stripeTotalMinor - $ipsTotalMinor;

	$expectedTaxExplained = ( $diff !== 0 && $diff === $taxMinor );
	$expectedMismatch = ( $diff !== 0 && !$expectedTaxExplained );

	$actualTaxExplained = isset( $snap['total_difference_tax_explained'] ) ? (bool) $snap['total_difference_tax_explained'] : FALSE;
	$actualMismatch = isset( $snap['has_total_mismatch'] ) ? (bool) $snap['has_total_mismatch'] : FALSE;

	if ( $actualTaxExplained !== $expectedTaxExplained || $actualMismatch !== $expectedMismatch )
	{
		$inconsistent[] = (int) $row['t_id'];
	}
}

assertOrFail( $scanned > 0, 'Real data scan found no snapshots to verify.' );
assertOrFail( \count( $inconsistent ) === 0, 'Inconsistent mismatch state in transactions: ' . \implode( ', ', $inconsistent ) );
\fwrite( STDOUT, "[PASS] Part 4: real data integrity (" . $scanned . " transactions scanned, 0 inconsistent)\n" );

\fwrite( STDOUT, "\nPASS: xpolarcheckout total mismatch snapshot checks (all 4 parts)\n" );
\fwrite( STDOUT, "Transaction: " . (string) $transaction->id . "\n" );
\fwrite( STDOUT, "Invoice total minor: " . (string) $invoiceTotalMinor . "\n" );
exit( 0 );
