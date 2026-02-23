//<?php

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	exit;
}

class xpolarcheckout_hook_invoiceViewHook extends _HOOK_CLASS_
{
	/**
	 * View single invoice — global Order Details enhancements + Polar settlement
	 */
	public function view()
	{
		parent::view();

		try
		{
			if ( !isset( $this->invoice ) )
			{
				return;
			}

			$output = \IPS\Output::i()->output;

			/* === GLOBAL enhancements (all invoices) === */
			$output = $this->_xpcEnhanceOrderDetails( $output );

			/* === POLAR-ONLY enhancements === */
			$extra = $this->invoice->status_extra;
			if ( isset( $extra['xpolarcheckout_snapshot'] ) && \is_array( $extra['xpolarcheckout_snapshot'] ) )
			{
				$snapshot = $extra['xpolarcheckout_snapshot'];

				/* Build Polar Charge Summary */
				$polarSummary = $this->_xpcBuildPolarSummary( $snapshot );

				/* Build Payment & References */
				$paymentRefs = $this->_xpcBuildPaymentRefs( $snapshot );

				/* Find the Order Details box and wrap in two-column layout */
				$output = $this->_xpcWrapInColumns( $output, $polarSummary );

				/* Insert Payment & References after the columns */
				$output = $this->_xpcInsertPaymentRefs( $output, $paymentRefs );
			}

			\IPS\Output::i()->output = $output;
		}
		catch ( \Throwable $e )
		{
			/* Silently fail — parent already set base output */
		}
	}

