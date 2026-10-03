<?php
/**
 * Przypomnienie o porzuconym koszyku - dane dla szablonu cart_reminder.html.php.
 * $data przygotowuje wtyczka plg_hikashop_cartreminder: url, stop_url, products (name, quantity, image).
 */
defined('_JEXEC') or die('Restricted access');
?><?php
$texts = array(
	'REGARDS' => \Joomla\CMS\Language\Text::sprintf('PLG_HIKASHOP_CARTREMINDER_REGARDS', htmlspecialchars((string)$mail->from_name, ENT_QUOTES, 'UTF-8')),
);

$vars = array(
	'URL' => htmlspecialchars($data->url, ENT_QUOTES, 'UTF-8'),
	'STOP_URL' => htmlspecialchars($data->stop_url, ENT_QUOTES, 'UTF-8'),
);

$cartProducts = array();
foreach($data->products as $product) {
	$imageData = '';
	if(!empty($product->image))
		$imageData = '<img src="'.htmlspecialchars($product->image, ENT_QUOTES, 'UTF-8').'" width="60" height="60" alt="" style="display:block;border-radius:6px;"/>';

	$cartProducts[] = array(
		'PRODUCT_IMG' => $imageData,
		'PRODUCT_NAME' => htmlspecialchars(strip_tags($product->name), ENT_QUOTES, 'UTF-8'),
		'PRODUCT_QUANTITY' => (int)$product->quantity,
	);
}
$templates = array('PRODUCT_LINE' => $cartProducts);
