<?php
/**
 * @brief		X Polar Checkout ACP License Controller
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
 * License management page
 */
class _license extends \IPS\Dispatcher\Controller
{
	/**
	 * Display current license status and the activation form.
	 *
	 * @return void
	 */
	public function manage(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'manage' );

		$status   = \IPS\xpolarcheckout\License\Checker::getStatus();
		$cache    = \IPS\xpolarcheckout\License\Checker::getCacheData();
		$licKey   = (string) \IPS\Settings::i()->{'xenntec_lic_xpolarcheckout_key'};

		/* Mask the license key for display: show first segment only */
		$maskedKey = '';
		if ( $licKey !== '' )
		{
			$parts  = explode( '-', $licKey );
			$masked = [];
			foreach ( $parts as $i => $part )
			{
				$masked[] = $i === 0 ? $part : str_repeat( '*', strlen( $part ) );
			}
			$maskedKey = implode( '-', $masked );
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( '__app_xpolarcheckout_license' );
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'xenntec', 'xpolarcheckout', 'admin' )->license(
			$status,
			$cache,
			$maskedKey,
			$licKey !== ''
		);
	}

	/**
	 * Save a new license key and perform an immediate remote verification.
	 *
	 * @return void
	 */
	protected function _activate(): void
	{
		\IPS\Session::i()->csrfCheck();
		\IPS\Dispatcher::i()->checkAcpPermission( 'manage' );

		$key = trim( \IPS\Request::i()->license_key ?? '' );

		if ( $key === '' )
		{
			\IPS\Output::i()->error( 'xenntec_lic_key_required', '1X001/1', 403 );
		}

		$status = \IPS\xpolarcheckout\License\Checker::activate( $key );

		$flashMsg = match ( $status ) {
			\IPS\xpolarcheckout\License\Checker::STATUS_VALID,
			\IPS\xpolarcheckout\License\Checker::STATUS_EXPIRING => \IPS\Member::loggedIn()->language()->addToStack( 'xenntec_lic_activated_ok' ),
			default => \IPS\Member::loggedIn()->language()->addToStack( 'xenntec_lic_activated_fail' ),
		};

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=xpolarcheckout&module=xenntec&controller=license' ),
			$flashMsg
		);
	}

	/**
	 * Clear the local license cache and trigger a fresh remote check.
	 *
	 * @return void
	 */
	protected function _recheck(): void
	{
		\IPS\Session::i()->csrfCheck();
		\IPS\Dispatcher::i()->checkAcpPermission( 'manage' );

		\IPS\xpolarcheckout\License\Checker::clearCache();
		\IPS\xpolarcheckout\License\Checker::getStatus();

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=xpolarcheckout&module=xenntec&controller=license' ),
			\IPS\Member::loggedIn()->language()->addToStack( 'xenntec_lic_rechecked' )
		);
	}
}
