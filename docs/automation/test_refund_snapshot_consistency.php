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

$settings = \json_decode( $gateway->settings, TRUE );
assertOrFail( \is_array( $settings ) && !empty( $settings['secret'] ), 'Gateway settings missing Stripe secret key.' );

$transactionRows = \IPS\Db::i()->select(
	't_id,t_status,t_invoice,t_extra',
	'nexus_transactions',
	array( 't_method IN (?) AND t_status IN(?,?)', \IPS\Db::i()->select( 'm_id', 'nexus_paymethods', array( 'm_gateway=?', 'XPolarCheckout' ) ), \IPS\nexus\Transaction::STATUS_PART_REFUNDED, \IPS\nexus\Transaction::STATUS_REFUNDED ),
	't_id DESC',
	25
);

$validated = 0;
$sawPartialContext = FALSE;
$sawFullContext = FALSE;
$stripeEvidenceRows = 0;

foreach ( $transactionRows as $row )
{
	$transaction = \IPS\nexus\Transaction::load( (int) $row['t_id'] );
	$invoice = $transaction->invoice;
	$extra = $transaction->extra;
	if ( !\is_array( $extra ) || !isset( $extra['xpolarcheckout_snapshot'] ) || !\is_array( $extra['xpolarcheckout_snapshot'] ) )
	{
		continue;
	}

	$snapshot = $extra['xpolarcheckout_snapshot'];
	$invoiceSnapshot = \is_array( $invoice->status_extra ) && isset( $invoice->status_extra['xpolarcheckout_snapshot'] ) && \is_array( $invoice->status_extra['xpolarcheckout_snapshot'] )
		? $invoice->status_extra['xpolarcheckout_snapshot']
		: array();

	assertOrFail( isset( $snapshot['amount_total_minor'] ) && \is_numeric( $snapshot['amount_total_minor'] ), "Transaction {$transaction->id} snapshot missing amount_total_minor." );
	assertOrFail( isset( $snapshot['amount_tax_minor'] ) && \is_numeric( $snapshot['amount_tax_minor'] ), "Transaction {$transaction->id} snapshot missing amount_tax_minor." );
	assertOrFail( isset( $snapshot['amount_total_display'] ) && \is_string( $snapshot['amount_total_display'] ) && $snapshot['amount_total_display'] !== '', "Transaction {$transaction->id} snapshot missing amount_total_display." );
	assertOrFail( isset( $snapshot['amount_tax_display'] ) && \is_string( $snapshot['amount_tax_display'] ) && $snapshot['amount_tax_display'] !== '', "Transaction {$transaction->id} snapshot missing amount_tax_display." );
	assertOrFail( \count( $invoiceSnapshot ) > 0, "Invoice {$invoice->id} snapshot missing for transaction {$transaction->id}." );

	if ( isset( $invoiceSnapshot['amount_total_minor'] ) )
	{
		assertOrFail( (int) $invoiceSnapshot['amount_total_minor'] === (int) $snapshot['amount_total_minor'], "Transaction {$transaction->id} total mismatch between transaction and invoice snapshot." );
	}
	if ( isset( $invoiceSnapshot['amount_tax_minor'] ) )
	{
		assertOrFail( (int) $invoiceSnapshot['amount_tax_minor'] === (int) $snapshot['amount_tax_minor'], "Transaction {$transaction->id} tax mismatch between transaction and invoice snapshot." );
	}
	if ( isset( $invoiceSnapshot['amount_total_display'] ) )
	{
		assertOrFail( (string) $invoiceSnapshot['amount_total_display'] === (string) $snapshot['amount_total_display'], "Transaction {$transaction->id} total display mismatch between transaction and invoice snapshot." );
	}
	if ( isset( $invoiceSnapshot['amount_tax_display'] ) )
	{
		assertOrFail( (string) $invoiceSnapshot['amount_tax_display'] === (string) $snapshot['amount_tax_display'], "Transaction {$transaction->id} tax display mismatch between transaction and invoice snapshot." );
	}

	if ( \is_string( $transaction->gw_id ) && $transaction->gw_id !== '' )
	{
		$refundList = \IPS\Http\Url::external( 'https://api.stripe.com/v1/refunds' )
			->setQueryString( array(
				'payment_intent' => $transaction->gw_id,
				'limit' => 10,
			) )
			->request( 20 )
			->setHeaders( array(
				'Authorization' => 'Bearer ' . $settings['secret'],
				'Stripe-Version' => \IPS\xpolarcheckout\XPolarCheckout::STRIPE_VERSION,
			) )
			->get()
			->decodeJson();

		if ( \is_array( $refundList ) && empty( $refundList['error'] ) && isset( $refundList['data'] ) && \is_array( $refundList['data'] ) )
		{
			$chargeAmount = isset( $snapshot['amount_total_minor'] ) && \is_numeric( $snapshot['amount_total_minor'] ) ? (int) $snapshot['amount_total_minor'] : NULL;
			$totalRefunded = 0;
			$refundCount = 0;
			foreach ( $refundList['data'] as $refundRow )
			{
				if ( !\is_array( $refundRow ) || !isset( $refundRow['amount'] ) || !\is_numeric( $refundRow['amount'] ) )
				{
					continue;
				}

				$refundAmount = (int) $refundRow['amount'];
				$refundCount++;
				$totalRefunded += $refundAmount;

				if ( $chargeAmount !== NULL && $chargeAmount > 0 && $refundAmount > 0 && $refundAmount < $chargeAmount )
				{
					$sawPartialContext = TRUE;
				}
			}

			if ( $refundCount > 0 )
			{
				$stripeEvidenceRows++;
			}

			if ( $chargeAmount !== NULL && $chargeAmount > 0 && $totalRefunded >= $chargeAmount )
			{
				$sawFullContext = TRUE;
			}
		}
	}

	$validated++;
}

assertOrFail( $validated > 0, 'No refunded xpolarcheckout transactions with snapshots were validated.' );
assertOrFail( $stripeEvidenceRows > 0, 'No Stripe charge evidence rows were available for refund consistency validation.' );
assertOrFail( $sawPartialContext, 'No partial-refund context found in validated transactions/history.' );
assertOrFail( $sawFullContext, 'No full-refund context found in validated transactions/history.' );

\fwrite( STDOUT, "PASS: xpolarcheckout refund snapshot consistency checks\n" );
\fwrite( STDOUT, "Validated transactions: " . (string) $validated . "\n" );
\fwrite( STDOUT, "Partial context observed: " . ( $sawPartialContext ? 'yes' : 'no' ) . "\n" );
\fwrite( STDOUT, "Full context observed: " . ( $sawFullContext ? 'yes' : 'no' ) . "\n" );
exit( 0 );
