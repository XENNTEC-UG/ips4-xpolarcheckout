//<?php

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	exit;
}

class xpolarcheckout_hook_couponNameHook extends _HOOK_CLASS_
{
	/**
	 * Use coupon — override name to "Coupon: CODE" for all purchases
	 *
	 * @param	\IPS\nexus\Invoice	$invoice	Invoice to use against
	 * @param	\IPS\nexus\Customer	$customer	The customer using
	 * @return	\IPS\nexus\extensions\nexus\Item\CouponDiscount
	 * @throws	\DomainException
	 */
	public function useCoupon( \IPS\nexus\Invoice $invoice, \IPS\nexus\Customer $customer )
	{
		$item = parent::useCoupon( $invoice, $customer );

		try
		{
			if ( !empty( $this->code ) )
			{
				$item->name = 'Coupon: ' . $this->code;
			}
		}
		catch ( \Throwable $e )
		{
			/* Silently fall back to default name */
		}

		return $item;
	}
}
