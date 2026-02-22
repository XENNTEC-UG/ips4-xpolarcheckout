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

$reflection = new \ReflectionClass( \IPS\xpolarcheckout\XPolarCheckout::class );
$gateway = $reflection->newInstanceWithoutConstructor();

$taxConfigMethod = $reflection->getMethod( 'applyCheckoutTaxConfiguration' );
$taxConfigMethod->setAccessible( TRUE );

$lineTaxMethod = $reflection->getMethod( 'applyLineItemTaxBehavior' );
$lineTaxMethod->setAccessible( TRUE );

$baseSession = array( 'mode' => 'payment' );
$taxEnabledSession = $taxConfigMethod->invoke( $gateway, $baseSession, TRUE, array( 'address_collection' => 0 ) );
assertOrFail( isset( $taxEnabledSession['automatic_tax']['enabled'] ) AND $taxEnabledSession['automatic_tax']['enabled'] === 'true', 'Tax-enabled session must set automatic_tax.enabled=true.' );
assertOrFail( isset( $taxEnabledSession['billing_address_collection'] ) AND $taxEnabledSession['billing_address_collection'] === 'required', 'Tax-enabled session must require billing address collection.' );
assertOrFail( isset( $taxEnabledSession['customer_update']['address'] ) AND $taxEnabledSession['customer_update']['address'] === 'auto', 'Tax-enabled session must set customer_update.address=auto.' );
assertOrFail( isset( $taxEnabledSession['customer_update']['name'] ) AND $taxEnabledSession['customer_update']['name'] === 'auto', 'Tax-enabled session must set customer_update.name=auto.' );

$addressCollectionSession = $taxConfigMethod->invoke( $gateway, $baseSession, FALSE, array( 'address_collection' => 1 ) );
assertOrFail( !isset( $addressCollectionSession['automatic_tax'] ), 'Tax-disabled session must not send automatic_tax.' );
assertOrFail( isset( $addressCollectionSession['billing_address_collection'] ) AND $addressCollectionSession['billing_address_collection'] === 'required', 'Tax-disabled + address_collection session must require billing address.' );

$plainSession = $taxConfigMethod->invoke( $gateway, $baseSession, FALSE, array( 'address_collection' => 0 ) );
assertOrFail( !isset( $plainSession['automatic_tax'] ), 'Plain session must not send automatic_tax when tax is disabled.' );
assertOrFail( !isset( $plainSession['billing_address_collection'] ), 'Plain session must not force billing address collection when disabled.' );

$baseLineItem = array(
	'price_data' => array(
		'currency' => 'EUR',
		'unit_amount' => 500,
		'product_data' => array( 'name' => 'Test Item' ),
	),
	'quantity' => 1,
);

$exclusiveLineItem = $lineTaxMethod->invoke( $gateway, $baseLineItem, TRUE, 'exclusive' );
assertOrFail( isset( $exclusiveLineItem['price_data']['tax_behavior'] ) AND $exclusiveLineItem['price_data']['tax_behavior'] === 'exclusive', 'Tax-enabled line item must set tax_behavior=exclusive when configured.' );

$inclusiveLineItem = $lineTaxMethod->invoke( $gateway, $baseLineItem, TRUE, 'inclusive' );
assertOrFail( isset( $inclusiveLineItem['price_data']['tax_behavior'] ) AND $inclusiveLineItem['price_data']['tax_behavior'] === 'inclusive', 'Tax-enabled line item must set tax_behavior=inclusive when configured.' );

$fallbackLineItem = $lineTaxMethod->invoke( $gateway, $baseLineItem, TRUE, 'bad-value' );
assertOrFail( isset( $fallbackLineItem['price_data']['tax_behavior'] ) AND $fallbackLineItem['price_data']['tax_behavior'] === 'exclusive', 'Invalid tax behavior must normalize to exclusive.' );

$untaxedLineItem = $lineTaxMethod->invoke( $gateway, $baseLineItem, FALSE, 'inclusive' );
assertOrFail( !isset( $untaxedLineItem['price_data']['tax_behavior'] ), 'Tax-disabled line item must not include tax_behavior.' );

\fwrite( STDOUT, "PASS: xpolarcheckout tax payload automation checks\n" );
exit( 0 );
