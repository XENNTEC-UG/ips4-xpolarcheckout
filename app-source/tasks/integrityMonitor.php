<?php
/**
 * @brief		Polar Checkout Integrity Monitor task
 * @author		<a href='https://xenntec.com/'>XENNTEC UG</a>
 * @copyright	(c) 2026 XENNTEC UG
 * @package		Invision Community
 * @subpackage	X Polar Checkout
 * @since		18 Feb 2026
 */

namespace IPS\xpolarcheckout\tasks;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Lightweight polling task that checks payment integrity metrics
 * and sends/clears ACP admin notifications via the PaymentIntegrity extension.
 *
 * Runs every 5 minutes. Only local DB queries — no external API calls.
 */
class _integrityMonitor extends \IPS\Task
{
	/**
	 * @brief	Forensics retention: 90 days
	 */
	const FORENSICS_RETENTION_DAYS = 90;

	/**
	 * Execute.
	 *
	 * @return	mixed	Message to log or NULL
	 * @throws	\IPS\Task\Exception
	 */
	public function execute()
	{
		try
		{
			\IPS\xpolarcheckout\extensions\core\AdminNotifications\PaymentIntegrity::runChecksAndSendNotifications();
		}
		catch ( \Throwable $e )
		{
			\IPS\Log::log( $e, 'xpolarcheckout_integrity_monitor' );
		}

		/* Prune old forensics entries (once daily via last-cleaned check) */
		try
		{
			$lastCleaned = isset( \IPS\Data\Store::i()->xpc_forensics_last_cleaned ) ? (int) \IPS\Data\Store::i()->xpc_forensics_last_cleaned : 0;
			if ( \time() - $lastCleaned > 86400 )
			{
				$cutoff = \time() - ( static::FORENSICS_RETENTION_DAYS * 86400 );
				\IPS\Db::i()->delete( 'xpc_webhook_forensics', array( 'created_at<?', $cutoff ) );
				\IPS\Data\Store::i()->xpc_forensics_last_cleaned = \time();
			}
		}
		catch ( \Throwable $e )
		{
			\IPS\Log::log( $e, 'xpolarcheckout_forensics_cleanup' );
		}

		return NULL;
	}
}
