<?php
/**
 * Przypomnienie o porzuconym koszyku.
 * Teksty {TXT:PLG_HIKASHOP_CARTREMINDER_...} pochodzą z pliku językowego wtyczki.
 */
defined('_JEXEC') or die('Restricted access');
?><table class="w600" border="0" cellspacing="0" cellpadding="0" width="600" style="margin:0px;font-family: Arial, Helvetica, sans-serif;font-size:14px;line-height:21px;">
	<tr>
		<td class="w20" width="20"></td>
		<td class="w560" style="text-align:left; color:#575757" width="560">
<!-- TITLE -->
<h1 style="font-size:18px;font-weight:bold;color:#23303a;border-bottom:1px solid #ddd;padding-bottom:10px">
	{TXT:PLG_HIKASHOP_CARTREMINDER_CART_TITLE}
</h1>
<!-- EO TITLE -->
<!-- MAIN MESSAGE -->
<p style="margin:0 0 4px;">{TXT:PLG_HIKASHOP_CARTREMINDER_HELLO}</p>
<p style="margin:0 0 14px;">{TXT:PLG_HIKASHOP_CARTREMINDER_CART_INTRO}</p>
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
</table>
<!-- EO PRODUCTS LIST -->
<!-- CART BUTTON -->
<p style="margin:22px 0;">
	<a href="{VAR:URL}" style="display:inline-block;padding:12px 22px;border-radius:6px;background:#b4976e;color:#ffffff;font-size:15px;font-weight:bold;text-decoration:none;">{TXT:PLG_HIKASHOP_CARTREMINDER_CART_BUTTON}</a>
</p>
<!-- EO CART BUTTON -->
<!-- HELP MESSAGE -->
<p style="margin:0 0 14px;">{TXT:PLG_HIKASHOP_CARTREMINDER_CART_HELP}</p>
<!-- EO HELP MESSAGE -->
<!-- BEST REGARDS -->
<p style="margin:0 0 14px;">{TXT:REGARDS}</p>
<!-- EO BEST REGARDS -->
<!-- FOOTER -->
<p style="margin:24px 0 0;padding-top:12px;border-top:1px solid #ddd;font-size:11px;line-height:16px;color:#8a8a8a;">
	{TXT:PLG_HIKASHOP_CARTREMINDER_CART_FOOTER}<br/>
	<a href="{VAR:STOP_URL}" style="color:#8a8a8a;">{TXT:PLG_HIKASHOP_CARTREMINDER_CART_STOP}</a>
</p>
<!-- EO FOOTER -->
		</td>
		<td class="w20" width="20"></td>
	</tr>
</table>
