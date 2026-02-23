<?php
/**
 * @brief       Polar Product Mapping Viewer
 * @author      XENNTEC UG
 * @package     IPS Community Suite
 * @subpackage  X Polar Checkout
 * @since       23 Feb 2026
 */

namespace IPS\xpolarcheckout\modules\admin\monitoring;

if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * ACP viewer for IPS package ↔ Polar product mappings.
 */
class _products extends \IPS\Dispatcher\Controller
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
		\IPS\Dispatcher::i()->checkAcpPermission( 'products_view' );
		parent::execute();
	}

	/**
	 * View product mappings table.
	 *
	 * @return	void
	 */
	protected function manage()
	{
		$table = new \IPS\Helpers\Table\Db( 'xpc_product_map', \IPS\Http\Url::internal( 'app=xpolarcheckout&module=monitoring&controller=products' ) );
		$table->langPrefix = 'xpc_product_map_';
		$table->sortBy = $table->sortBy ?: 'updated_at';
		$table->sortDirection = $table->sortDirection ?: 'desc';

		$table->include = array( 'ips_package_id', 'product_name', 'polar_product_id', 'updated_at' );
		$table->mainColumn = 'product_name';

		/* Quick search by product name */
		$table->quickSearch = function( $val )
		{
			return array( 'product_name LIKE ?', '%' . $val . '%' );
		};

		/* Custom parsers */
		$table->parsers = array(
			'ips_package_id' => function( $val )
			{
				return (int) $val;
			},
			'product_name' => function( $val )
			{
				return \htmlspecialchars( (string) $val, ENT_QUOTES | ENT_DISALLOWED, 'UTF-8', FALSE );
			},
			'polar_product_id' => function( $val )
			{
				return \htmlspecialchars( (string) $val, ENT_QUOTES | ENT_DISALLOWED, 'UTF-8', FALSE );
			},
			'updated_at' => function( $val )
			{
				return (int) $val > 0 ? \IPS\DateTime::ts( (int) $val )->localeDate() . ' ' . \IPS\DateTime::ts( (int) $val )->localeTime() : '-';
			},
		);

		/* Sync All Names button */
		$syncUrl = \IPS\Http\Url::internal( 'app=xpolarcheckout&module=monitoring&controller=products&do=syncAll' )->csrf();
		$syncLabel = \IPS\Member::loggedIn()->language()->addToStack( 'xpc_sync_all_names' );
		$buttons = '<a href="' . $syncUrl . '" class="ipsButton ipsButton--small ipsButton--primary" data-confirm>' . $syncLabel . '</a>';

		\IPS\Output::i()->title = \IPS\Member::loggedIn()->language()->addToStack( 'xpc_product_map_title' );
		\IPS\Output::i()->output = $buttons . (string) $table;
	}

	/**
	 * Sync all product names from IPS packages to Polar.
	 *
	 * @return	void
	 */
	protected function syncAll()
	{
		\IPS\Session::i()->csrfCheck();

		$gateway = NULL;
		try
		{
			foreach ( \IPS\nexus\Gateway::roots() as $gw )
			{
				if ( $gw instanceof \IPS\xpolarcheckout\XPolarCheckout )
				{
					$gateway = $gw;
					break;
				}
			}
		}
		catch ( \Exception $e ) {}

		if ( $gateway === NULL )
		{
			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=xpolarcheckout&module=monitoring&controller=products' ),
				'xpc_sync_no_gateway'
			);
			return;
		}

		$settings = \json_decode( $gateway->settings, TRUE );
		if ( !\is_array( $settings ) )
		{
			$settings = array();
		}

		$accessToken = isset( $settings['access_token'] ) ? \trim( (string) $settings['access_token'] ) : '';
		$environment = isset( $settings['environment'] ) ? (string) $settings['environment'] : 'sandbox';
		$apiBase = ( $environment === 'production' )
			? \IPS\xpolarcheckout\XPolarCheckout::POLAR_API_BASE_PRODUCTION
			: \IPS\xpolarcheckout\XPolarCheckout::POLAR_API_BASE_SANDBOX;
		$synced = 0;

		try
		{
			$rows = \IPS\Db::i()->select( '*', 'xpc_product_map' );
			foreach ( $rows as $row )
			{
				$packageId = (int) $row['ips_package_id'];
				$storedName = (string) $row['product_name'];
				$polarProductId = (string) $row['polar_product_id'];

				if ( $packageId <= 0 || $polarProductId === '' )
				{
					continue;
				}

				/* Fetch current IPS package name */
				$currentName = '';
				try
				{
					$package = \IPS\nexus\Package::load( $packageId );
					$currentName = \trim( (string) $package->_title );
				}
				catch ( \OutOfRangeException $e )
				{
					continue;
				}

				if ( $currentName === '' || $currentName === $storedName )
				{
					continue;
				}

				/* Polar product name minimum is 3 chars */
				if ( \mb_strlen( $currentName ) < 3 )
				{
					$currentName .= ' (item)';
				}

				/* PATCH Polar product */
				try
				{
					$patchPayload = \json_encode( array( 'name' => $currentName ) );
					if ( \is_string( $patchPayload ) && $accessToken !== '' )
					{
						\IPS\Http\Url::external( $apiBase . '/products/' . $polarProductId )
							->request( 15 )
							->setHeaders( array(
								'Authorization' => 'Bearer ' . $accessToken,
								'Content-Type' => 'application/json',
								'Accept' => 'application/json',
							) )
							->patch( $patchPayload );
					}

					\IPS\Db::i()->update( 'xpc_product_map', array(
						'product_name' => $currentName,
						'updated_at' => \time(),
					), array( 'map_id=?', (int) $row['map_id'] ) );

					$synced++;
				}
				catch ( \Exception $e )
				{
					\IPS\Log::log( $e, 'xpolarcheckout_product_map' );
				}
			}
		}
		catch ( \Exception $e )
		{
			\IPS\Log::log( $e, 'xpolarcheckout_product_map' );
		}

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=xpolarcheckout&module=monitoring&controller=products' ),
			\IPS\Member::loggedIn()->language()->addToStack( 'xpc_sync_complete' )
		);
	}
}
