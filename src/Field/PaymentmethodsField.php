<?php
namespace Pablop76\Plugin\Hikashop\Cartreminder\Field;

// no direct access
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/**
 * Pole w ustawieniach wtyczki: lista metod płatności HikaShop do zaznaczenia.
 *
 * Wartością jest nazwa wtyczki płatności (np. banktransfer), bo z nią wtyczka porównuje
 * zamówienie (order_payment_method). Kilka metod tej samej wtyczki to jedna pozycja.
 */
class PaymentmethodsField extends ListField
{
    protected $type = 'Paymentmethods';

    protected function getInput()
    {
        // Lista bez zaznaczenia nie trafia do formularza i wróciłaby wartość domyślna.
        // Pusta wartość obok listy pozwala odznaczyć wszystko (csv() ją pomija).
        $empty = $this->multiple ? '<input type="hidden" name="' . $this->name . '" value="">' : '';

        return $empty . parent::getInput();
    }

    protected function getOptions()
    {
        $methods = [];

        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select($db->quoteName(['payment_type', 'payment_name', 'payment_published']))
                ->from($db->quoteName('#__hikashop_payment'))
                ->order($db->quoteName(['payment_ordering', 'payment_id']));
            $rows = $db->setQuery($query)->loadObjectList();
        } catch (\Throwable $e) {
            // Bez HikaShop nie ma tabeli metod; zostają tylko zapisane wartości
            $rows = [];
        }

        foreach ($rows as $row) {
            $type = (string) $row->payment_type;

            if ($type === '') {
                continue;
            }

            $methods[$type]['names'][] = (string) $row->payment_name;
            $methods[$type]['published'] = !empty($methods[$type]['published']) || (int) $row->payment_published === 1;
        }

        // Zapisana wartość, której nie ma już wśród metod, nie może zniknąć przy zapisie
        $saved = \is_array($this->value) ? $this->value : explode(',', (string) $this->value);

        foreach ($saved as $type) {
            $type = trim((string) $type);

            if ($type !== '' && !isset($methods[$type])) {
                $methods[$type] = ['names' => [], 'published' => true];
            }
        }

        $options = [];

        foreach ($methods as $type => $method) {
            $names = array_unique(array_filter($method['names'], 'strlen'));
            $details = $type . ($method['published'] ? '' : ', ' . Text::_('PLG_HIKASHOP_CARTREMINDER_PAYMENT_UNPUBLISHED'));
            $text = $names ? implode(', ', $names) . ' (' . $details . ')' : $details;
            $options[] = HTMLHelper::_('select.option', $type, $text);
        }

        return array_merge(parent::getOptions(), $options);
    }
}