	/**
	 * Build Polar Charge Summary HTML
	 *
	 * @param	array	$snapshot	Polar snapshot data
	 * @return	string
	 */
	protected function _xpcBuildPolarSummary( $snapshot )
	{
		$lang = \IPS\Member::loggedIn()->language();
		$html = '';

		$html .= "<div class='ipsBox'>";
		$html .= "<h2 class='ipsType_sectionTitle ipsType_reset'>" . $lang->addToStack( 'xpolarcheckout_settle_title' ) . "</h2>";
		$html .= "<div class='ipsPad'>";
		$html .= "<h3 class='ipsType_minorHeading ipsType_reset'>" . $lang->addToStack( 'xpolarcheckout_settle_charge_summary' ) . "</h3>";
		$html .= "<ul class='ipsDataList ipsDataList_reducedSpacing ipsSpacer_top ipsSpacer_half'>";

		/* Subtotal */
		$subtotalDisplay = !empty( $snapshot['amount_subtotal_display'] ) ? htmlspecialchars( $snapshot['amount_subtotal_display'], ENT_QUOTES | ENT_DISALLOWED, 'UTF-8', FALSE ) : '-';
		$html .= "<li class='ipsDataItem'>";
		$html .= "<div class='ipsDataItem_main'>" . $lang->addToStack( 'xpolarcheckout_settle_subtotal' ) . "</div>";
		$html .= "<div class='ipsDataItem_generic ipsType_right cNexusPrice' style='white-space:nowrap;'>{$subtotalDisplay}</div>";
		$html .= "</li>";

		/* Discount — use explicit discount_amount_minor when available, fall back to computed */
		$discountMinor = 0;
		if ( !empty( $snapshot['discount_amount_minor'] ) && (int) $snapshot['discount_amount_minor'] > 0 )
		{
			$discountMinor = (int) $snapshot['discount_amount_minor'];
		}
		elseif ( !empty( $snapshot['amount_subtotal_minor'] ) && !empty( $snapshot['amount_total_minor'] ) )
		{
			$taxMinor = !empty( $snapshot['amount_tax_minor'] ) ? (int) $snapshot['amount_tax_minor'] : 0;
			$discountMinor = (int) $snapshot['amount_subtotal_minor'] - (int) $snapshot['amount_total_minor'] + $taxMinor;
		}

		if ( $discountMinor > 0 )
		{
			$currency = !empty( $snapshot['currency'] ) ? mb_strtoupper( $snapshot['currency'] ) : '';
			$discountDisplay = !empty( $snapshot['discount_amount_display'] )
				? htmlspecialchars( $snapshot['discount_amount_display'], ENT_QUOTES | ENT_DISALLOWED, 'UTF-8', FALSE )
				: $currency . ' ' . number_format( $discountMinor / 100, 2 );

			$html .= "<li class='ipsDataItem'>";
			$html .= "<div class='ipsDataItem_main'>" . $lang->addToStack( 'xpolarcheckout_coupon_discount' ) . "</div>";
			$html .= "<div class='ipsDataItem_generic ipsType_right cNexusPrice' style='white-space:nowrap;color:#28a745;'>-{$discountDisplay}</div>";
			$html .= "</li>";

			/* Discount name/code */
			if ( !empty( $snapshot['discount_name'] ) || !empty( $snapshot['discount_code'] ) )
			{
				$discountLabel = '';
				if ( !empty( $snapshot['discount_name'] ) )
				{
					$discountLabel = htmlspecialchars( $snapshot['discount_name'], ENT_QUOTES | ENT_DISALLOWED, 'UTF-8', FALSE );
				}
				if ( !empty( $snapshot['discount_code'] ) )
				{
					$codeDisplay = htmlspecialchars( $snapshot['discount_code'], ENT_QUOTES | ENT_DISALLOWED, 'UTF-8', FALSE );
					$discountLabel .= ( $discountLabel !== '' ? ' ' : '' ) . '<span class="ipsType_light">(' . $codeDisplay . ')</span>';
				}
				$html .= "<li class='ipsDataItem'>";
				$html .= "<div class='ipsDataItem_main ipsType_light'>" . $lang->addToStack( 'xpolarcheckout_settle_discount_label' ) . "</div>";
				$html .= "<div class='ipsDataItem_generic ipsType_right ipsType_light' style='white-space:nowrap;'>{$discountLabel}</div>";
				$html .= "</li>";
			}

			/* Net subtotal — clarifies the tax basis */
			$netMinor = !empty( $snapshot['net_amount_minor'] )
				? (int) $snapshot['net_amount_minor']
				: (int) $snapshot['amount_subtotal_minor'] - $discountMinor;
			$netDisplay = !empty( $snapshot['net_amount_display'] )
				? htmlspecialchars( $snapshot['net_amount_display'], ENT_QUOTES | ENT_DISALLOWED, 'UTF-8', FALSE )
				: $currency . ' ' . number_format( $netMinor / 100, 2 );
			$html .= "<li class='ipsDataItem'>";
			$html .= "<div class='ipsDataItem_main ipsType_light'>" . $lang->addToStack( 'xpolarcheckout_settle_net_subtotal' ) . "</div>";
			$html .= "<div class='ipsDataItem_generic ipsType_right cNexusPrice ipsType_light' style='white-space:nowrap;'>{$netDisplay}</div>";
			$html .= "</li>";
		}

		/* Tax (single row — Polar does not provide per-rate breakdown) */
		$taxDisplay = !empty( $snapshot['amount_tax_display'] ) ? htmlspecialchars( $snapshot['amount_tax_display'], ENT_QUOTES | ENT_DISALLOWED, 'UTF-8', FALSE ) : '-';
		$html .= "<li class='ipsDataItem'>";
		$html .= "<div class='ipsDataItem_main'>" . $lang->addToStack( 'xpolarcheckout_settle_tax' ) . "</div>";
		$html .= "<div class='ipsDataItem_generic ipsType_right cNexusPrice' style='white-space:nowrap;'>{$taxDisplay}</div>";
		$html .= "</li>";

		$html .= "</ul>";

		/* Total charged with divider */
		$totalDisplay = !empty( $snapshot['amount_total_display'] ) ? htmlspecialchars( $snapshot['amount_total_display'], ENT_QUOTES | ENT_DISALLOWED, 'UTF-8', FALSE ) : '-';
		$html .= "<div class='ipsSpacer_top ipsSpacer_half' style='border-top: 2px solid #333; padding-top: 8px;'>";
		$html .= "<ul class='ipsDataList ipsDataList_reducedSpacing'>";
		$html .= "<li class='ipsDataItem'>";
		$html .= "<div class='ipsDataItem_main'><strong>" . $lang->addToStack( 'xpolarcheckout_settle_total_charged' ) . "</strong></div>";
		$html .= "<div class='ipsDataItem_generic ipsType_right cNexusPrice' style='white-space:nowrap;'><strong>{$totalDisplay}</strong></div>";
		$html .= "</li>";

		/* Refunded amount (Polar-specific) */
		if ( !empty( $snapshot['amount_refunded_minor'] ) && (int) $snapshot['amount_refunded_minor'] > 0 )
		{
			$refundedDisplay = !empty( $snapshot['amount_refunded_display'] ) ? htmlspecialchars( $snapshot['amount_refunded_display'], ENT_QUOTES | ENT_DISALLOWED, 'UTF-8', FALSE ) : '-';
			$html .= "<li class='ipsDataItem'>";
			$html .= "<div class='ipsDataItem_main'>" . $lang->addToStack( 'xpolarcheckout_settle_refunded_amount' ) . "</div>";
			$html .= "<div class='ipsDataItem_generic ipsType_right cNexusPrice' style='white-space:nowrap;color:#dc3545;'>-{$refundedDisplay}</div>";
			$html .= "</li>";

			/* Refunded tax amount */
			if ( !empty( $snapshot['refunded_tax_amount_minor'] ) && (int) $snapshot['refunded_tax_amount_minor'] > 0 )
			{
				$refundedTaxDisplay = !empty( $snapshot['refunded_tax_amount_display'] ) ? htmlspecialchars( $snapshot['refunded_tax_amount_display'], ENT_QUOTES | ENT_DISALLOWED, 'UTF-8', FALSE ) : '-';
				$html .= "<li class='ipsDataItem'>";
				$html .= "<div class='ipsDataItem_main ipsType_light'>" . $lang->addToStack( 'xpolarcheckout_settle_refunded_tax' ) . "</div>";
				$html .= "<div class='ipsDataItem_generic ipsType_right cNexusPrice ipsType_light' style='white-space:nowrap;color:#dc3545;'>-{$refundedTaxDisplay}</div>";
				$html .= "</li>";
			}
		}

		$html .= "</ul>";
		$html .= "</div>";

		/* Provider status badge (Polar-specific) */
		if ( !empty( $snapshot['provider_status'] ) )
		{
			$status = $snapshot['provider_status'];
			$statusColors = array(
				'paid' => '#28a745',
				'refunded' => '#dc3545',
				'partially_refunded' => '#fd7e14',
				'pending' => '#6c757d',
			);
			$color = isset( $statusColors[ $status ] ) ? $statusColors[ $status ] : '#6c757d';

			$langKey = 'xpolarcheckout_status_' . $status;
			$statusLabel = $lang->checkKeyExists( $langKey )
				? $lang->addToStack( $langKey )
				: htmlspecialchars( ucfirst( str_replace( '_', ' ', $status ) ), ENT_QUOTES | ENT_DISALLOWED, 'UTF-8', FALSE );

			$html .= "<div class='ipsSpacer_top ipsSpacer_half'>";
			$html .= "<span class='ipsBadge' style='background-color:{$color};color:#fff;'>{$statusLabel}</span>";
			$html .= "</div>";
		}

		/* Tax explains difference info */
		if ( !empty( $snapshot['total_difference_tax_explained'] ) )
		{
			$html .= "<div class='ipsMessage ipsMessage_info ipsSpacer_top ipsSpacer_half'>";
			$html .= $lang->addToStack( 'xpolarcheckout_settle_tax_explains_diff' );
			$html .= "</div>";
		}

		/* Total mismatch warning */
		if ( !empty( $snapshot['has_total_mismatch'] ) )
		{
			$mismatchDisplay = !empty( $snapshot['total_mismatch_display'] )
				? htmlspecialchars( $snapshot['total_mismatch_display'], ENT_QUOTES | ENT_DISALLOWED, 'UTF-8', FALSE )
				: $lang->addToStack( 'xpolarcheckout_settle_mismatch_warning' );
			$html .= "<div class='ipsMessage ipsMessage_warning ipsSpacer_top ipsSpacer_half'>";
			$html .= "<strong>" . $lang->addToStack( 'xpolarcheckout_settle_mismatch_title' ) . "</strong>: {$mismatchDisplay}";
			$html .= "</div>";
		}

		$html .= "</div>"; /* ipsPad */
		$html .= "</div>"; /* ipsBox */

		return $html;
	}

