<?php
/**
 * XENNTEC License Checker — do not modify
 *
 * @package     X Polar Checkout
 * @copyright   (c) 2026 XENNTEC UG
 */

namespace IPS\xpolarcheckout\tasks;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Hourly task that keeps the license status cache up to date.
 *
 * Delegates entirely to Checker::runCheck(), which only performs a remote
 * request when the local cache has actually expired.
 */
class _licenseCheck extends \IPS\Task
{
	/**
	 * Execute
	 *
	 * Runs a license check if one is due. Returns NULL in all cases — there is
	 * nothing worth logging on a routine cache refresh.
	 *
	 * @return mixed Message to log or NULL.
	 * @throws \IPS\Task\Exception
	 */
	public function execute(): mixed
	{
		\IPS\xpolarcheckout\License\Checker::runCheck();
		return null;
	}

	/**
	 * Cleanup
	 *
	 * Called before execute() if the previous run took longer than 15 minutes.
	 * Nothing to clean up for a stateless license check.
	 *
	 * @return void
	 */
	public function cleanup(): void
	{
	}
}
