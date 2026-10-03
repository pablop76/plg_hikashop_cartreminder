<?php
/**
 * Przypomnienie o nieopłaconym zamówieniu - dane dla szablonu order_payment_reminder.html.php.
 * $data przygotowuje wtyczka plg_hikashop_cartreminder: url, order_number, order_date, total,
 * bank_info, reset_url, products (name, quantity, image).
 */
defined('_JEXEC') or die('Restricted access');
?><?php
$texts = array(
	'INTRO' => \Joomla\CMS\Language\Text::sprintf('PLG_HIKASHOP_CARTREMINDER_ORDER_INTRO', htmlspecialchars((string)$data->order_number, ENT_QUOTES, 'UTF-8'), $data->order_date),
	'BANK_NOTE' => \Joomla\CMS\Language\Text::sprintf('PLG_HIKASHOP_CARTREMINDER_ORDER_BANK_NOTE', htmlspecialchars((string)$data->order_number, ENT_QUOTES, 'UTF-8')),
	'REGARDS' => \Joomla\CMS\Language\Text::sprintf('PLG_HIKASHOP_CARTREMINDER_REGARDS', htmlspecialchars((string)$mail->from_name, ENT_QUOTES, 'UTF-8')),
);

// BANK_INFO to HTML z ustawień metody płatności (wpisuje je administrator), RESET_URL jest tylko dla klienta z kontem
$vars = array(
	'URL' => htmlspecialchars($data->url, ENT_QUOTES, 'UTF-8'),
	'TOTAL' => $data->total,
	'BANK_INFO' => $data->bank_info,
	'RESET_URL' => htmlspecialchars($data->reset_url, ENT_QUOTES, 'UTF-8'),
);

$orderProducts = array();
foreach($data->products as $product) {
	$imageData = '';
	if(!empty($product->image))
		$imageData = '<img src="'.htmlspecialchars($product->image, ENT_QUOTES, 'UTF-8').'" width="60" height="60" alt="" style="display:block;border-radius:6px;"/>';

	$orderProducts[] = array(
		'PRODUCT_IMG' => $imageData,
		'PRODUCT_NAME' => htmlspecialchars(strip_tags($product->name), ENT_QUOTES, 'UTF-8'),
		'PRODUCT_QUANTITY' => (int)$product->quantity,
	);
}
$templates = array('PRODUCT_LINE' => $orderProducts);