	/**
	 * Build Payment & References HTML
	 *
	 * @param	array	$snapshot	Polar snapshot data
	 * @return	string
	 */
	protected function _xpcBuildPaymentRefs( $snapshot )
	{
		$lang = \IPS\Member::loggedIn()->language();
		$html = '';

		$html .= "<div class='ipsBox ipsMargin_top'>";
		$html .= "<h2 class='ipsType_sectionTitle ipsType_reset'>" . $lang->addToStack( 'xpolarcheckout_settle_payment_refs' ) . "</h2>";
		$html .= "<div class='ipsPad'>";
		$html .= "<ul class='ipsDataList ipsDataList_reducedSpacing'>";

		/* Captured timestamp */
		$capturedDisplay = !empty( $snapshot['captured_at_iso'] ) ? htmlspecialchars( $snapshot['captured_at_iso'], ENT_QUOTES | ENT_DISALLOWED, 'UTF-8', FALSE ) : '-';
		$html .= "<li class='ipsDataItem'>";
		$html .= "<div class='ipsDataItem_main'>" . $lang->addToStack( 'xpolarcheckout_settle_captured_at' ) . "</div>";
		$html .= "<div class='ipsDataItem_generic ipsType_right' style='white-space:nowrap;'>{$capturedDisplay}</div>";
		$html .= "</li>";

		/* Polar invoice number */
		if ( !empty( $snapshot['polar_invoice_number'] ) )
		{
			$invoiceNumDisplay = htmlspecialchars( $snapshot['polar_invoice_number'], ENT_QUOTES | ENT_DISALLOWED, 'UTF-8', FALSE );
			$html .= "<li class='ipsDataItem'>";
			$html .= "<div class='ipsDataItem_main'>" . $lang->addToStack( 'xpolarcheckout_settle_invoice_number' ) . "</div>";
			$html .= "<div class='ipsDataItem_generic ipsType_right' style='white-space:nowrap;'>{$invoiceNumDisplay}</div>";
			$html .= "</li>";
		}

		/* Polar Order ID + action buttons */
		$orderIdDisplay = !empty( $snapshot['order_id'] ) ? htmlspecialchars( $snapshot['order_id'], ENT_QUOTES | ENT_DISALLOWED, 'UTF-8', FALSE ) : '-';
		$invoiceButtons = '';
		if ( !empty( $snapshot['customer_invoice_url'] ) )
		{
			$url = htmlspecialchars( $snapshot['customer_invoice_url'], ENT_QUOTES | ENT_DISALLOWED, 'UTF-8', FALSE );
			$invoiceButtons .= " <a href='{$url}' target='_blank' rel='noopener' class='ipsButton ipsButton_light ipsButton_verySmall'>" . $lang->addToStack( 'xpolarcheckout_settle_view_invoice' ) . "</a>";
		}
		if ( !empty( $snapshot['customer_invoice_pdf_url'] ) )
		{
			$url = htmlspecialchars( $snapshot['customer_invoice_pdf_url'], ENT_QUOTES | ENT_DISALLOWED, 'UTF-8', FALSE );
			$invoiceButtons .= " <a href='{$url}' target='_blank' rel='noopener' class='ipsButton ipsButton_light ipsButton_verySmall'>" . $lang->addToStack( 'xpolarcheckout_settle_download_pdf' ) . "</a>";
		}
		$html .= "<li class='ipsDataItem'>";
		$html .= "<div class='ipsDataItem_main'>" . $lang->addToStack( 'xpolarcheckout_settle_provider_order_id' ) . "</div>";
		$html .= "<div class='ipsDataItem_generic ipsType_right ipsType_light ipsType_small' style='white-space:nowrap;'>";
		$html .= $orderIdDisplay;
		if ( $invoiceButtons )
		{
			$html .= "<div class='ipsSpacer_top ipsSpacer_half' style='white-space:nowrap;'>{$invoiceButtons}</div>";
		}
		$html .= "</div>";
		$html .= "</li>";

		/* Polar Checkout ID + receipt button */
		$checkoutIdDisplay = !empty( $snapshot['checkout_id'] ) ? htmlspecialchars( $snapshot['checkout_id'], ENT_QUOTES | ENT_DISALLOWED, 'UTF-8', FALSE ) : '-';
		$receiptButton = '';
		if ( !empty( $snapshot['customer_receipt_url'] ) )
		{
			$url = htmlspecialchars( $snapshot['customer_receipt_url'], ENT_QUOTES | ENT_DISALLOWED, 'UTF-8', FALSE );
			$receiptButton = " <a href='{$url}' target='_blank' rel='noopener' class='ipsButton ipsButton_light ipsButton_verySmall'>" . $lang->addToStack( 'xpolarcheckout_settle_view_receipt' ) . "</a>";
		}
		$html .= "<li class='ipsDataItem'>";
		$html .= "<div class='ipsDataItem_main'>" . $lang->addToStack( 'xpolarcheckout_settle_provider_checkout_id' ) . "</div>";
		$html .= "<div class='ipsDataItem_generic ipsType_right ipsType_light ipsType_small' style='white-space:nowrap;'>";
		$html .= $checkoutIdDisplay;
		if ( $receiptButton )
		{
			$html .= "<div class='ipsSpacer_top ipsSpacer_half' style='white-space:nowrap;'>{$receiptButton}</div>";
		}
		$html .= "</div>";
		$html .= "</li>";

		/* Billing name */
		if ( !empty( $snapshot['billing_name'] ) )
		{
			$billingNameDisplay = htmlspecialchars( $snapshot['billing_name'], ENT_QUOTES | ENT_DISALLOWED, 'UTF-8', FALSE );
			$html .= "<li class='ipsDataItem'>";
			$html .= "<div class='ipsDataItem_main'>" . $lang->addToStack( 'xpolarcheckout_settle_billing_name' ) . "</div>";
			$html .= "<div class='ipsDataItem_generic ipsType_right ipsType_light' style='white-space:nowrap;'>{$billingNameDisplay}</div>";
			$html .= "</li>";
		}

		/* Customer email */
		if ( !empty( $snapshot['customer_email'] ) )
		{
			$emailDisplay = htmlspecialchars( $snapshot['customer_email'], ENT_QUOTES | ENT_DISALLOWED, 'UTF-8', FALSE );
			$html .= "<li class='ipsDataItem'>";
			$html .= "<div class='ipsDataItem_main'>" . $lang->addToStack( 'xpolarcheckout_settle_customer_email' ) . "</div>";
			$html .= "<div class='ipsDataItem_generic ipsType_right ipsType_light' style='white-space:nowrap;'>{$emailDisplay}</div>";
			$html .= "</li>";
		}

		/* Billing reason */
		if ( !empty( $snapshot['billing_reason'] ) )
		{
			$reasonRaw = (string) $snapshot['billing_reason'];
			$reasonLangKey = 'xpolarcheckout_billing_reason_' . $reasonRaw;
			$reasonDisplay = $lang->checkKeyExists( $reasonLangKey )
				? $lang->addToStack( $reasonLangKey )
				: htmlspecialchars( ucfirst( str_replace( '_', ' ', $reasonRaw ) ), ENT_QUOTES | ENT_DISALLOWED, 'UTF-8', FALSE );
			$html .= "<li class='ipsDataItem'>";
			$html .= "<div class='ipsDataItem_main'>" . $lang->addToStack( 'xpolarcheckout_settle_billing_reason' ) . "</div>";
			$html .= "<div class='ipsDataItem_generic ipsType_right ipsType_light' style='white-space:nowrap;'>{$reasonDisplay}</div>";
			$html .= "</li>";
		}

		/* Customer tax ID */
		if ( !empty( $snapshot['customer_tax_id'] ) && \is_array( $snapshot['customer_tax_id'] ) )
		{
			$taxIdDisplay = htmlspecialchars( implode( ', ', $snapshot['customer_tax_id'] ), ENT_QUOTES | ENT_DISALLOWED, 'UTF-8', FALSE );
			$html .= "<li class='ipsDataItem'>";
			$html .= "<div class='ipsDataItem_main'>" . $lang->addToStack( 'xpolarcheckout_settle_customer_tax_id' ) . "</div>";
			$html .= "<div class='ipsDataItem_generic ipsType_right ipsType_light' style='white-space:nowrap;'>{$taxIdDisplay}</div>";
			$html .= "</li>";
		}

		$html .= "</ul>";
		$html .= "</div>"; /* ipsPad */

		/* Source of truth footer */
		$html .= "<div class='ipsPad ipsAreaBackground_light'>";
		$html .= "<p class='ipsType_reset ipsType_medium ipsType_light'>" . $lang->addToStack( 'xpolarcheckout_settle_source_truth' ) . "</p>";
		$html .= "</div>";

		$html .= "</div>"; /* ipsBox */

		return $html;
	}

