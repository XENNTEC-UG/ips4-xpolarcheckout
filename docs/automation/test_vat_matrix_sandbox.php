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
 * Build request payload for Stripe Tax Calculation API.
 *
 * @param	array	$scenario	Scenario payload
 * @return	array
 */
function buildTaxCalculationPayload( array $scenario )
{
	$payload = array(
		'currency' => 'eur',
		'line_items' => array(
			array(
				'amount' => 500,
				'reference' => 'propass_matrix_' . $scenario['code'],
				'tax_behavior' => 'exclusive',
				'tax_code' => 'txcd_10103000',
			),
		),
		'customer_details' => array(
			'address_source' => 'billing',
			'address' => $scenario['address'],
		),
	);

	if ( isset( $scenario['tax_ids'] ) && \is_array( $scenario['tax_ids'] ) && \count( $scenario['tax_ids'] ) )
	{
		$payload['customer_details']['tax_ids'] = $scenario['tax_ids'];
	}

	return $payload;
}

/**
 * Execute Stripe Tax Calculation API call.
 *
 * @param	array	$payload	API payload
 * @param	array	$settings	Gateway settings
 * @return	array
 */
function createTaxCalculation( array $payload, array $settings )
{
	$response = \IPS\Http\Url::external( 'https://api.stripe.com/v1/tax/calculations' )
		->request( 20 )
		->setHeaders( array(
			'Authorization' => 'Bearer ' . $settings['secret'],
			'Stripe-Version' => \IPS\xpolarcheckout\XPolarCheckout::STRIPE_VERSION,
		) )
		->post( $payload )
		->decodeJson();

	if ( !\is_array( $response ) )
	{
		return array();
	}

	return $response;
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

$scenarios = array(
	array(
		'code' => 'de_b2c',
		'label' => 'DE B2C',
		'address' => array(
			'country' => 'DE',
			'postal_code' => '10115',
			'city' => 'Berlin',
			'line1' => 'Invalidenstrasse 116',
		),
	),
	array(
		'code' => 'eu_b2c_oss',
		'label' => 'EU B2C (OSS)',
		'address' => array(
			'country' => 'FR',
			'postal_code' => '75001',
			'city' => 'Paris',
			'line1' => '1 Rue de Rivoli',
		),
	),
	array(
		'code' => 'eu_b2b_vat',
		'label' => 'EU B2B with VAT ID',
		'address' => array(
			'country' => 'FR',
			'postal_code' => '75001',
			'city' => 'Paris',
			'line1' => '1 Rue de Rivoli',
		),
		'tax_ids' => array(
			array(
				'type' => 'eu_vat',
				'value' => 'FR40303265045',
			),
		),
	),
	array(
		'code' => 'non_eu_customer',
		'label' => 'Non-EU customer',
		'address' => array(
			'country' => 'US',
			'state' => 'CA',
			'postal_code' => '94105',
			'city' => 'San Francisco',
			'line1' => '510 Townsend St',
		),
	),
);

$results = array();
foreach ( $scenarios as $scenario )
{
	$payload = buildTaxCalculationPayload( $scenario );
	$response = createTaxCalculation( $payload, $settings );

	assertOrFail( \is_array( $response ) && empty( $response['error'] ), 'Stripe Tax calculation failed for scenario: ' . $scenario['label'] );
	assertOrFail( isset( $response['amount_total'] ) && \is_numeric( $response['amount_total'] ), 'Missing amount_total for scenario: ' . $scenario['label'] );
	assertOrFail( isset( $response['tax_amount_exclusive'] ) && \is_numeric( $response['tax_amount_exclusive'] ), 'Missing tax_amount_exclusive for scenario: ' . $scenario['label'] );
	assertOrFail( isset( $response['tax_breakdown'] ) && \is_array( $response['tax_breakdown'] ), 'Missing tax_breakdown for scenario: ' . $scenario['label'] );

	$firstReason = NULL;
	if ( isset( $response['tax_breakdown'][0]['taxability_reason'] ) && \is_string( $response['tax_breakdown'][0]['taxability_reason'] ) )
	{
		$firstReason = $response['tax_breakdown'][0]['taxability_reason'];
	}

	$results[] = array(
		'scenario' => $scenario['label'],
		'tax_amount_exclusive_minor' => (int) $response['tax_amount_exclusive'],
		'amount_total_minor' => (int) $response['amount_total'],
		'currency' => isset( $response['currency'] ) ? \strtoupper( (string) $response['currency'] ) : 'EUR',
		'first_taxability_reason' => $firstReason,
	);
}

assertOrFail( \count( $results ) === 4, 'VAT matrix did not produce four scenario results.' );

$hasNonZeroTax = FALSE;
$hasZeroTax = FALSE;
foreach ( $results as $row )
{
	if ( $row['tax_amount_exclusive_minor'] > 0 )
	{
		$hasNonZeroTax = TRUE;
	}
	if ( $row['tax_amount_exclusive_minor'] === 0 )
	{
		$hasZeroTax = TRUE;
	}
}

assertOrFail( $hasNonZeroTax, 'VAT matrix did not produce any taxable scenario (non-zero tax).' );
assertOrFail( $hasZeroTax, 'VAT matrix did not produce any zero-tax scenario (expected for at least one scenario).' );

\fwrite( STDOUT, "PASS: xpolarcheckout DE/EU VAT matrix sandbox checks\n" );
foreach ( $results as $row )
{
	\fwrite( STDOUT, $row['scenario'] . ': tax=' . (string) $row['tax_amount_exclusive_minor'] . ' ' . $row['currency'] . ', reason=' . ( $row['first_taxability_reason'] ?? 'n/a' ) . "\n" );
}
exit( 0 );
