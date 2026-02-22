//<?php

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	exit;
}

class xpolarcheckout_hook_theme_sc_print_settle extends _HOOK_CLASS_
{

/* !Hook Data - DO NOT REMOVE */
public static function hookData(): array {
 return array_merge_recursive( array (
  'printInvoice' =>
  array (
    0 =>
    array (
      'selector' => '.ipsPrint > table',
      'type' => 'add_after',
      'content' => '{{if isset( $invoice->status_extra[ \'xpolarcheckout_snapshot\' ] ) AND \\is_array( $invoice->status_extra[ \'xpolarcheckout_snapshot\' ] )}}
	{{$stripeSnapshot = $invoice->status_extra[ \'xpolarcheckout_snapshot\' ];}}
	<h2>{lang="xpolarcheckout_settle_title"}</h2>
	<h3>{lang="xpolarcheckout_settle_charge_summary"}</h3>
	<table>
		<tbody>
			<tr>
				<td><strong>{lang="xpolarcheckout_settle_subtotal"}</strong></td>
				<td>{{if !empty( $stripeSnapshot[ \'amount_subtotal_display\' ] )}}{$stripeSnapshot[ \'amount_subtotal_display\' ]}{{else}}-{{endif}}</td>
			</tr>
			{{if !empty( $stripeSnapshot[ \'amount_subtotal_minor\' ] ) AND !empty( $stripeSnapshot[ \'amount_total_minor\' ] )}}
				{{$xpc_discountMinor = (int) $stripeSnapshot[ \'amount_subtotal_minor\' ] - (int) $stripeSnapshot[ \'amount_total_minor\' ] + (int) $stripeSnapshot[ \'amount_tax_minor\' ];}}
				{{if $xpc_discountMinor > 0}}
					{{$xpc_discountDisplay = \\strtoupper( $stripeSnapshot[ \'currency\' ] ) . \' \' . \\number_format( $xpc_discountMinor / 100, 2 );}}
					<tr>
						<td><strong>{lang="xpolarcheckout_coupon_discount"}</strong></td>
						<td style=\'color: #28a745;\'>-{$xpc_discountDisplay}</td>
					</tr>
				{{endif}}
			{{endif}}
			{{if !empty( $stripeSnapshot[ \'tax_breakdown\' ] ) AND \\is_array( $stripeSnapshot[ \'tax_breakdown\' ] )}}
				{{foreach $stripeSnapshot[ \'tax_breakdown\' ] as $taxRow}}
				<tr>
					<td><strong>
						{{if !empty( $taxRow[ \'rate_display_name\' ] )}}{$taxRow[ \'rate_display_name\' ]}{{else}}Tax{{endif}}
						{{if !empty( $taxRow[ \'rate_percentage\' ] )}} ({$taxRow[ \'rate_percentage\' ]}%){{endif}}
						{{if !empty( $taxRow[ \'jurisdiction\' ] )}} &mdash; {$taxRow[ \'jurisdiction\' ]}{{elseif !empty( $taxRow[ \'country\' ] )}} &mdash; {$taxRow[ \'country\' ]}{{endif}}
					</strong></td>
					<td>{{if !empty( $taxRow[ \'amount_display\' ] )}}{$taxRow[ \'amount_display\' ]}{{else}}-{{endif}}</td>
				</tr>
				{{endforeach}}
			{{else}}
				<tr>
					<td><strong>{lang="xpolarcheckout_settle_tax"}</strong></td>
					<td>{{if !empty( $stripeSnapshot[ \'amount_tax_display\' ] )}}{$stripeSnapshot[ \'amount_tax_display\' ]}{{else}}-{{endif}}</td>
				</tr>
			{{endif}}
			<tr style=\'border-top: 2px solid #333;\'>
				<td><strong>{lang="xpolarcheckout_settle_total_charged"}</strong></td>
				<td><strong>{{if !empty( $stripeSnapshot[ \'amount_total_display\' ] )}}{$stripeSnapshot[ \'amount_total_display\' ]}{{else}}-{{endif}}</strong></td>
			</tr>
			<tr>
				<td>{lang="xpolarcheckout_settle_ips_total"}</td>
				<td>{{if !empty( $stripeSnapshot[ \'ips_invoice_total_display\' ] )}}{$stripeSnapshot[ \'ips_invoice_total_display\' ]}{{else}}-{{endif}}</td>
			</tr>
			{{if isset( $stripeSnapshot[ \'has_total_mismatch\' ] ) AND $stripeSnapshot[ \'has_total_mismatch\' ]}}
				<tr>
					<td><strong>{lang="xpolarcheckout_settle_mismatch_title"}</strong></td>
					<td>{{if !empty( $stripeSnapshot[ \'total_mismatch_display\' ] )}}{$stripeSnapshot[ \'total_mismatch_display\' ]}{{else}}{lang="xpolarcheckout_settle_mismatch_warning"}{{endif}}</td>
				</tr>
			{{endif}}
			{{if !empty( $stripeSnapshot[ \'customer_tax_exempt\' ] ) AND $stripeSnapshot[ \'customer_tax_exempt\' ] !== \'none\'}}
			<tr>
				<td><strong>{lang="xpolarcheckout_tax_exempt_status"}</strong></td>
				<td>{{if $stripeSnapshot[ \'customer_tax_exempt\' ] == \'reverse\'}}Reverse charge{{else}}{$stripeSnapshot[ \'customer_tax_exempt\' ]}{{endif}}</td>
			</tr>
			{{endif}}
			{{if !empty( $stripeSnapshot[ \'customer_tax_ids\' ] ) AND \\is_array( $stripeSnapshot[ \'customer_tax_ids\' ] )}}
			<tr>
				<td><strong>{lang="xpolarcheckout_customer_tax_id"}</strong></td>
				<td>{{foreach $stripeSnapshot[ \'customer_tax_ids\' ] as $taxId}}{$taxId[ \'type\' ]}: {$taxId[ \'value\' ]}<br>{{endforeach}}</td>
			</tr>
			{{endif}}
		</tbody>
	</table>
	<h3>{lang="xpolarcheckout_settle_payment_refs"}</h3>
	<table>
		<tbody>
			{{if !empty( $stripeSnapshot[ \'payment_method_type\' ] )}}
			<tr>
				<td><strong>{lang="xpolarcheckout_payment_method"}</strong></td>
				<td>{$stripeSnapshot[ \'payment_method_type\' ]}{{if !empty( $stripeSnapshot[ \'card_brand\' ] )}} {$stripeSnapshot[ \'card_brand\' ]}{{endif}}{{if !empty( $stripeSnapshot[ \'card_last4\' ] )}} ****{$stripeSnapshot[ \'card_last4\' ]}{{endif}}</td>
			</tr>
			{{endif}}
			<tr>
				<td><strong>{lang="xpolarcheckout_settle_captured_at"}</strong></td>
				<td>{{if !empty( $stripeSnapshot[ \'captured_at_iso\' ] )}}{$stripeSnapshot[ \'captured_at_iso\' ]}{{else}}-{{endif}}</td>
			</tr>
			<tr>
				<td><strong>{lang="xpolarcheckout_settle_stripe_invoice_id"}</strong></td>
				<td>
					{{if !empty( $stripeSnapshot[ \'invoice_id\' ] )}}{$stripeSnapshot[ \'invoice_id\' ]}{{else}}-{{endif}}
					{{if !empty( $stripeSnapshot[ \'customer_invoice_url\' ] )}}<br><a href=\'{$stripeSnapshot[ \'customer_invoice_url\' ]}\' target=\'_blank\' rel=\'noopener\'>{lang="xpolarcheckout_settle_view_invoice"}</a>{{endif}}
					{{if !empty( $stripeSnapshot[ \'customer_invoice_pdf_url\' ] )}}<br><a href=\'{$stripeSnapshot[ \'customer_invoice_pdf_url\' ]}\' target=\'_blank\' rel=\'noopener\'>{lang="xpolarcheckout_settle_download_pdf"}</a>{{endif}}
				</td>
			</tr>
			<tr>
				<td><strong>{lang="xpolarcheckout_settle_stripe_pi"}</strong></td>
				<td>
					{{if !empty( $stripeSnapshot[ \'payment_intent_id\' ] )}}{$stripeSnapshot[ \'payment_intent_id\' ]}{{else}}-{{endif}}
					{{if !empty( $stripeSnapshot[ \'customer_receipt_url\' ] )}}<br><a href=\'{$stripeSnapshot[ \'customer_receipt_url\' ]}\' target=\'_blank\' rel=\'noopener\'>{lang="xpolarcheckout_settle_view_receipt"}</a>{{endif}}
				</td>
			</tr>
		</tbody>
	</table>
	<p><em>{lang="xpolarcheckout_settle_source_truth"}</em></p>
{{endif}}',
    ),
  ),
), parent::hookData() );
}
/* End Hook Data */

}
