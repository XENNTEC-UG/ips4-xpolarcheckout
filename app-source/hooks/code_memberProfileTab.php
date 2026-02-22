//<?php

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	exit;
}

class xpolarcheckout_hook_code_memberProfileTab extends _HOOK_CLASS_
{
	/**
	 * Get left-column blocks — append Stripe Dispute Summary block
	 *
	 * @return	array
	 */
	public function leftColumnBlocks(): array
	{
		try
		{
			$return = parent::leftColumnBlocks();
			$return[] = 'IPS\xpolarcheckout\extensions\core\MemberACPProfileBlocks\StripeDisputeSummary';
			return $return;
		}
		catch ( \Throwable $e )
		{
			return parent::leftColumnBlocks();
		}
	}
}
