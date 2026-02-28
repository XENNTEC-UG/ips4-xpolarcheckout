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
	{{$polarSnapshot = $invoice->status_extra[ \'xpolarcheckout_snapshot\' ];}}
	<h2>{lang="xpolarcheckout_settle_title"}</h2>
	<h3>{lang="xpolarcheckout_settle_charge_summary"}</h3>
	<table>
		<tbody>
			<tr>
				<td><strong>{lang="xpolarcheckout_settle_subtotal"}</strong></td>
				<td>{{if !empty( $polarSnapshot[ \'amount_subtotal_display\' ] )}}{$polarSnapshot[ \'amount_subtotal_display\' ]}{{else}}-{{endif}}</td>
			</tr>
			{{if !empty( $polarSnapshot[ \'discount_amount_minor\' ] ) AND (int) $polarSnapshot[ \'discount_amount_minor\' ] > 0}}
				<tr>
					<td><strong>{lang="xpolarcheckout_coupon_discount"}</strong></td>
					<td style=\'color: #28a745;\'>-{{if !empty( $polarSnapshot[ \'discount_amount_display\' ] )}}{$polarSnapshot[ \'discount_amount_display\' ]}{{else}}{{$xpc_discountDisplay = \\strtoupper( $polarSnapshot[ \'currency\' ] ) . \' \' . \\number_format( (int) $polarSnapshot[ \'discount_amount_minor\' ] / 100, 2 );}}{$xpc_discountDisplay}{{endif}}</td>
				</tr>
			{{elseif !empty( $polarSnapshot[ \'amount_subtotal_minor\' ] ) AND !empty( $polarSnapshot[ \'amount_total_minor\' ] )}}
				{{$xpc_discountMinor = (int) $polarSnapshot[ \'amount_subtotal_minor\' ] - (int) $polarSnapshot[ \'amount_total_minor\' ] + (int) $polarSnapshot[ \'amount_tax_minor\' ];}}
				{{if $xpc_discountMinor > 0}}
					{{$xpc_discountDisplay = \\strtoupper( $polarSnapshot[ \'currency\' ] ) . \' \' . \\number_format( $xpc_discountMinor / 100, 2 );}}
					<tr>
						<td><strong>{lang="xpolarcheckout_coupon_discount"}</strong></td>
						<td style=\'color: #28a745;\'>-{$xpc_discountDisplay}</td>
					</tr>
				{{endif}}
			{{endif}}
			{{if !empty( $polarSnapshot[ \'discount_name\' ] ) OR !empty( $polarSnapshot[ \'discount_code\' ] )}}
				<tr>
					<td>{lang="xpolarcheckout_settle_discount_label"}</td>
					<td>{{if !empty( $polarSnapshot[ \'discount_name\' ] )}}{$polarSnapshot[ \'discount_name\' ]}{{endif}}{{if !empty( $polarSnapshot[ \'discount_code\' ] )}} ({$polarSnapshot[ \'discount_code\' ]}){{endif}}</td>
				</tr>
			{{endif}}
			<tr>
				<td><strong>{lang="xpolarcheckout_settle_tax"}</strong></td>
				<td>{{if !empty( $polarSnapshot[ \'amount_tax_display\' ] )}}{$polarSnapshot[ \'amount_tax_display\' ]}{{else}}-{{endif}}</td>
			</tr>
			<tr style=\'border-top: 2px solid rgba(128,128,128,0.3);\'>
				<td><strong>{lang="xpolarcheckout_settle_total_charged"}</strong></td>
				<td><strong>{{if !empty( $polarSnapshot[ \'amount_total_display\' ] )}}{$polarSnapshot[ \'amount_total_display\' ]}{{else}}-{{endif}}</strong></td>
			</tr>
			{{if !empty( $polarSnapshot[ \'amount_refunded_minor\' ] ) AND (int) $polarSnapshot[ \'amount_refunded_minor\' ] > 0}}
			<tr>
				<td><strong>{lang="xpolarcheckout_settle_refunded_amount"}</strong></td>
				<td style=\'color: #dc3545;\'>-{{if !empty( $polarSnapshot[ \'amount_refunded_display\' ] )}}{$polarSnapshot[ \'amount_refunded_display\' ]}{{else}}-{{endif}}</td>
			</tr>
			{{if !empty( $polarSnapshot[ \'refunded_tax_amount_minor\' ] ) AND (int) $polarSnapshot[ \'refunded_tax_amount_minor\' ] > 0}}
			<tr>
				<td>{lang="xpolarcheckout_settle_refunded_tax"}</td>
				<td style=\'color: #dc3545;\'>-{{if !empty( $polarSnapshot[ \'refunded_tax_amount_display\' ] )}}{$polarSnapshot[ \'refunded_tax_amount_display\' ]}{{else}}-{{endif}}</td>
			</tr>
			{{endif}}
			{{endif}}
			<tr>
				<td>{lang="xpolarcheckout_settle_ips_total"}</td>
				<td>{{if !empty( $polarSnapshot[ \'ips_invoice_total_display\' ] )}}{$polarSnapshot[ \'ips_invoice_total_display\' ]}{{else}}-{{endif}}</td>
			</tr>
			{{if isset( $polarSnapshot[ \'has_total_mismatch\' ] ) AND $polarSnapshot[ \'has_total_mismatch\' ]}}
				<tr>
					<td><strong>{lang="xpolarcheckout_settle_mismatch_title"}</strong></td>
					<td>{{if !empty( $polarSnapshot[ \'total_mismatch_display\' ] )}}{$polarSnapshot[ \'total_mismatch_display\' ]}{{else}}{lang="xpolarcheckout_settle_mismatch_warning"}{{endif}}</td>
				</tr>
			{{endif}}
		</tbody>
	</table>
	<h3>{lang="xpolarcheckout_settle_payment_refs"}</h3>
	<table>
		<tbody>
			<tr>
				<td><strong>{lang="xpolarcheckout_settle_captured_at"}</strong></td>
				<td>{{if !empty( $polarSnapshot[ \'captured_at_iso\' ] )}}{$polarSnapshot[ \'captured_at_iso\' ]}{{else}}-{{endif}}</td>
			</tr>
			<tr>
				<td><strong>{lang="xpolarcheckout_settle_provider_order_id"}</strong></td>
				<td>
					{{if !empty( $polarSnapshot[ \'order_id\' ] )}}{$polarSnapshot[ \'order_id\' ]}{{else}}-{{endif}}
					{{if !empty( $polarSnapshot[ \'customer_invoice_url\' ] )}}<br><a href=\'{$polarSnapshot[ \'customer_invoice_url\' ]}\' target=\'_blank\' rel=\'noopener\'>{lang="xpolarcheckout_settle_view_invoice"}</a>{{endif}}
					{{if !empty( $polarSnapshot[ \'customer_invoice_pdf_url\' ] )}}<br><a href=\'{$polarSnapshot[ \'customer_invoice_pdf_url\' ]}\' target=\'_blank\' rel=\'noopener\'>{lang="xpolarcheckout_settle_download_pdf"}</a>{{endif}}
				</td>
			</tr>
			<tr>
				<td><strong>{lang="xpolarcheckout_settle_provider_checkout_id"}</strong></td>
				<td>
					{{if !empty( $polarSnapshot[ \'checkout_id\' ] )}}{$polarSnapshot[ \'checkout_id\' ]}{{else}}-{{endif}}
					{{if !empty( $polarSnapshot[ \'customer_receipt_url\' ] )}}<br><a href=\'{$polarSnapshot[ \'customer_receipt_url\' ]}\' target=\'_blank\' rel=\'noopener\'>{lang="xpolarcheckout_settle_view_receipt"}</a>{{endif}}
				</td>
			</tr>
			{{if !empty( $polarSnapshot[ \'polar_invoice_number\' ] )}}
			<tr>
				<td><strong>{lang="xpolarcheckout_settle_invoice_number"}</strong></td>
				<td>{$polarSnapshot[ \'polar_invoice_number\' ]}</td>
			</tr>
			{{endif}}
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
