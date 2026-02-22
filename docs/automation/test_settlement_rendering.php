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
 * @param	string	$needle	Needle
 * @param	string	$haystack	Haystack
 * @param	string	$message	Message on failure
 * @return	void
 */
function assertContains( $needle, $haystack, $message )
{
	assertOrFail( \strpos( (string) $haystack, (string) $needle ) !== FALSE, $message );
}

$hookRows = iterator_to_array(
	\IPS\Db::i()->select(
		'filename',
		'core_hooks',
		array( 'app=? AND filename IN(?,?)', 'xpolarcheckout', 'theme_sc_clients_settle', 'theme_sc_print_settle' )
	)
);

assertOrFail( \count( $hookRows ) === 2, 'Expected both settlement hooks to be registered in core_hooks.' );
assertOrFail( (int) \IPS\Db::i()->select( 'app_enabled', 'core_applications', array( 'app_directory=?', 'xpolarcheckout' ) )->first() === 1, 'xpolarcheckout app is not enabled in core_applications.' );

$clientHookFile = '/workspace/ips-dev-source/apps/xpolarcheckout/app-source/hooks/theme_sc_clients_settle.php';
$printHookFile = '/workspace/ips-dev-source/apps/xpolarcheckout/app-source/hooks/theme_sc_print_settle.php';
assertOrFail( \file_exists( $clientHookFile ), 'Client settlement hook file missing.' );
assertOrFail( \file_exists( $printHookFile ), 'Print settlement hook file missing.' );

$clientHookSource = (string) \file_get_contents( $clientHookFile );
$printHookSource = (string) \file_get_contents( $printHookFile );

assertContains( '.ipsBox--child', $clientHookSource, 'Client hook selector does not target invoice total box.' );
assertContains( 'xpolarcheckout_snapshot', $clientHookSource, 'Client hook content missing snapshot check.' );
assertContains( 'xpolarcheckout_stripe_charged_label', $clientHookSource, 'Client hook content missing Stripe charged label.' );
assertContains( 'xpolarcheckout_ips_invoice_total_label', $clientHookSource, 'Client hook content missing IPS total label.' );
assertContains( 'amount_total_display', $clientHookSource, 'Client hook content missing Stripe total display.' );

assertContains( '.ipsPrint > table', $printHookSource, 'Print hook selector does not target print invoice table.' );
assertContains( 'xpolarcheckout_settle_title', $printHookSource, 'Print hook content missing settlement title.' );
assertContains( 'xpolarcheckout_settle_stripe_invoice_id', $printHookSource, 'Print hook content missing Stripe invoice row.' );
assertContains( 'xpolarcheckout_settle_stripe_pi', $printHookSource, 'Print hook content missing Stripe payment intent row.' );

$nexusThemeXmlPath = '/var/www/html/applications/nexus/data/theme.xml';
assertOrFail( \file_exists( $nexusThemeXmlPath ), 'Nexus theme.xml not found in runtime.' );
$nexusThemeXml = (string) \file_get_contents( $nexusThemeXmlPath );
assertContains( 'cNexusInvoiceView', $nexusThemeXml, 'Nexus invoice template source no longer contains cNexusInvoiceView anchor.' );
assertContains( 'ipsPrint', $nexusThemeXml, 'Nexus print invoice template source no longer contains ipsPrint container.' );

try
{
	$invoiceId = \IPS\Db::i()->select(
		'i_id',
		'nexus_invoices',
		"JSON_EXTRACT(i_status_extra, '$.xpolarcheckout_snapshot') IS NOT NULL",
		'i_id DESC',
		1
	)->first();
}
catch ( \UnderflowException $e )
{
	\fwrite( STDERR, "ASSERTION FAILED: No invoices with xpolarcheckout snapshot data found.\n" );
	exit( 1 );
}

$invoice = \IPS\nexus\Invoice::load( $invoiceId );
assertOrFail( \is_array( $invoice->status_extra ) && isset( $invoice->status_extra['xpolarcheckout_snapshot'] ), 'Latest snapshot invoice is missing status_extra snapshot payload.' );
$snapshot = $invoice->status_extra['xpolarcheckout_snapshot'];
assertOrFail( isset( $snapshot['customer_invoice_url'] ) && \is_string( $snapshot['customer_invoice_url'] ) && $snapshot['customer_invoice_url'] !== '', 'Snapshot missing customer_invoice_url.' );
assertOrFail( isset( $snapshot['customer_invoice_pdf_url'] ) && \is_string( $snapshot['customer_invoice_pdf_url'] ) && $snapshot['customer_invoice_pdf_url'] !== '', 'Snapshot missing customer_invoice_pdf_url.' );
assertOrFail( isset( $snapshot['customer_receipt_url'] ) && \is_string( $snapshot['customer_receipt_url'] ) && $snapshot['customer_receipt_url'] !== '', 'Snapshot missing customer_receipt_url.' );
assertOrFail( \strpos( (string) $invoice->notes, '[[STRIPECHECKOUT_SETTLEMENT_BEGIN]]' ) === FALSE, 'Legacy Stripe notes marker still present in invoice notes.' );
assertOrFail( \strpos( (string) $invoice->notes, '[[XSTRIPECHECKOUT_SETTLEMENT_BEGIN]]' ) === FALSE, 'Legacy XPolarCheckout notes marker still present in invoice notes.' );

\fwrite( STDOUT, "PASS: xpolarcheckout settlement block rendering checks\n" );
\fwrite( STDOUT, "Validated hooks: theme_sc_clients_settle, theme_sc_print_settle\n" );
\fwrite( STDOUT, "Validated invoice snapshot: #" . (string) $invoice->id . "\n" );
exit( 0 );
