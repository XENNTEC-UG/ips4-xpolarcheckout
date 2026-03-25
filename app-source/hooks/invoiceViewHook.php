//<?php

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	exit;
}

class xpolarcheckout_hook_invoiceViewHook extends _HOOK_CLASS_
{
	/**
	 * View single invoice — global Order Details enhancements + Polar settlement
	 */
	public function view()
	{
		parent::view();

		try
		{
			if ( !isset( $this->invoice ) )
			{
				return;
			}

			$output = \IPS\Output::i()->output;

			/* === GLOBAL enhancements (all invoices) === */
			$output = \IPS\xpolarcheckout\Invoice\ViewHelper::enhanceOrderDetails( $output, $this->invoice );

			/* === POLAR-ONLY enhancements === */
			$extra = $this->invoice->status_extra;
			if ( isset( $extra['xpolarcheckout_snapshot'] ) && \is_array( $extra['xpolarcheckout_snapshot'] ) )
			{
				$snapshot = $extra['xpolarcheckout_snapshot'];

				$polarSummary = \IPS\xpolarcheckout\Invoice\ViewHelper::buildPolarSummary( $snapshot );
				$paymentRefs  = \IPS\xpolarcheckout\Invoice\ViewHelper::buildPaymentRefs( $snapshot );
				$output       = \IPS\xpolarcheckout\Invoice\ViewHelper::wrapInColumns( $output, $polarSummary );
				$output       = \IPS\xpolarcheckout\Invoice\ViewHelper::insertPaymentRefs( $output, $paymentRefs );
			}

			\IPS\Output::i()->output = $output;
		}
		catch ( \Throwable $e )
		{
			/* Silently fail — parent already set base output */
		}
	}
}
