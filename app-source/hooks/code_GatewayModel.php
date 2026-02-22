//<?php

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	exit;
}

class xpolarcheckout_hook_code_GatewayModel extends _HOOK_CLASS_
{


	/**
	 * Gateways
	 * @xenntec.com
	 * @return	array
	 */
	public static function gateways()
	{
		try
		{
			$array = parent::gateways();
			$array['XPolarCheckout'] = 'IPS\\xpolarcheckout\\XPolarCheckout';
			return $array;
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
