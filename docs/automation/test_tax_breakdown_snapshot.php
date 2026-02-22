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

/**
 * @param	string	$path	Stripe API path
 * @param	array	$query	Query string params
 * @param	array	$settings	Gateway settings
 * @return	array
 */
function stripeGetJson( $path, array $query, array $settings )
{
	$url = \IPS\Http\Url::external( 'https://api.stripe.com' . $path );
	if ( \count( $query ) )
	{
		$url = $url->setQueryString( $query );
	}

	return $url
		->request( 20 )
		->setHeaders( array(
			'Authorization' => 'Bearer ' . $settings['secret'],
			'Stripe-Version' => \IPS\xpolarcheckout\XPolarCheckout::STRIPE_VERSION,
		) )
		->get()
		->decodeJson();
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

$invoiceList = stripeGetJson( '/v1/invoices', array( 'status' => 'paid', 'limit' => 20 ), $settings );
assertOrFail( \is_array( $invoiceList ) && isset( $invoiceList['data'] ) && \is_array( $invoiceList['data'] ) && \count( $invoiceList['data'] ), 'Stripe API returned no paid invoices to validate tax breakdown.' );

$invoice = NULL;
foreach ( $invoiceList['data'] as $candidateInvoice )
{
	if ( !\is_array( $candidateInvoice ) )
	{
		continue;
	}

	$hasTotalTaxes = isset( $candidateInvoice['total_taxes'] ) && \is_array( $candidateInvoice['total_taxes'] ) && \count( $candidateInvoice['total_taxes'] );
	$hasTotalTaxAmounts = isset( $candidateInvoice['total_tax_amounts'] ) && \is_array( $candidateInvoice['total_tax_amounts'] ) && \count( $candidateInvoice['total_tax_amounts'] );
	if ( $hasTotalTaxes || $hasTotalTaxAmounts )
	{
		$invoice = $candidateInvoice;
		break;
	}
}

if ( $invoice === NULL )
{
	$invoice = $invoiceList['data'][0];
}

$controllerClass = new \ReflectionClass( \IPS\xpolarcheckout\modules\front\webhook\webhook::class );
$controller = $controllerClass->newInstanceWithoutConstructor();
$taxBreakdownMethod = $controllerClass->getMethod( 'buildStripeTaxBreakdown' );
$taxBreakdownMethod->setAccessible( TRUE );

$currency = isset( $invoice['currency'] ) ? \strtoupper( (string) $invoice['currency'] ) : NULL;
$taxSnapshot = $taxBreakdownMethod->invoke( $controller, $invoice, $currency, $settings );

assertOrFail( \is_array( $taxSnapshot ), 'Tax snapshot should be an array.' );
assertOrFail( \array_key_exists( 'taxability_reason', $taxSnapshot ), 'Tax snapshot missing taxability_reason key.' );
assertOrFail( \array_key_exists( 'taxability_reasons', $taxSnapshot ) && \is_array( $taxSnapshot['taxability_reasons'] ), 'Tax snapshot missing taxability_reasons array.' );
assertOrFail( \array_key_exists( 'tax_breakdown', $taxSnapshot ) && \is_array( $taxSnapshot['tax_breakdown'] ), 'Tax snapshot missing tax_breakdown array.' );

$hasExpectedTaxEntries = ( isset( $invoice['total_taxes'] ) && \is_array( $invoice['total_taxes'] ) && \count( $invoice['total_taxes'] ) )
	|| ( isset( $invoice['total_tax_amounts'] ) && \is_array( $invoice['total_tax_amounts'] ) && \count( $invoice['total_tax_amounts'] ) );

if ( $hasExpectedTaxEntries )
{
	assertOrFail( \count( $taxSnapshot['tax_breakdown'] ) > 0, 'Tax breakdown should have at least one row for invoice with Stripe tax entries.' );
	$firstRow = $taxSnapshot['tax_breakdown'][0];
	assertOrFail( \is_array( $firstRow ), 'First tax breakdown row must be an array.' );
	assertOrFail( \array_key_exists( 'amount_minor', $firstRow ), 'Tax breakdown row missing amount_minor.' );
	assertOrFail( \array_key_exists( 'amount_display', $firstRow ), 'Tax breakdown row missing amount_display.' );
	assertOrFail( \array_key_exists( 'taxability_reason', $firstRow ), 'Tax breakdown row missing taxability_reason.' );
}

\fwrite( STDOUT, "PASS: xpolarcheckout tax breakdown snapshot checks\n" );
\fwrite( STDOUT, "Invoice: " . ( isset( $invoice['id'] ) ? $invoice['id'] : 'unknown' ) . "\n" );
\fwrite( STDOUT, "Tax rows: " . (string) \count( $taxSnapshot['tax_breakdown'] ) . "\n" );
if ( \count( $taxSnapshot['tax_breakdown'] ) )
{
	\fwrite( STDOUT, "First row: " . \json_encode( $taxSnapshot['tax_breakdown'][0] ) . "\n" );
}

exit( 0 );