	/**
	 * Find Order Details box and wrap in two-column layout
	 *
	 * @param	string	$output			Current page output
	 * @param	string	$polarSummary	Polar Charge Summary HTML
	 * @return	string
	 */
	protected function _xpcWrapInColumns( $output, $polarSummary )
	{
		/* Find the section title heading — unique marker for Order Details */
		$marker = 'ipsType_sectionTitle';
		$pos = mb_strpos( $output, $marker );
		if ( $pos === false )
		{
			return $output;
		}

		/* Go backwards to find the opening <div class="ipsBox"> */
		$before = mb_substr( $output, 0, $pos );
		$classPos = mb_strrpos( $before, 'class="ipsBox"' );
		if ( $classPos === false )
		{
			$classPos = mb_strrpos( $before, "class='ipsBox'" );
		}
		if ( $classPos === false )
		{
			return $output;
		}
		$divBefore = mb_substr( $output, 0, $classPos );
		$boxStart = mb_strrpos( $divBefore, '<div' );
		if ( $boxStart === false )
		{
			return $output;
		}

		/* Find the matching closing </div> */
		$boxEnd = $this->_xpcFindClosingDiv( $output, $boxStart );
		if ( $boxEnd === false )
		{
			return $output;
		}

		$orderDetailsBox = mb_substr( $output, $boxStart, $boxEnd - $boxStart );

		/* Check if already inside a columns layout (shipments case or Stripe hook) */
		$contextBefore = mb_substr( $output, max( 0, $boxStart - 300 ), min( 300, $boxStart ) );
		if ( mb_strpos( $contextBefore, 'ipsColumn_fluid' ) !== false )
		{
			/* Already in columns — add Polar summary after the Order Details box */
			$output = mb_substr( $output, 0, $boxEnd )
				. $polarSummary
				. '<!-- xpc-columns-end -->'
				. mb_substr( $output, $boxEnd );
			return $output;
		}

		/* Standard mode — wrap in two-column layout with veryWide right column */
		$twoColumn = "<div class='ipsColumns ipsColumns_collapsePhone'>"
			. "<div class='ipsColumn ipsColumn_fluid'>" . $orderDetailsBox . "</div>"
			. "<div class='ipsColumn ipsColumn_veryWide'>" . $polarSummary . "</div>"
			. "</div>"
			. "<!-- xpc-columns-end -->";

		$output = mb_substr( $output, 0, $boxStart ) . $twoColumn . mb_substr( $output, $boxEnd );

		return $output;
	}

