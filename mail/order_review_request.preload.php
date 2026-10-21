<?php
/**
 * Prośba o opinię po wysłaniu zamówienia - dane dla szablonu order_review_request.html.php.
 * $data przygotowuje wtyczka plg_hikashop_cartreminder: order_number, stop_url, products (name, image, url).
 */
defined('_JEXEC') or die('Restricted access');
?><?php
$texts = array(
	'INTRO' => \Joomla\CMS\Language\Text::sprintf('PLG_HIKASHOP_CARTREMINDER_REVIEW_INTRO', htmlspecialchars((string)$data->order_number, ENT_QUOTES, 'UTF-8')),
	'REGARDS' => \Joomla\CMS\Language\Text::sprintf('PLG_HIKASHOP_CARTREMINDER_REGARDS', htmlspecialchars((string)$mail->from_name, ENT_QUOTES, 'UTF-8')),
);

$vars = array(
	'STOP_URL' => htmlspecialchars($data->stop_url, ENT_QUOTES, 'UTF-8'),
);

$reviewProducts = array();
foreach($data->products as $product) {
	$imageData = '';
	if(!empty($product->image))
		$imageData = '<img src="'.htmlspecialchars($product->image, ENT_QUOTES, 'UTF-8').'" width="60" height="60" alt="" style="display:block;border-radius:6px;"/>';

	$reviewProducts[] = array(
		'PRODUCT_IMG' => $imageData,
		'PRODUCT_NAME' => htmlspecialchars(strip_tags($product->name), ENT_QUOTES, 'UTF-8'),
		'PRODUCT_URL' => htmlspecialchars($product->url, ENT_QUOTES, 'UTF-8'),
	);
}
$templates = array('PRODUCT_LINE' => $reviewProducts);
