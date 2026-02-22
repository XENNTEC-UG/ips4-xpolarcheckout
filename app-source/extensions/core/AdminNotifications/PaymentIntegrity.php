<?php

namespace IPS\xpolarcheckout\extensions\core\AdminNotifications;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * ACP Notification: Stripe Payment Integrity Alerts
 */
class _PaymentIntegrity extends \IPS\core\AdminNotification
{
	/**
	 * @brief	Identifier for what to group this notification type with on the settings form
	 */
	public static $group = 'commerce';

	/**
	 * @brief	Priority 1-5 (1 being highest) for this group compared to others
	 */
	public static $groupPriority = 4;

	/**
	 * @brief	Priority 1-5 (1 being highest) for this notification type compared to others in the same group
	 */
	public static $itemPriority = 1;

	/**
	 * Check for any issues we may need to send a notification about.
	 *
	 * @return	void
	 */
	public static function runChecksAndSendNotifications()
	{
		try
		{
			$stats = \IPS\xpolarcheckout\XPolarCheckout::collectAlertStats();
		}
		catch ( \Throwable $e )
		{
			\IPS\Log::log( $e, 'xpolarcheckout_integrity_monitor' );
			return;
		}

		/* Webhook errors in last 24h */
		if ( (int) $stats['webhook_error_count_24h'] > 0 )
		{
			\IPS\core\AdminNotification::send( 'xpolarcheckout', 'PaymentIntegrity', 'webhook_errors', TRUE );
		}
		else
		{
			\IPS\core\AdminNotification::remove( 'xpolarcheckout', 'PaymentIntegrity', 'webhook_errors' );
		}

		/* Replay task stale */
		if ( $stats['replay_recent_run'] === FALSE )
		{
			\IPS\core\AdminNotification::send( 'xpolarcheckout', 'PaymentIntegrity', 'replay_stale', TRUE );
		}
		else
		{
			\IPS\core\AdminNotification::remove( 'xpolarcheckout', 'PaymentIntegrity', 'replay_stale' );
		}

		/* Stripe-vs-IPS mismatches in last 30 days */
		if ( (int) $stats['mismatch_count_30d'] > 0 )
		{
			\IPS\core\AdminNotification::send( 'xpolarcheckout', 'PaymentIntegrity', 'mismatches', TRUE );
		}
		else
		{
			\IPS\core\AdminNotification::remove( 'xpolarcheckout', 'PaymentIntegrity', 'mismatches' );
		}

		/* Endpoint drift is not checked here — fetchWebhookEndpoint() requires
		   a Stripe API call which is too heavy for a 5-minute polling task.
		   Drift is visible on the integrity panel instead. */

		/* Tax not collecting */
		if ( $stats['tax_readiness_status'] !== 'collecting' AND $stats['tax_readiness_status'] !== 'unknown' )
		{
			\IPS\core\AdminNotification::send( 'xpolarcheckout', 'PaymentIntegrity', 'tax_not_collecting', TRUE );
		}
		else
		{
			\IPS\core\AdminNotification::remove( 'xpolarcheckout', 'PaymentIntegrity', 'tax_not_collecting' );
		}
	}

	/**
	 * Title for settings.
	 *
	 * @return	string
	 */
	public static function settingsTitle()
	{
		return 'acp_notification_PaymentIntegrity';
	}

	/**
	 * Is this type of notification ever optional?
	 *
	 * @return	bool
	 */
	public static function mayBeOptional()
	{
		return TRUE;
	}

	/**
	 * Is this type of notification might recur?
	 *
	 * @return	bool
	 */
	public static function mayRecur()
	{
		return TRUE;
	}

	/**
	 * Can a member access this type of notification?
	 *
	 * @param	\IPS\Member	$member	The member
	 * @return	bool
	 */
	public static function permissionCheck( \IPS\Member $member )
	{
		return $member->hasAcpRestriction( 'xpolarcheckout', 'monitoring', 'integrity_view' );
	}

