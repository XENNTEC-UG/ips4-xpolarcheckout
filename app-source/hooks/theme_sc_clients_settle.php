//<?php

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	exit;
}

class xpolarcheckout_hook_theme_sc_clients_settle extends _HOOK_CLASS_
{

/* !Hook Data - DO NOT REMOVE */
public static function hookData(): array {
 return array_merge_recursive( array (
  'invoice' =>
  array (
    0 =>
    array (
      'selector' => '.ipsBox--child',
      'type' => 'replace',
      'content' => '{{if isset( $invoice->status_extra[ \'xpolarcheckout_snapshot\' ] ) AND \\is_array( $invoice->status_extra[ \'xpolarcheckout_snapshot\' ] )}}
	{{$polarSnapshot = $invoice->status_extra[ \'xpolarcheckout_snapshot\' ];}}
	<div class=\'ipsBox ipsBox--child ipsPadding\'>
		<h2 class=\'ipsType_minorHeading\'>{lang="order_order_total"}</h2>
		<span class=\'cNexusOrderBadge cNexusOrderBadge_{$invoice->status}\'>{lang="istatus_{$invoice->status}"}</span> &nbsp;&nbsp;&nbsp;
		<span class=\'cNexusPrice\' style=\'font-size: 1.4em;\'>{{if !empty( $polarSnapshot[ \'amount_total_display\' ] )}}{$polarSnapshot[ \'amount_total_display\' ]}{{else}}{$invoice->total}{{endif}}</span>
		<p class=\'ipsType_light ipsType_small ipsType_reset ipsSpacer_top ipsSpacer_half\'>{lang="xpolarcheckout_provider_charged_label"}</p>
		{{if !empty( $polarSnapshot[ \'discount_name\' ] )}}
			<p class=\'ipsType_light ipsType_small ipsType_reset\'>{lang="xpolarcheckout_settle_discount_label"}: {$polarSnapshot[ \'discount_name\' ]}{{if !empty( $polarSnapshot[ \'discount_code\' ] )}} ({$polarSnapshot[ \'discount_code\' ]}){{endif}}</p>
		{{endif}}
		<p class=\'ipsType_light ipsType_small ipsType_reset ipsSpacer_top ipsSpacer_half\'>{lang="xpolarcheckout_ips_invoice_total_label"}: {$invoice->total}</p>
		{{if $invoice->status === $invoice::STATUS_PENDING}}
			<ul class=\'ipsToolList ipsToolList_vertical ipsSpacer_top\'>
				<li><a href="{$invoice->checkoutUrl()}" class=\'ipsButton ipsButton_primary ipsButton_fullWidth ipsButton_small\' title="{lang="order_pay_now_title"}">{lang="order_pay_now"}</a></li>
				<li><a href="{$invoice->url()->setQueryString( \'do\', \'cancel\' )->csrf()}" class=\'ipsButton ipsButton_light ipsButton_fullWidth ipsButton_small\' data-confirm title=\'{lang="order_cancel_invoice"}\'>{lang="cancel"}</a></li>
			</ul>
		{{elseif $invoice->status === $invoice::STATUS_EXPIRED}}
			<p class=\'ipsType_center ipsType_light ipsType_reset ipsSpacer_top\'>{lang="order_invoice_expired"}</p>
		{{elseif $invoice->status === $invoice::STATUS_CANCELED}}
			<p class=\'ipsType_center ipsType_light ipsType_reset ipsSpacer_top\'>{lang="order_invoice_cancelled"}</p>
		{{elseif $invoice->status === $invoice::STATUS_PAID}}
			<p class=\'ipsType_center ipsType_light ipsType_reset ipsSpacer_top\'>
				{lang="order_invoice_paid"}<br>
				{{if $invoice->billaddress AND \\count( $invoice->transactions( array( \\IPS\\nexus\\Transaction::STATUS_PAID, \\IPS\\nexus\\Transaction::STATUS_WAITING, \\IPS\\nexus\\Transaction::STATUS_HELD, \\IPS\\nexus\\Transaction::STATUS_REVIEW, \\IPS\\nexus\\Transaction::STATUS_REFUSED, \\IPS\\nexus\\Transaction::STATUS_REFUNDED, \\IPS\\nexus\\Transaction::STATUS_PART_REFUNDED, \\IPS\\nexus\\Transaction::STATUS_GATEWAY_PENDING ) ) ) > 0 }}
					<a href=\'#elPaymentDetails\' data-ipsDialog data-ipsDialog-content=\'#elPaymentDetails\' data-ipsDialog-title=\'{lang="order_payment_details"}\' data-ipsDialog-size=\'narrow\'>{lang="order_view_payment"}</a>
				{{endif}}
			</p>
			{{if $invoice->billaddress}}
				<div id=\'elPaymentDetails\' class=\'ipsType_left ipsJS_hide\'>
					{template="paymentLog" group="clients" app="nexus" params="$invoice"}
				</div>
			{{endif}}
		{{endif}}
	</div>
{{else}}
	<div class=\'ipsBox ipsBox--child ipsPadding\'>
		<h2 class=\'ipsType_minorHeading\'>{lang="order_order_total"}</h2>
		<span class=\'cNexusOrderBadge cNexusOrderBadge_{$invoice->status}\'>{lang="istatus_{$invoice->status}"}</span> &nbsp;&nbsp;&nbsp;<span class=\'cNexusPrice\'>{$invoice->total}</span>
		{{if $invoice->status === $invoice::STATUS_PENDING}}
			<ul class=\'ipsToolList ipsToolList_vertical ipsSpacer_top\'>
				<li><a href="{$invoice->checkoutUrl()}" class=\'ipsButton ipsButton_primary ipsButton_fullWidth ipsButton_small\' title="{lang="order_pay_now_title"}">{lang="order_pay_now"}</a></li>
				<li><a href="{$invoice->url()->setQueryString( \'do\', \'cancel\' )->csrf()}" class=\'ipsButton ipsButton_light ipsButton_fullWidth ipsButton_small\' data-confirm title=\'{lang="order_cancel_invoice"}\'>{lang="cancel"}</a></li>
			</ul>
		{{elseif $invoice->status === $invoice::STATUS_EXPIRED}}
			<p class=\'ipsType_center ipsType_light ipsType_reset ipsSpacer_top\'>{lang="order_invoice_expired"}</p>
		{{elseif $invoice->status === $invoice::STATUS_CANCELED}}
			<p class=\'ipsType_center ipsType_light ipsType_reset ipsSpacer_top\'>{lang="order_invoice_cancelled"}</p>
		{{elseif $invoice->status === $invoice::STATUS_PAID}}
			<p class=\'ipsType_center ipsType_light ipsType_reset ipsSpacer_top\'>
				{lang="order_invoice_paid"}<br>
				{{if $invoice->billaddress AND \\count( $invoice->transactions( array( \\IPS\\nexus\\Transaction::STATUS_PAID, \\IPS\\nexus\\Transaction::STATUS_WAITING, \\IPS\\nexus\\Transaction::STATUS_HELD, \\IPS\\nexus\\Transaction::STATUS_REVIEW, \\IPS\\nexus\\Transaction::STATUS_REFUSED, \\IPS\\nexus\\Transaction::STATUS_REFUNDED, \\IPS\\nexus\\Transaction::STATUS_PART_REFUNDED, \\IPS\\nexus\\Transaction::STATUS_GATEWAY_PENDING ) ) ) > 0 }}
					<a href=\'#elPaymentDetails\' data-ipsDialog data-ipsDialog-content=\'#elPaymentDetails\' data-ipsDialog-title=\'{lang="order_payment_details"}\' data-ipsDialog-size=\'narrow\'>{lang="order_view_payment"}</a>
				{{endif}}
			</p>
			{{if $invoice->billaddress}}
				<div id=\'elPaymentDetails\' class=\'ipsType_left ipsJS_hide\'>
					{template="paymentLog" group="clients" app="nexus" params="$invoice"}
				</div>
			{{endif}}
		{{endif}}
	</div>
{{endif}}',
    ),
  ),
), parent::hookData() );
}
/* End Hook Data */

}