	/**
	 * Find the matching closing </div> for a div starting at $startPos
	 *
	 * @param	string	$html		HTML string
	 * @param	int		$startPos	Position of the opening <div
	 * @return	int|false			Position after the closing </div>, or false
	 */
	protected function _xpcFindClosingDiv( $html, $startPos )
	{
		$depth = 0;
		$len = mb_strlen( $html );
		$i = $startPos;

		while ( $i < $len )
		{
			$nextOpen = mb_strpos( $html, '<div', $i );
			$nextClose = mb_strpos( $html, '</div>', $i );

			if ( $nextClose === false )
			{
				break;
			}

			if ( $nextOpen !== false && $nextOpen < $nextClose )
			{
				$depth++;
				$i = $nextOpen + 4;
			}
			else
			{
				$depth--;
				if ( $depth === 0 )
				{
					return $nextClose + 6;
				}
				$i = $nextClose + 6;
			}
		}

		return false;
	}

	/**
	 * Insert Payment & References section after the columns layout
	 *
	 * @param	string	$output			Current page output
	 * @param	string	$paymentRefs	Payment & References HTML
	 * @return	string
	 */
	protected function _xpcInsertPaymentRefs( $output, $paymentRefs )
	{
		$marker = '<!-- xpc-columns-end -->';
		$pos = mb_strpos( $output, $marker );
		if ( $pos !== false )
		{
			$insertAt = $pos + mb_strlen( $marker );
			return mb_substr( $output, 0, $insertAt ) . $paymentRefs . mb_substr( $output, $insertAt );
		}

		/* Fallback: append at end */
		return $output . $paymentRefs;
	}

