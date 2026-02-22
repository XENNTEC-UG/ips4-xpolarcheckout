<?php
/**
 * @brief		ACP Member Profile Block — Stripe Disputes & Refunds
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
 * ACP Member Profile Block — Stripe Disputes & Refunds summary
 */
class _StripeDisputeSummary extends \IPS\core\MemberACPProfile\Block
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
			/* Find XPolarCheckout gateway IDs */
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

			/* Query transactions for this member with dispute/refund/partial-refund status */
			$where = array(
				array( \IPS\Db::i()->in( 't_method', $gatewayIds ) ),
				array( 't_member=?', $this->member->member_id ),
				array( \IPS\Db::i()->in( 't_status', array( 'dspd', 'rfnd', 'prfd' ) ) ),
			);

			$disputeCount = 0;
			$refundCount = 0;
			$latestDispute = NULL;

			$transactions = \IPS\Db::i()->select( 't_id, t_status, t_extra', 'nexus_transactions', $where, 't_id DESC' );
			foreach ( $transactions as $row )
			{
				$extra = json_decode( $row['t_extra'], TRUE );

				if ( $row['t_status'] === 'dspd' )
				{
					$disputeCount++;
					if ( $latestDispute === NULL AND isset( $extra['xpolarcheckout_dispute'] ) AND \is_array( $extra['xpolarcheckout_dispute'] ) )
					{
						$latestDispute = $extra['xpolarcheckout_dispute'];
					}
				}
				elseif ( $row['t_status'] === 'rfnd' OR $row['t_status'] === 'prfd' )
				{
					$refundCount++;
				}
			}

			/* Nothing to show */
			if ( $disputeCount === 0 AND $refundCount === 0 )
			{
				return NULL;
			}

			/* Check ban status */
			$isBanned = ( $this->member->temp_ban == -1 );

			/* Build output HTML */
			$html = "<div class='ipsBox'>";
			$html .= "<h2 class='ipsType_sectionTitle ipsType_reset'>" . \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_dispute_summary' ) . "</h2>";
			$html .= "<div class='ipsPad'><ul class='ipsDataList ipsDataList_reducedSpacing'>";

			/* Chargebacks row */
			$html .= "<li class='ipsDataItem'>";
			$html .= "<div class='ipsDataItem_main'>" . \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_chargebacks_count' ) . "</div>";
			$html .= "<div class='ipsDataItem_generic ipsType_right'>";
			if ( $disputeCount > 0 )
			{
				$html .= "<span class='ipsType_warning'><strong>{$disputeCount}</strong></span>";
			}
			else
			{
				$html .= "0";
			}
			$html .= "</div></li>";

			/* Latest dispute detail */
			if ( $latestDispute !== NULL )
			{
				$html .= "<li class='ipsDataItem'>";
				$html .= "<div class='ipsDataItem_main'>" . \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_latest_dispute' ) . "</div>";
				$html .= "<div class='ipsDataItem_generic ipsType_right'>";
				if ( isset( $latestDispute['reason'] ) )
				{
					$html .= htmlspecialchars( $latestDispute['reason'] );
				}
				if ( isset( $latestDispute['created'] ) )
				{
					$html .= " (" . htmlspecialchars( $latestDispute['created'] ) . ")";
				}
				$html .= "</div></li>";

				/* Evidence deadline */
				if ( isset( $latestDispute['evidence_due_by'] ) )
				{
					$html .= "<li class='ipsDataItem'>";
					$html .= "<div class='ipsDataItem_main'>" . \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_evidence_deadline' ) . "</div>";
					$html .= "<div class='ipsDataItem_generic ipsType_right'>" . \IPS\DateTime::ts( (int) $latestDispute['evidence_due_by'] )->localeDate() . "</div>";
					$html .= "</li>";
				}
			}

			/* Refunds row */
			$html .= "<li class='ipsDataItem'>";
			$html .= "<div class='ipsDataItem_main'>" . \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_refunds_count' ) . "</div>";
			$html .= "<div class='ipsDataItem_generic ipsType_right'>{$refundCount}</div>";
			$html .= "</li>";

			/* Ban status */
			$html .= "<li class='ipsDataItem'>";
			$html .= "<div class='ipsDataItem_main'>" . \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_ban_status' ) . "</div>";
			$html .= "<div class='ipsDataItem_generic ipsType_right'>";
			if ( $isBanned )
			{
				$html .= "<span class='ipsType_warning'>" . \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_banned_chargeback' ) . "</span>";
			}
			else
			{
				$html .= \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_not_banned' );
			}
			$html .= "</div></li>";

			$html .= "</ul>";

			/* Link to integrity panel */
			$integrityUrl = \IPS\Http\Url::internal( 'app=xpolarcheckout&module=monitoring&controller=integrity' );
			$html .= "<p class='ipsType_reset ipsType_medium ipsSpacer_top ipsSpacer_half'>";
			$html .= "<a href='" . htmlspecialchars( $integrityUrl ) . "'>" . \IPS\Member::loggedIn()->language()->addToStack( 'xpolarcheckout_view_integrity' ) . "</a>";
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
