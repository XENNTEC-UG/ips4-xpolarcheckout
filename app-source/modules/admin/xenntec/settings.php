<?php
/**
 * @brief		X Polar Checkout ACP Settings Controller
 * @author		<a href='https://xenntec.com/'>XENNTEC UG</a>
 * @copyright	(c) 2026 XENNTEC UG
 */

namespace IPS\xpolarcheckout\modules\admin\xenntec;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Settings management page
 */
class _settings extends \IPS\Dispatcher\Controller
{
	/**
	 * Display and save gateway settings.
	 *
	 * @return void
	 */
	public function manage(): void
	{
		if ( !\in_array( \IPS\xpolarcheckout\License\Checker::getStatus(), [
			\IPS\xpolarcheckout\License\Checker::STATUS_VALID,
			\IPS\xpolarcheckout\License\Checker::STATUS_EXPIRING,
			\IPS\xpolarcheckout\License\Checker::STATUS_GRACE,
		], true ) ) {
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( 'app=xpolarcheckout&module=xenntec&controller=license' ) );
		}

		\IPS\Dispatcher::i()->checkAcpPermission( 'manage' );
	}
}
