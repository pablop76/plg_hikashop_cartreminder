<?php
/**
 * Przypomnienie o nieopłaconym zamówieniu.
 * Teksty {TXT:PLG_HIKASHOP_CARTREMINDER_...} pochodzą z pliku językowego wtyczki.
 */
defined('_JEXEC') or die('Restricted access');
?><table class="w600" border="0" cellspacing="0" cellpadding="0" width="600" style="margin:0px;font-family: Arial, Helvetica, sans-serif;font-size:14px;line-height:21px;">
	<tr>
		<td class="w20" width="20"></td>
		<td class="w560" style="text-align:left; color:#575757" width="560">
<!-- TITLE -->
<h1 style="font-size:18px;font-weight:bold;color:#23303a;border-bottom:1px solid #ddd;padding-bottom:10px">
	{TXT:PLG_HIKASHOP_CARTREMINDER_ORDER_TITLE}
</h1>
<!-- EO TITLE -->
<!-- MAIN MESSAGE -->
<p style="margin:0 0 4px;">{TXT:PLG_HIKASHOP_CARTREMINDER_HELLO}</p>
<p style="margin:0 0 14px;">{TXT:INTRO}</p>
<!-- EO MAIN MESSAGE -->
<!-- PRODUCTS LIST -->
<table border="0" cellspacing="0" cellpadding="0" width="100%" style="font-family: Arial, Helvetica, sans-serif;font-size:14px;line-height:21px;">
<!--{START:PRODUCT_LINE}-->
	<tr>
		<td width="72" style="padding:8px 0;border-bottom:1px solid #eee3d3;">{LINEVAR:PRODUCT_IMG}</td>
		<td style="padding:8px 0;border-bottom:1px solid #eee3d3;color:#23303a;">{LINEVAR:PRODUCT_NAME}</td>
		<td style="padding:8px 0;border-bottom:1px solid #eee3d3;text-align:right;white-space:nowrap;">{LINEVAR:PRODUCT_QUANTITY} {TXT:PLG_HIKASHOP_CARTREMINDER_PIECES}</td>
	</tr>
<!--{END:PRODUCT_LINE}-->
	<tr>
		<td colspan="3" style="padding:10px 0 0;text-align:right;white-space:nowrap;font-weight:bold;color:#23303a;">{TXT:PLG_HIKASHOP_CARTREMINDER_ORDER_TOTAL} {VAR:TOTAL}</td>
	</tr>
</table>
<!-- EO PRODUCTS LIST -->
<!--{IF:BANK_INFO}-->
<!-- BANK TRANSFER DETAILS -->
<div style="margin:18px 0 0;padding:14px 18px;border:1px solid #e6dfd3;border-radius:6px;background:#fbf9f5;color:#23303a;">
	<p style="margin:0 0 6px;font-size:15px;font-weight:bold;">{TXT:PLG_HIKASHOP_CARTREMINDER_ORDER_BANK_TITLE}</p>
	{VAR:BANK_INFO}
	<p style="margin:6px 0 0;">{TXT:BANK_NOTE}</p>
</div>
<!-- EO BANK TRANSFER DETAILS -->
<!--{ENDIF:BANK_INFO}-->
<!-- PAY BUTTON -->
<p style="margin:22px 0;">
	<a href="{VAR:URL}" style="display:inline-block;padding:12px 22px;border-radius:6px;background:#b4976e;color:#ffffff;font-size:15px;font-weight:bold;text-decoration:none;">{TXT:PLG_HIKASHOP_CARTREMINDER_ORDER_BUTTON}</a>
</p>
<!-- EO PAY BUTTON -->
<!--{IF:RESET_URL}-->
<!-- LOGIN NOTE -->
<p style="margin:0 0 14px;font-size:13px;line-height:19px;">{TXT:PLG_HIKASHOP_CARTREMINDER_ORDER_LOGIN_NOTE} <a href="{VAR:RESET_URL}" style="color:#8a6f47;">{TXT:PLG_HIKASHOP_CARTREMINDER_ORDER_LOGIN_RESET}</a></p>
<!-- EO LOGIN NOTE -->
<!--{ENDIF:RESET_URL}-->
<!-- ALREADY PAID AND HELP MESSAGE -->
<p style="margin:0 0 14px;">{TXT:PLG_HIKASHOP_CARTREMINDER_ORDER_PAID}</p>
<p style="margin:0 0 14px;">{TXT:PLG_HIKASHOP_CARTREMINDER_ORDER_HELP}</p>
<!-- EO ALREADY PAID AND HELP MESSAGE -->
<!-- BEST REGARDS -->
<p style="margin:0 0 14px;">{TXT:REGARDS}</p>
<!-- EO BEST REGARDS -->
		</td>
		<td class="w20" width="20"></td>
	</tr>
</table>
