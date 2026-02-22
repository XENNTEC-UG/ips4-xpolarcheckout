//<?php

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	exit;
}

class xpolarcheckout_hook_code_loadJs extends _HOOK_CLASS_
{


	/**
	 * Init - catches legacy PayPal IPN messages
	 *
	 * @return	void
	 */
	public function init()
	{
		try
		{
	      	if ( !\IPS\Request::i()->isAjax() )
			{
				if ( \IPS\Settings::i()->gateways_counts and $decoded = json_decode( \IPS\Settings::i()->gateways_counts, TRUE ) and isset( $decoded['XPolarCheckout'] ) and $decoded['XPolarCheckout'] > 0 )
				{
					\IPS\Output::i()->jsFiles[] = 'https://js.stripe.com/v3/';
				}
			}
	      
			return parent::init();
		}
		catch ( \Throwable $e )
		{
			if( \defined( '\IPS\DEBUG_HOOKS' ) AND \IPS\DEBUG_HOOKS )
			{
				\IPS\Log::log( $e, 'hook_exception' );
			}

			if ( method_exists( get_parent_class(), __FUNCTION__ ) )
			{
				return \call_user_func_array( 'parent::' . __FUNCTION__, \func_get_args() );
			}
			else
			{
				throw $e;
			}
		}
	}

}
