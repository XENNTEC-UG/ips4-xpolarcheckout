<?php
/**
 * @brief       Webhook Forensics Log Viewer
 * @author      XENNTEC UG
 * @package     IPS Community Suite
 * @subpackage  X Polar Checkout
 * @since       22 Feb 2026
 */

namespace IPS\xpolarcheckout\modules\admin\monitoring;

if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Webhook Forensics Log — records webhook validation failures for security audit.
 */
class _forensics extends \IPS\Dispatcher\Controller
{
	/**
	 * @brief	Has been CSRF-protected
	 */
	public static $csrfProtected = TRUE;

	/**
	 * Execute
	 *
	 * @return	void
	 */
	public function execute()
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'forensics_view' );
		parent::execute();
	}

	/**
	 * View webhook forensics log.
	 *
	 * @return	void
	 */
	protected function manage()
	{
		$table = new \IPS\Helpers\Table\Db( 'xsc_webhook_forensics', \IPS\Http\Url::internal( 'app=xpolarcheckout&module=monitoring&controller=forensics' ) );
		$table->langPrefix = 'xsc_forensics_';
		$table->sortBy = $table->sortBy ?: 'created_at';
		$table->sortDirection = $table->sortDirection ?: 'desc';

		$table->include = array( 'failure_reason', 'event_type', 'event_id', 'ip_address', 'http_status', 'created_at' );
		$table->mainColumn = 'failure_reason';

		/* Filters by failure reason */
		$table->filters = array(
			'xsc_forensics_filter_invalid_payload'   => 'failure_reason=\'invalid_payload\'',
			'xsc_forensics_filter_missing_signature'  => 'failure_reason=\'missing_signature\'',
			'xsc_forensics_filter_invalid_signature'  => 'failure_reason=\'invalid_signature\'',
			'xsc_forensics_filter_timestamp_too_old'  => 'failure_reason=\'timestamp_too_old\'',
		);

		/* Quick search by IP */
		$table->quickSearch = function( $val )
		{
			return array( 'ip_address LIKE ?', '%' . $val . '%' );
		};

		/* Custom parsers */
		$table->parsers = array(
			'failure_reason' => function( $val )
			{
				$key = 'xsc_forensics_reason_' . $val;

				return \IPS\Member::loggedIn()->language()->addToStack( $key );
			},
			'event_type' => function( $val )
			{
				return ( $val !== '' ) ? \htmlspecialchars( $val, ENT_QUOTES | ENT_DISALLOWED, 'UTF-8', FALSE ) : '-';
			},
			'event_id' => function( $val )
			{
				return ( $val !== NULL && $val !== '' ) ? \htmlspecialchars( $val, ENT_QUOTES | ENT_DISALLOWED, 'UTF-8', FALSE ) : '-';
			},
			'created_at' => function( $val )
			{
				return (int) $val > 0 ? \IPS\DateTime::ts( (int) $val )->localeDate() . ' ' . \IPS\DateTime::ts( (int) $val )->localeTime() : '-';
			},
		);

		\IPS\Output::i()->title = \IPS\Member::loggedIn()->language()->addToStack( 'xsc_forensics_title' );
		\IPS\Output::i()->output = (string) $table;
	}
}