	/**
	 * Can a member view this notification?
	 *
	 * @param	\IPS\Member	$member	The member
	 * @return	bool
	 */
	public function visibleTo( \IPS\Member $member )
	{
		return static::permissionCheck( $member );
	}

	/**
	 * Notification Title (full HTML, must be escaped where necessary).
	 *
	 * @return	string
	 */
	public function title()
	{
		return \IPS\Member::loggedIn()->language()->addToStack( 'xsc_alert_' . $this->extra . '_title' );
	}

	/**
	 * Notification Subtitle (no HTML).
	 *
	 * @return	string|NULL
	 */
	public function subtitle()
	{
		try
		{
			$stats = \IPS\xpolarcheckout\XPolarCheckout::collectAlertStats();
		}
		catch ( \Throwable $e )
		{
			return NULL;
		}

		switch ( $this->extra )
		{
			case 'webhook_errors':
				return \IPS\Member::loggedIn()->language()->addToStack( 'xsc_alert_webhook_errors_subtitle', FALSE, array( 'sprintf' => array( (int) $stats['webhook_error_count_24h'] ) ) );

			case 'replay_stale':
				return \IPS\Member::loggedIn()->language()->addToStack( 'xsc_alert_replay_stale_subtitle' );

			case 'mismatches':
				return \IPS\Member::loggedIn()->language()->addToStack( 'xsc_alert_mismatches_subtitle', FALSE, array( 'sprintf' => array( (int) $stats['mismatch_count_30d'] ) ) );

			case 'tax_not_collecting':
				return \IPS\Member::loggedIn()->language()->addToStack( 'xsc_alert_tax_not_collecting_subtitle', FALSE, array( 'sprintf' => array( $stats['tax_readiness_status'] ) ) );

			default:
				return NULL;
		}
	}

	/**
	 * Notification Body (full HTML, must be escaped where necessary).
	 *
	 * @return	string
	 */
	public function body()
	{
		return \IPS\Member::loggedIn()->language()->addToStack( 'xsc_alert_' . $this->extra . '_body' );
	}

	/**
	 * Severity.
	 *
	 * @return	string
	 */
	public function severity()
	{
		if ( \in_array( $this->extra, array( 'webhook_errors', 'replay_stale' ), TRUE ) )
		{
			return static::SEVERITY_HIGH;
		}

		return static::SEVERITY_NORMAL;
	}

	/**
	 * Dismissible?
	 *
	 * @return	string
	 */
	public function dismissible()
	{
		return static::DISMISSIBLE_TEMPORARY;
	}

	/**
	 * Style.
	 *
	 * @return	string
	 */
	public function style()
	{
		if ( $this->extra === 'webhook_errors' )
		{
			return static::STYLE_ERROR;
		}

		return static::STYLE_WARNING;
	}

	/**
	 * Quick link from popup menu.
	 *
	 * @return	\IPS\Http\Url
	 */
	public function link()
	{
		return \IPS\Http\Url::internal( 'app=xpolarcheckout&module=monitoring&controller=integrity', 'admin' );
	}

	/**
	 * Should this notification dismiss itself?
	 *
	 * @note	This is checked every time the notification shows. Should be lightweight.
	 * @return	bool
	 */
	public function selfDismiss()
	{
		try
		{
			$stats = \IPS\xpolarcheckout\XPolarCheckout::collectAlertStats();
		}
		catch ( \Throwable $e )
		{
			return FALSE;
		}

		switch ( $this->extra )
		{
			case 'webhook_errors':
				return (int) $stats['webhook_error_count_24h'] === 0;

			case 'replay_stale':
				return $stats['replay_recent_run'] === TRUE;

			case 'mismatches':
				return (int) $stats['mismatch_count_30d'] === 0;

			case 'tax_not_collecting':
				return $stats['tax_readiness_status'] === 'collecting' OR $stats['tax_readiness_status'] === 'unknown';

			default:
				return FALSE;
		}
	}
}