	/**
	 * Enhance Order Details: products subtotal before coupons, tag icon, green pricing, hide duplicates
	 * Runs globally for ALL invoices (not just Polar)
	 *
	 * @param	string	$output		Current page output
	 * @return	string
	 */
	protected function _xpcEnhanceOrderDetails( $output )
	{
		/* Skip if Stripe hook already enhanced this output */
		if ( mb_strpos( $output, '<!-- xsc-order-enhanced -->' ) !== false || mb_strpos( $output, '<!-- xpc-order-enhanced -->' ) !== false )
		{
			return $output;
		}

		/* Detect coupon item names from invoice items */
		$couponNames = array();
		foreach ( clone $this->invoice->items as $item )
		{
			if ( $item instanceof \IPS\nexus\extensions\nexus\Item\CouponDiscount )
			{
				$couponNames[] = $item->name;
			}
		}

		/* --- Products subtotal insertion (only when coupons exist) --- */
		$productsSubtotal = $this->_xpcProductsSubtotal();
		if ( $productsSubtotal !== null && !empty( $couponNames ) )
		{
			$lang = \IPS\Member::loggedIn()->language();
			$subtotalLabel = $lang->addToStack( 'subtotal' );
			$subtotalRow = "<li class='ipsDataItem cNexusCheckout_subtotal'>"
				. "<div class='ipsDataItem_main ipsType_right'><strong>{$subtotalLabel}</strong></div>"
				. "<div class='ipsDataItem_generic ipsDataItem_size3 ipsType_right cNexusPrice'>{$productsSubtotal}</div>"
				. "</li>";

			/* Build regex pattern from actual coupon item names */
			$namePatterns = array();
			foreach ( $couponNames as $name )
			{
				$namePatterns[] = preg_quote( htmlspecialchars( $name, ENT_QUOTES | ENT_DISALLOWED, 'UTF-8', FALSE ), '/' );
			}
			$couponPattern = implode( '|', $namePatterns );

			/* Insert before the first <li> containing a coupon item name */
			$output = preg_replace(
				'/(<li\b[^>]*\bipsDataItem\b[^>]*>(?=(?:(?!<\/li>).)*(?:' . $couponPattern . ')))/si',
				$subtotalRow . '$1',
				$output,
				1
			);
		}

		/* --- CSS to hide duplicate coupon section --- */
		$css = '<style>.cNexusCheckout_coupon { display: none !important; }</style>';

		/* --- Enhance coupon items: tag icon + green pricing --- */
		$couponNamesSet = array_flip( $couponNames );
		$output = preg_replace_callback(
			'/<li\b[^>]*\bipsDataItem\b[^>]*>.*?<\/li>/si',
			function( $match ) use ( $couponNamesSet )
			{
				$item = $match[0];

				/* Check if this item contains any known coupon name */
				$isCoupon = false;
				foreach ( $couponNamesSet as $name => $_ )
				{
					if ( mb_strpos( $item, htmlspecialchars( $name, ENT_QUOTES | ENT_DISALLOWED, 'UTF-8', FALSE ) ) !== false )
					{
						$isCoupon = true;
						break;
					}
				}
				if ( !$isCoupon )
				{
					return $item;
				}

				/* Replace product placeholder thumbnail (div) with tag icon */
				$item = preg_replace(
					'/<div[^>]*ipsNoThumb_product[^>]*>[^<]*<\/div>/i',
					'<i class="fa fa-tag" style="font-size:1.5em;color:#28a745;display:flex;align-items:center;justify-content:center;width:50px;height:50px;"></i>',
					$item
				);

				/* Add green color to negative price amounts */
				$item = preg_replace_callback(
					'/(<span[^>]*\bcNexusPrice\b[^>]*>)(.*?<\/span>)/si',
					function( $priceMatch )
					{
						$openTag = $priceMatch[1];
						$rest = $priceMatch[2];
						$text = trim( strip_tags( $rest ) );
						if ( mb_strpos( $text, '-' ) === 0 )
						{
							if ( preg_match( '/style=["\']/', $openTag ) )
							{
								$openTag = preg_replace( '/style=(["\'])/', 'style=$1color:#28a745 !important;', $openTag );
							}
							else
							{
								$openTag = str_replace( '>', ' style="color:#28a745 !important;">', $openTag );
							}
						}
						return $openTag . $rest;
					},
					$item
				);

				return $item;
			},
			$output
		);

		/* Inject CSS before first div + idempotency marker */
		$firstDiv = mb_strpos( $output, '<div' );
		if ( $firstDiv !== false )
		{
			$output = mb_substr( $output, 0, $firstDiv ) . $css . '<!-- xpc-order-enhanced -->' . mb_substr( $output, $firstDiv );
		}
		else
		{
			$output = $css . '<!-- xpc-order-enhanced -->' . $output;
		}

		return $output;
	}

	/**
	 * Compute products-only subtotal from invoice items (excludes coupons and shipping)
	 *
	 * @return	\IPS\nexus\Money|null	Returns null if no coupon items exist
	 */
	protected function _xpcProductsSubtotal()
	{
		$hasCoupon = false;
		$productsTotal = new \IPS\Math\Number( '0' );

		foreach ( clone $this->invoice->items as $item )
		{
			if ( $item instanceof \IPS\nexus\extensions\nexus\Item\CouponDiscount )
			{
				$hasCoupon = true;
				continue;
			}

			if ( $item instanceof \IPS\nexus\extensions\nexus\Item\ShippingCharge )
			{
				continue;
			}

			$productsTotal = $productsTotal->add( $item->linePrice()->amount );
		}

		if ( !$hasCoupon )
		{
			return null;
		}

		return new \IPS\nexus\Money( $productsTotal, $this->invoice->currency );
	}
}
