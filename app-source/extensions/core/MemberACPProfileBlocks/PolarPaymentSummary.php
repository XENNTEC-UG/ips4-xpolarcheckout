<?php
/**
 * @brief		ACP Member Profile Block — Polar Payments & Refunds
 * @author		Fundryi
 * @package		Invision Community
 * @subpackage	X Polar Checkout
 * @since		17 Feb 2026
 */

namespace IPS\xpolarcheckout\extensions\core\MemberACPProfileBlocks;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * ACP Member Profile Block — Polar payment/refund summary
 */
class _PolarPaymentSummary extends \IPS\core\MemberACPProfile\Block
{
	/**
	 * Get output
	 *
	 * @return	string|NULL
	 */
	public function output()
	{
		try
		{
			$gatewayIds = array();
			foreach ( \IPS\nexus\Gateway::roots() as $gateway )
			{
				if ( $gateway instanceof \IPS\xpolarcheckout\XPolarCheckout )
				{
					$gatewayIds[] = $gateway->id;
				}
			}

			if ( empty( $gatewayIds ) )
			{
				return NULL;
			}

			$where = array(
				array( \IPS\Db::i()->in( 't_method', $gatewayIds ) ),
				array( 't_member=?', $this->member->member_id ),
			);

			$totalCount = 0;
			$refundCount = 0;
			$latestTs = NULL;

			$transactions = \IPS\Db::i()->select( 't_id, t_status, t_extra', 'nexus_transactions', $where, 't_id DESC' );
			foreach ( $transactions as $row )
			{
				$totalCount++;
				if ( $row['t_status'] === 'rfnd' OR $row['t_status'] === 'prfd' )
				{
					$refundCount++;
				}

				$extra = \json_decode( $row['t_extra'], TRUE );
				if ( \is_array( $extra ) AND isset( $extra['history'] ) AND \is_array( $extra['history'] ) )
				{
					foreach ( $extra['history'] as $event )
					{
						if ( \is_array( $event ) AND isset( $event['on'] ) AND \is_numeric( $event['on'] ) )
						{
							$ts = (int) $event['on'];
							if ( $latestTs === NULL OR $ts > $latestTs )
							{
								$latestTs = $ts;
							}
						}
					}
				}
			}

			if ( $totalCount === 0 )
			{
				return NULL;
			}

			$html = "<div class='ipsBox'>";
			$html .= "<h2 class='ipsType_sectionTitle ipsType_reset'>" . \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_dispute_summary' ) . "</h2>";
			$html .= "<div class='ipsPad'><ul class='ipsDataList ipsDataList_reducedSpacing'>";

			$html .= "<li class='ipsDataItem'>";
			$html .= "<div class='ipsDataItem_main'>Transactions</div>";
			$html .= "<div class='ipsDataItem_generic ipsType_right'>{$totalCount}</div>";
			$html .= "</li>";

			$html .= "<li class='ipsDataItem'>";
			$html .= "<div class='ipsDataItem_main'>" . \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_refunds_count' ) . "</div>";
			$html .= "<div class='ipsDataItem_generic ipsType_right'>{$refundCount}</div>";
			$html .= "</li>";

			if ( $latestTs !== NULL )
			{
				$html .= "<li class='ipsDataItem'>";
				$html .= "<div class='ipsDataItem_main'>Last activity</div>";
				$html .= "<div class='ipsDataItem_generic ipsType_right'>" . \IPS\DateTime::ts( $latestTs )->localeDate() . "</div>";
				$html .= "</li>";
			}

			$html .= "</ul>";

			$integrityUrl = \IPS\Http\Url::internal( 'app=xpolarcheckout&module=monitoring&controller=integrity' );
			$html .= "<p class='ipsType_reset ipsType_medium ipsSpacer_top ipsSpacer_half'>";
			$html .= "<a href='" . \htmlspecialchars( (string) $integrityUrl, ENT_QUOTES | ENT_DISALLOWED, 'UTF-8' ) . "'>" . \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_view_integrity' ) . "</a>";
			$html .= "</p>";

			$html .= "</div></div>";

			return $html;
		}
		catch ( \Throwable $e )
		{
			\IPS\Log::log( $e, 'xpolarcheckout' );
			return NULL;
		}
	}
}
