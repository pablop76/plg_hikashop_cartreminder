<?php
namespace Pablop76\Plugin\Hikashop\Cartreminder\Extension;

// no direct access
defined('_JEXEC') or die;

use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Mail\MailHelper;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

class Cartreminder extends CMSPlugin
{
    /**
     * Load the language file on instantiation
     */
    protected $autoloadLanguage = true;

    private const TABLE = '#__hikashop_cartreminder';
    private const MAIL_CART = 'cart_reminder';
    private const MAIL_ORDER = 'order_payment_reminder';
    private const MAIL_REVIEW = 'order_review_request';

    /**
     * Tyle razy próbujemy wysłać jedno przypomnienie, zanim wpis dostanie status "failed"
     */
    private const MAX_ATTEMPTS = 3;

    /**
     * Odstęp między próbami wysyłki (sekundy). Świeższy wpis "pending" oznacza wysyłkę w toku.
     */
    private const RETRY_AFTER = 3600;

    /**
     * Zamówienie złożone do tylu dni po mailu o koszyku liczymy jako odzyskane
     */
    private const RECOVERY_DAYS = 14;

    /**
     * Adresy, na które w tym przebiegu crona już coś poszło
     */
    private $sentTo = [];

    /**
     * Zgłasza maile do HikaShop → System → E-maile, żeby dało się je tam edytować
     * i wyłączać. Szablony leżą w katalogu wtyczki (mail/).
     */
    public function onMailListing(&$files)
    {
        $folder = JPATH_PLUGINS . DIRECTORY_SEPARATOR . 'hikashop' . DIRECTORY_SEPARATOR . 'cartreminder'
            . DIRECTORY_SEPARATOR . 'mail' . DIRECTORY_SEPARATOR;

        foreach ([self::MAIL_CART, self::MAIL_ORDER, self::MAIL_REVIEW] as $name) {
            $files[] = [
                'folder' => $folder,
                'name' => Text::_('PLG_HIKASHOP_CARTREMINDER_MAIL_' . strtoupper($name)),
                'filename' => $name,
                'file' => $name,
            ];
        }
    }

    /**
     * Wysyłka przypomnień przy każdym wywołaniu crona HikaShop
     *
     * Najpierw nieopłacone zamówienia (klient kliknął już "Zamawiam i płacę"),
     * potem porzucone koszyki, na końcu prośby o opinię - w ramach wspólnego limitu wiadomości na przebieg.
     */
    public function onHikashopCronTrigger(&$messages)
    {
        if (!$this->loadHikashop()) {
            return true;
        }

        $this->cleanLog();
        $this->markRecovered();

        $test = (int) $this->params->get('test_mode', 1);
        $testEmail = trim((string) $this->params->get('test_email', ''));

        // Tryb testowy bez adresu testowego = nic nie wysyłamy
        if ($test && !MailHelper::isEmailAddress($testEmail)) {
            return true;
        }

        $mode = (string) $this->params->get('mode', 'both');
        $limit = max(1, (int) $this->params->get('limit', 10));
        $orders = 0;
        $carts = 0;
        $reviews = 0;

        if ($mode === 'both' || $mode === 'orders') {
            $orders = $this->remindOrders($limit, $test, $testEmail);
        }

        if (($mode === 'both' || $mode === 'carts') && $limit - $orders > 0) {
            $carts = $this->remindCarts($limit - $orders, $test, $testEmail);
        }

        if ((int) $this->params->get('review', 0) && $limit - $orders - $carts > 0) {
            $reviews = $this->requestReviews($limit - $orders - $carts, $test, $testEmail);
        }

        if ($orders || $carts || $reviews) {
            $messages[] = Text::sprintf('PLG_HIKASHOP_CARTREMINDER_CRON_REPORT', $orders, $carts, $reviews);
        }

        return true;
    }

    /**
     * Linki z maila (com_ajax, group=hikashop, plugin=cartreminder):
     *
     * a=cart&id=N&k=...   - przypina koszyk N do sesji odwiedzającego i przenosi do kasy
     * a=stop&e=...&k=...  - dopisuje adres do listy rezygnacji z przypomnień o koszyku
     * t=1                 - link z maila testowego: sprawdza podpis, ale niczego nie zmienia
     *
     * Oba linki są podpisane (HMAC z sekretem witryny), więc nie da się ich podrobić
     * dla cudzego koszyka ani adresu.
     */
    public function onAjaxCartreminder(AjaxEvent $event)
    {
        $app = $this->getApplication();

        if (!$app || !$app->isClient('site') || !$this->loadHikashop()) {
            return;
        }

        if ($app->getInput()->getCmd('a') === 'stop') {
            $this->optOut();
            return;
        }

        $this->restoreCart();
    }

    /**
     * Przypomnienia o nieopłaconych zamówieniach
     */
    private function remindOrders(int $limit, int $test, string $testEmail): int
    {
        $config = hikashop_config();

        if (!(int) $config->get(self::MAIL_ORDER . '.published', 1)) {
            return 0;
        }

        $statuses = $this->csv((string) $config->get('order_unpaid_statuses', 'created'));

        if (!$statuses) {
            return 0;
        }

        $db = $this->db();
        $now = time();
        $delay = max(1, (int) $this->params->get('order_delay', 24)) * 3600;
        $slowDelay = max(1, (int) $this->params->get('order_slow_delay', 72)) * 3600;
        $slowMethods = $this->csv((string) $this->params->get('order_slow_methods', 'banktransfer'));
        $skipMethods = $this->csv((string) $this->params->get('order_skip_methods', 'collectondelivery'));
        $maxAge = max(1, (int) $this->params->get('max_age', 7)) * 86400;

        $query = $db->getQuery(true)
            ->select($db->quoteName([
                'o.order_id', 'o.order_number', 'o.order_created', 'o.order_full_price', 'o.order_currency_id',
                'o.order_token', 'o.order_payment_method', 'o.order_payment_id', 'u.user_email', 'u.user_cms_id',
            ]))
            ->from($db->quoteName('#__hikashop_order', 'o'))
            ->join('INNER', $db->quoteName('#__hikashop_user', 'u') . ' ON u.user_id = o.order_user_id')
            ->where('o.order_type = ' . $db->quote('sale'))
            ->where('o.order_status IN (' . implode(',', array_map([$db, 'quote'], $statuses)) . ')')
            ->where('o.order_full_price > 0')
            ->where('o.order_created < ' . ($now - min($delay, $slowDelay)))
            ->where('o.order_created > ' . ($now - $maxAge))
            ->where($this->openSql('order', 'o.order_id', $test, $now))
            ->order('o.order_created DESC');

        if ($skipMethods) {
            $query->where('o.order_payment_method NOT IN (' . implode(',', array_map([$db, 'quote'], $skipMethods)) . ')');
        }

        // Część kandydatów odpada dopiero w PHP, stąd zapas ponad limit
        $rows = $db->setQuery($query, 0, $limit * 5)->loadObjectList();
        $sent = 0;
        $tried = 0;

        foreach ($rows as $row) {
            // Limit liczy próby, nie tylko udane wysyłki: przy awarii poczty nie mielimy całej listy
            if ($tried >= $limit) {
                break;
            }

            $email = strtolower(trim((string) $row->user_email));
            $orderDelay = in_array($row->order_payment_method, $slowMethods, true) ? $slowDelay : $delay;

            if (!MailHelper::isEmailAddress($email) || isset($this->sentTo[$email])
                || $this->isExcluded($email)
                || (int) $row->order_created > $now - $orderDelay
                || $this->recentlyReminded($email, $test)
                // Klient złożył później kolejne zamówienie (np. ponowił z inną płatnością)
                || $this->hasOrderSince($email, (int) $row->order_created)) {
                continue;
            }

            $data = new \stdClass();
            $data->products = $this->loadOrderProducts((int) $row->order_id);

            if (!$data->products) {
                continue;
            }

            $data->order_number = $row->order_number;
            $data->order_date = HTMLHelper::_('date', (int) $row->order_created, 'd.m.Y');
            $data->total = hikashop_get('class.currency')->format($row->order_full_price, (int) $row->order_currency_id);
            $data->url = $this->payUrl($row);
            $data->bank_info = $this->bankInfo($row);
            // Klient z kontem płaci po zalogowaniu (tak działa HikaShop), więc dostaje link do nowego hasła
            $data->reset_url = (int) $row->user_cms_id > 0 ? $this->resetUrl() : '';

            $id = $this->claim('order', (int) $row->order_id, $email, $test);

            if (!$id) {
                continue;
            }

            $tried++;
            $error = $this->send(self::MAIL_ORDER, $data, 'PLG_HIKASHOP_CARTREMINDER_ORDER_SUBJECT', (string) $row->order_number, $email, $test, $testEmail);
            $this->finish($id, $error);

            if ($error !== '') {
                continue;
            }

            $this->sentTo[$email] = true;
            $sent++;
        }

        return $sent;
    }

    /**
     * Przypomnienia o porzuconych koszykach
     *
     * Bierzemy tylko koszyki przypisane do klienta sklepu (user_id > 0), bo tylko one
     * mają adres e-mail. Koszyki anonimowe (w praktyce głównie roboty) są pomijane.
     */
    private function remindCarts(int $limit, int $test, string $testEmail): int
    {
        if (!(int) hikashop_config()->get(self::MAIL_CART . '.published', 1)) {
            return 0;
        }

        $db = $this->db();
        $now = time();
        $delay = max(1, (int) $this->params->get('cart_delay', 4)) * 3600;
        $maxAge = max(1, (int) $this->params->get('max_age', 7)) * 86400;
        $consent = (int) $this->params->get('consent', 1);

        $query = $db->getQuery(true)
            ->select($db->quoteName(['c.cart_id', 'c.cart_modified', 'u.user_email']))
            ->from($db->quoteName('#__hikashop_cart', 'c'))
            ->join('INNER', $db->quoteName('#__hikashop_user', 'u') . ' ON u.user_id = c.user_id')
            ->where('c.cart_type = ' . $db->quote('cart'))
            ->where('c.user_id > 0')
            ->where('c.cart_modified < ' . ($now - $delay))
            ->where('c.cart_modified > ' . ($now - $maxAge))
            ->where($this->openSql('cart', 'c.cart_id', $test, $now))
            ->order('c.cart_modified DESC');

        $rows = $db->setQuery($query, 0, $limit * 5)->loadObjectList();
        $sent = 0;
        $tried = 0;

        foreach ($rows as $row) {
            if ($tried >= $limit) {
                break;
            }

            $email = strtolower(trim((string) $row->user_email));

            if (!MailHelper::isEmailAddress($email) || isset($this->sentTo[$email])
                || $this->isExcluded($email)
                || $this->isOptedOut($email)
                || $this->recentlyReminded($email, $test)
                // Godzina zapasu: zamówienie złożone tuż przed ostatnią zmianą koszyka też się liczy
                || $this->hasOrderSince($email, (int) $row->cart_modified - 3600)
                || ($consent && !$this->hasConsent($email, (string) $this->params->get('acym_lists', '')))) {
                continue;
            }

            $data = new \stdClass();
            $data->products = $this->loadCartProducts((int) $row->cart_id);

            if (!$data->products) {
                continue;
            }

            // Linki z maila testowego dostają znacznik t=1 i osobny podpis: po kliknięciu niczego nie zmieniają
            $mark = $test ? '&t=1' : '';
            $data->url = $this->actionUrl('a=cart&id=' . (int) $row->cart_id . $mark . '&k=' . $this->sign('cart|' . (int) $row->cart_id . '|' . $email . $mark));
            $data->stop_url = $this->stopUrl($email, $mark);

            $shopName = (string) hikashop_config()->get('from_name');

            $id = $this->claim('cart', (int) $row->cart_id, $email, $test);

            if (!$id) {
                continue;
            }

            $tried++;
            $error = $this->send(self::MAIL_CART, $data, 'PLG_HIKASHOP_CARTREMINDER_CART_SUBJECT', $shopName, $email, $test, $testEmail);
            $this->finish($id, $error);

            if ($error !== '') {
                continue;
            }

            $this->sentTo[$email] = true;
            $sent++;
        }

        return $sent;
    }

    /**
     * Prośby o opinię kilka dni po wysłaniu zamówienia
     *
     * Moment wysyłki to ostatnia zmiana na jeden ze statusów z konfiguracji (historia zamówienia),
     * a zamówienie musi mieć taki status nadal. Okno wysyłki chroni klientów sprzed miesięcy
     * przed mailem zaraz po włączeniu funkcji.
     */
    private function requestReviews(int $limit, int $test, string $testEmail): int
    {
        if (!(int) hikashop_config()->get(self::MAIL_REVIEW . '.published', 1)) {
            return 0;
        }

        $statuses = $this->csv((string) $this->params->get('review_statuses', 'shipped'));

        if (!$statuses) {
            return 0;
        }

        $db = $this->db();
        $now = time();
        $delay = max(1, (int) $this->params->get('review_delay', 7)) * 86400;
        $window = max(1, (int) $this->params->get('review_window', 7)) * 86400;
        $statuses = implode(',', array_map([$db, 'quote'], $statuses));

        $shipped = $db->getQuery(true)
            ->select('h.history_order_id, MAX(h.history_created) AS shipped')
            ->from($db->quoteName('#__hikashop_history', 'h'))
            ->where('h.history_new_status IN (' . $statuses . ')')
            ->where('h.history_created > ' . ($now - $delay - $window))
            ->group('h.history_order_id');

        $query = $db->getQuery(true)
            ->select($db->quoteName(['o.order_id', 'o.order_number', 'o.order_token', 'u.user_email']))
            ->from($db->quoteName('#__hikashop_order', 'o'))
            ->join('INNER', $db->quoteName('#__hikashop_user', 'u') . ' ON u.user_id = o.order_user_id')
            ->join('INNER', '(' . $shipped . ') AS s ON s.history_order_id = o.order_id')
            ->where('o.order_type = ' . $db->quote('sale'))
            ->where('o.order_status IN (' . $statuses . ')')
            ->where('o.order_token <> ' . $db->quote(''))
            ->where('s.shipped < ' . ($now - $delay))
            ->where($this->openSql('review', 'o.order_id', $test, $now))
            ->order('s.shipped ASC');

        $rows = $db->setQuery($query, 0, $limit * 5)->loadObjectList();
        $shopName = (string) hikashop_config()->get('from_name');
        $sent = 0;
        $tried = 0;

        foreach ($rows as $row) {
            if ($tried >= $limit) {
                break;
            }

            $email = strtolower(trim((string) $row->user_email));

            if (!MailHelper::isEmailAddress($email) || isset($this->sentTo[$email])
                || $this->isExcluded($email)
                || $this->isOptedOut($email)
                // Kilka zamówień w krótkim czasie = jedna prośba o opinię
                || $this->recentlyReminded($email, $test, ['review'])
                || !$this->mayAskForReview($email, (int) $row->order_id)) {
                continue;
            }

            $data = new \stdClass();
            $data->products = $this->loadReviewProducts($row, $email, $test);

            if (!$data->products) {
                continue;
            }

            $data->order_number = $row->order_number;
            $data->stop_url = $this->stopUrl($email, $test ? '&t=1' : '');

            $id = $this->claim('review', (int) $row->order_id, $email, $test);

            if (!$id) {
                continue;
            }

            $tried++;
            $error = $this->send(self::MAIL_REVIEW, $data, 'PLG_HIKASHOP_CARTREMINDER_REVIEW_SUBJECT', (string) $row->order_number, $email, $test, $testEmail);
            $this->finish($id, $error);

            if ($error !== '') {
                continue;
            }

            $this->sentTo[$email] = true;
            $sent++;
        }

        return $sent;
    }

    /**
     * Produkty zamówienia do prośby o opinię: nazwa, miniatura, link do formularza oceny
     *
     * Ocena należy do produktu głównego, więc warianty łączymy z rodzicem i każdy produkt jest raz.
     * Pomijamy produkty niewidoczne w sklepie i te, które ten adres już ocenił (np. z maila o wysyłce).
     * Link niesie numer i token zamówienia: HikaShop uznaje wtedy klienta za kupującego bez logowania.
     */
    private function loadReviewProducts(\stdClass $order, string $email, int $test): array
    {
        $db = $this->db();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['op.product_id', 'p.product_parent_id']))
            ->from($db->quoteName('#__hikashop_order_product', 'op'))
            ->join('INNER', $db->quoteName('#__hikashop_product', 'p') . ' ON p.product_id = op.product_id')
            ->where('op.order_id = ' . (int) $order->order_id)
            ->where('op.order_product_quantity > 0')
            // Opcja dodana do pozycji nie jest osobnym zakupem (kolumna dopuszcza NULL)
            ->where('COALESCE(op.order_product_option_parent_id, 0) = 0')
            ->order('op.order_product_id ASC');
        $ids = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $id = (int) $row->product_parent_id ?: (int) $row->product_id;
            $ids[$id] = $id;
        }

        if (!$ids) {
            return [];
        }

        $query = $db->getQuery(true)
            ->select('DISTINCT ' . $db->quoteName('vote_ref_id'))
            ->from($db->quoteName('#__hikashop_vote'))
            ->where($db->quoteName('vote_type') . ' = ' . $db->quote('product'))
            ->where($db->quoteName('vote_email') . ' = ' . $db->quote($email))
            ->where($db->quoteName('vote_ref_id') . ' IN (' . implode(',', $ids) . ')');
        $ids = array_diff($ids, array_map('intval', $db->setQuery($query)->loadColumn()));

        if (!$ids) {
            return [];
        }

        // Mail testowy czyta administrator: bez tokenu link nie pozwoli ocenić w imieniu klienta
        $params = $test ? '' : 'order_id=' . (int) $order->order_id . '&order_token=' . urlencode((string) $order->order_token) . '&';
        $params .= 'utm_source=cartreminder&utm_medium=email&utm_campaign=prosba-o-opinie#hikashop_comment_form';

        $productClass = hikashop_get('class.product');
        $images = $this->imagesFor($ids);
        $products = [];

        foreach ($ids as $id) {
            $product = $productClass->get($id);

            if (!$product || (int) $product->product_published !== 1) {
                continue;
            }

            $url = hikashop_contentLink('product&task=show&cid=' . $id . '&name=' . $product->product_alias, $product, false, false, false, true);

            $products[] = (object) [
                'name' => (string) hikashop_translate($product->product_name),
                'image' => $this->thumbnail($images[$id] ?? ''),
                'url' => $url . (strpos($url, '?') === false ? '?' : '&') . $params,
            ];
        }

        return $products;
    }

    /**
     * Buduje wiadomość z szablonu HikaShop i wysyła ją
     *
     * W trybie testowym wiadomość idzie na adres testowy, a właściwy adresat jest w temacie.
     * Zwraca opis błędu; pusty tekst oznacza, że wiadomość wyszła.
     */
    private function send(string $mailName, \stdClass $data, string $subjectKey, string $subjectValue, string $email, int $test, string $testEmail): string
    {
        $mailClass = hikashop_get('class.mail');
        $mail = $mailClass->get($mailName, $data);

        if (empty($mail->body)) {
            return 'Empty mail body';
        }

        // Temat wpisany w System → E-maile ma pierwszeństwo przed domyślnym z pliku językowego
        $subject = trim((string) ($mail->subject ?? ''));
        $subject = str_replace('%s', $subjectValue, Text::_($subject !== '' ? $subject : $subjectKey));

        $mail->subject = $test ? '[TEST: ' . $email . '] ' . $subject : $subject;
        $mail->dst_email = $test ? $testEmail : $email;
        $mail->dst_name = '';

        if ($mailClass->sendMail($mail)) {
            return '';
        }

        $error = trim((string) ($mailClass->mailer->ErrorInfo ?? ''));

        return $error !== '' ? $error : 'Mail not sent';
    }

    /**
     * Pozycje koszyka do maila: nazwa, ilość, miniatura
     *
     * Zwraca pustą tablicę, gdy koszyka nie należy przypominać: nie ma w nim nic,
     * co da się dziś kupić, albo zawiera produkt z kategorii wyłączonej w konfiguracji.
     * Cen celowo nie ma - liczy je kasa (strefa podatkowa, rabaty), a mail mógłby pokazać inne.
     */
    private function loadCartProducts(int $cartId): array
    {
        $db = $this->db();
        $query = $db->getQuery(true)
            ->select($db->quoteName([
                'cp.product_id', 'cp.cart_product_quantity', 'p.product_name', 'p.product_parent_id',
                'p.product_published', 'p.product_quantity',
            ]))
            ->select($db->quoteName('pp.product_name', 'parent_name'))
            ->select($db->quoteName('pp.product_published', 'parent_published'))
            ->select($db->quoteName('pp.product_quantity', 'parent_quantity'))
            ->from($db->quoteName('#__hikashop_cart_product', 'cp'))
            ->join('INNER', $db->quoteName('#__hikashop_product', 'p') . ' ON p.product_id = cp.product_id')
            ->join('LEFT', $db->quoteName('#__hikashop_product', 'pp') . ' ON pp.product_id = p.product_parent_id')
            ->where('cp.cart_id = ' . $cartId)
            ->where('cp.cart_product_quantity > 0')
            ->order('cp.cart_product_id ASC');
        $rows = $db->setQuery($query)->loadObjectList();

        // Zostają produkty opublikowane i dostępne (ilość 0 = brak, -1 = bez limitu)
        $rows = array_values(array_filter($rows, function ($row) {
            $hasParent = (int) $row->product_parent_id > 0;

            return (int) $row->product_published === 1 && (int) $row->product_quantity !== 0
                && (!$hasParent || ((int) $row->parent_published === 1 && (int) $row->parent_quantity !== 0));
        }));

        if (!$rows) {
            return [];
        }

        $ids = array_map('intval', array_column($rows, 'product_id'));
        $parentIds = array_filter(array_map('intval', array_column($rows, 'product_parent_id')));
        $skip = array_filter(array_map('intval', $this->csv((string) $this->params->get('skip_categories', ''))));

        if ($skip) {
            // Kategorie są przypięte do produktu głównego, więc dla wariantów sprawdzamy rodzica
            $query = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__hikashop_product_category'))
                ->where($db->quoteName('product_id') . ' IN (' . implode(',', array_merge($ids, $parentIds)) . ')')
                ->where($db->quoteName('category_id') . ' IN (' . implode(',', $skip) . ')');

            if ((int) $db->setQuery($query)->loadResult() > 0) {
                return [];
            }
        }

        $suffixes = $this->variantSuffixes($parentIds ? $ids : []);
        $images = $this->imagesFor($ids);
        $products = [];

        foreach ($rows as $row) {
            $id = (int) $row->product_id;
            $name = (string) $row->product_name;

            // Wariant: nazwa produktu głównego + nazwa wariantu albo wartości jego cech
            if ((int) $row->product_parent_id > 0) {
                $suffix = trim($name) !== '' ? $name : ($suffixes[$id] ?? '');
                $name = $row->parent_name . ($suffix !== '' ? ': ' . $suffix : '');
            }

            $products[] = (object) [
                'name' => $name,
                'quantity' => (int) $row->cart_product_quantity,
                'image' => $this->thumbnail($images[$id] ?? ''),
            ];
        }

        return $products;
    }

    /**
     * Pozycje zamówienia do maila: nazwa, ilość, miniatura
     *
     * Kwot pozycji nie pokazujemy: do zapłaty jest suma zamówienia (z dostawą, rabatem i opłatami),
     * a pełne rozliczenie klient ma w mailu z potwierdzeniem zamówienia.
     */
    private function loadOrderProducts(int $orderId): array
    {
        $db = $this->db();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['product_id', 'order_product_name', 'order_product_quantity']))
            ->from($db->quoteName('#__hikashop_order_product'))
            ->where($db->quoteName('order_id') . ' = ' . $orderId)
            ->where($db->quoteName('order_product_quantity') . ' > 0')
            ->order($db->quoteName('order_product_id') . ' ASC');
        $rows = $db->setQuery($query)->loadObjectList();

        if (!$rows) {
            return [];
        }

        $images = $this->imagesFor(array_column($rows, 'product_id'));
        $products = [];

        foreach ($rows as $row) {
            $products[] = (object) [
                'name' => (string) $row->order_product_name,
                'quantity' => (int) $row->order_product_quantity,
                'image' => $this->thumbnail($images[(int) $row->product_id] ?? ''),
            ];
        }

        return $products;
    }

    /**
     * Wartości cech dla wariantów bez własnej nazwy (id wariantu => "wartość, wartość")
     */
    private function variantSuffixes(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $db = $this->db();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['v.variant_product_id', 'c.characteristic_value']))
            ->from($db->quoteName('#__hikashop_variant', 'v'))
            ->join('INNER', $db->quoteName('#__hikashop_characteristic', 'c') . ' ON c.characteristic_id = v.variant_characteristic_id')
            ->where('v.variant_product_id IN (' . implode(',', $ids) . ')')
            ->order('v.ordering ASC');
        $suffixes = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $suffixes[(int) $row->variant_product_id][] = trim((string) $row->characteristic_value);
        }

        return array_map(function ($values) {
            return implode(', ', $values);
        }, $suffixes);
    }

    /**
     * Pierwsze zdjęcie każdego produktu (id => ścieżka pliku); wariant bez zdjęcia dostaje zdjęcie rodzica
     */
    private function imagesFor(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if (!$ids) {
            return [];
        }

        $db = $this->db();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['product_id', 'product_parent_id']))
            ->from($db->quoteName('#__hikashop_product'))
            ->where($db->quoteName('product_id') . ' IN (' . implode(',', $ids) . ')');
        $parents = $db->setQuery($query)->loadAssocList('product_id', 'product_parent_id');

        $all = array_unique(array_merge($ids, array_filter(array_map('intval', $parents))));
        $query = $db->getQuery(true)
            ->select($db->quoteName(['file_ref_id', 'file_path']))
            ->from($db->quoteName('#__hikashop_file'))
            ->where($db->quoteName('file_type') . ' = ' . $db->quote('product'))
            ->where($db->quoteName('file_ref_id') . ' IN (' . implode(',', $all) . ')')
            ->order($db->quoteName('file_ordering') . ' ASC, ' . $db->quoteName('file_id') . ' ASC');
        $files = [];

        foreach ($db->setQuery($query)->loadObjectList() as $file) {
            if (!isset($files[(int) $file->file_ref_id])) {
                $files[(int) $file->file_ref_id] = (string) $file->file_path;
            }
        }

        $images = [];

        foreach ($ids as $id) {
            $images[$id] = $files[$id] ?? $files[(int) ($parents[$id] ?? 0)] ?? '';
        }

        return $images;
    }

    /**
     * Pełny adres miniatury 60x60 (tak samo, jak robi to mail HikaShop o liście życzeń)
     */
    private function thumbnail(string $filePath): string
    {
        if ($filePath === '') {
            return '';
        }

        $img = hikashop_get('helper.image')->getThumbnail($filePath, [60, 60], ['forcesize' => true, 'scale' => 'outside']);

        if (empty($img->success)) {
            return '';
        }

        if (substr($img->url, 0, 3) === '../') {
            return str_replace('../', HIKASHOP_LIVE, $img->url);
        }

        if (empty($img->external)) {
            return substr(HIKASHOP_LIVE, 0, strpos(HIKASHOP_LIVE, '/', 9)) . $img->url;
        }

        return $img->url;
    }

    /**
     * Link "Płacę za zamówienie" - ten sam adres, który HikaShop daje w mailu o nowym zamówieniu.
     * Gość dostaje order_token, klient z kontem musi się zalogować.
     */
    private function payUrl(\stdClass $order): string
    {
        $url = 'index.php?option=com_hikashop&ctrl=order&task=pay&order_id=' . (int) $order->order_id;

        if ((int) $order->user_cms_id === 0 && !empty($order->order_token)) {
            $url .= '&order_token=' . urlencode($order->order_token);
        }

        return hikashop_frontendLink($url . '&utm_source=cartreminder&utm_medium=email&utm_campaign=nieoplacone-zamowienie');
    }

    /**
     * Adres formularza nowego hasła, przez pozycję menu logowania (wygląda wtedy jak reszta sklepu)
     */
    private function resetUrl(): string
    {
        $item = $this->getApplication()->getMenu()->getItems('link', 'index.php?option=com_users&view=login', true);

        return Route::_('index.php?option=com_users&view=reset' . ($item ? '&Itemid=' . (int) $item->id : ''), false, Route::TLS_IGNORE, true);
    }

    /**
     * Link z maila o koszyku: przypina koszyk do sesji odwiedzającego i przenosi do kasy
     *
     * Koszyk gościa jest w HikaShop związany z sesją przeglądarki, więc po kilku godzinach
     * klient z maila zobaczyłby pusty koszyk. Dlatego koszyk przepinamy na bieżącą sesję
     * jako anonimowy - bez adresów, żeby link nie pokazał nikomu cudzych danych.
     */
    private function restoreCart(): void
    {
        $app = $this->getApplication();
        $db = $this->db();
        $cartId = $app->getInput()->getInt('id');
        $key = (string) $app->getInput()->getAlnum('k');
        $test = $app->getInput()->getInt('t') ? 1 : 0;

        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'email', 'clicked', 'sent']))
            ->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('type') . ' = ' . $db->quote('cart'))
            ->where($db->quoteName('ref_id') . ' = ' . $cartId)
            ->where($db->quoteName('test') . ' = ' . $test)
            ->order($db->quoteName('id') . ' DESC');
        $row = $db->setQuery($query, 0, 1)->loadObject();

        if (!$row || !hash_equals($this->sign('cart|' . $cartId . '|' . $row->email . ($test ? '&t=1' : '')), $key)) {
            $this->leave('PLG_HIKASHOP_CARTREMINDER_MSG_BAD_LINK', 'warning');
        }

        // Link działa przez ograniczony czas od wysłania maila
        if ((int) $row->sent < time() - max(1, (int) $this->params->get('link_days', 7)) * 86400) {
            $this->leave('PLG_HIKASHOP_CARTREMINDER_MSG_LINK_EXPIRED', 'notice');
        }

        // Mail testowy czyta administrator, a koszyk należy do klienta - zostaje nietknięty
        if ($test) {
            $this->leave('PLG_HIKASHOP_CARTREMINDER_MSG_TEST_LINK', 'message');
        }

        $query = $db->getQuery(true)
            ->select($db->quoteName(['cart_id', 'user_id']))
            ->from($db->quoteName('#__hikashop_cart'))
            ->where($db->quoteName('cart_id') . ' = ' . $cartId)
            ->where($db->quoteName('cart_type') . ' = ' . $db->quote('cart'));
        $cart = $db->setQuery($query)->loadObject();

        // Koszyk znika po złożeniu zamówienia albo po okresie przechowywania
        if (!$cart) {
            $this->leave('PLG_HIKASHOP_CARTREMINDER_MSG_CART_GONE', 'notice');
        }

        if (!(int) $row->clicked) {
            $query = $db->getQuery(true)
                ->update($db->quoteName(self::TABLE))
                ->set($db->quoteName('clicked') . ' = ' . time())
                ->where($db->quoteName('id') . ' = ' . (int) $row->id);
            $db->setQuery($query)->execute();
        }

        $user = hikashop_loadUser(true);

        // Właściciel koszyka jest już rozpoznany (zalogowany albo w trakcie zakupu) - koszyk ma u siebie
        if (empty($user->user_id) || (int) $user->user_id !== (int) $cart->user_id) {
            $session = $app->getSession();
            // Zapis do sesji wymusza jej start, więc przeglądarka dostanie ciasteczko sesji
            $session->set('plg_hikashop_cartreminder.cart', $cartId);

            $query = $db->getQuery(true)
                ->update($db->quoteName('#__hikashop_cart'))
                ->set($db->quoteName('user_id') . ' = 0')
                ->set($db->quoteName('session_id') . ' = ' . $db->quote($session->getId()))
                ->set($db->quoteName('cart_billing_address_id') . ' = 0')
                ->set($db->quoteName('cart_shipping_address_ids') . ' = ' . $db->quote(''))
                ->set($db->quoteName('cart_shipping_ids') . ' = ' . $db->quote(''))
                ->set($db->quoteName('cart_payment_id') . ' = 0')
                ->set($db->quoteName('cart_current') . ' = 1')
                ->set($db->quoteName('cart_modified') . ' = ' . time())
                ->where($db->quoteName('cart_id') . ' = ' . $cartId);
            $db->setQuery($query)->execute();
        }

        // Adres pozycji menu kasy (ten sam, którego używa sklep); bez niej zwykły link HikaShop
        $itemid = (int) hikashop_config()->get('checkout_itemid', 0);
        $url = $itemid ? Route::_('index.php?Itemid=' . $itemid, false) : hikashop_completeLink('checkout', false, true);
        $url .= (strpos($url, '?') === false ? '?' : '&') . 'utm_source=cartreminder&utm_medium=email&utm_campaign=porzucony-koszyk';

        $app->redirect($url);
    }

    /**
     * Link "Nie chcę dostawać takich przypomnień"
     */
    private function optOut(): void
    {
        $input = $this->getApplication()->getInput();
        $email = base64_decode(strtr((string) $input->getString('e'), '-_', '+/'), true);
        $key = (string) $input->getAlnum('k');
        $test = $input->getInt('t') ? 1 : 0;

        if ($email === false || !MailHelper::isEmailAddress($email) || !hash_equals($this->sign('stop|' . $email . ($test ? '&t=1' : '')), $key)) {
            $this->leave('PLG_HIKASHOP_CARTREMINDER_MSG_BAD_LINK', 'warning');
        }

        if ($test) {
            $this->leave('PLG_HIKASHOP_CARTREMINDER_MSG_TEST_LINK', 'message');
        }

        if (!$this->isOptedOut($email)) {
            $row = (object) ['type' => 'optout', 'ref_id' => 0, 'email' => $email, 'sent' => time(), 'test' => 0, 'status' => 'optout'];

            try {
                $this->db()->insertObject(self::TABLE, $row);
            } catch (\RuntimeException $e) {
                // Wpis już jest (dwa kliknięcia naraz) - nic więcej nie trzeba
            }
        }

        $this->leave('PLG_HIKASHOP_CARTREMINDER_MSG_STOPPED', 'message');
    }

    /**
     * Komunikat i powrót na stronę główną
     */
    private function leave(string $message, string $type): void
    {
        $app = $this->getApplication();
        $app->enqueueMessage(Text::_($message), $type);
        $app->redirect(Uri::root());
    }

    /**
     * Czy adres zrezygnował z przypomnień o koszyku i próśb o opinię
     */
    private function isOptedOut(string $email): bool
    {
        $db = $this->db();
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('type') . ' = ' . $db->quote('optout'))
            ->where($db->quoteName('email') . ' = ' . $db->quote($email));

        return (int) $db->setQuery($query)->loadResult() > 0;
    }

    /**
     * Czy na ten adres poszło już przypomnienie podanego rodzaju w ostatnich N dniach
     * (jedna osoba nie dostaje serii maili za kilka koszyków albo koszyk i zamówienie)
     */
    private function recentlyReminded(string $email, int $test, array $types = ['cart', 'order']): bool
    {
        $db = $this->db();
        $gap = max(1, (int) $this->params->get('min_gap', 7)) * 86400;
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('type') . ' IN (' . implode(',', array_map([$db, 'quote'], $types)) . ')')
            ->where($db->quoteName('email') . ' = ' . $db->quote($email))
            ->where($db->quoteName('test') . ' = ' . $test)
            ->where($db->quoteName('status') . ' = ' . $db->quote('sent'))
            ->where($db->quoteName('sent') . ' > ' . (time() - $gap));

        return (int) $db->setQuery($query)->loadResult() > 0;
    }

    /**
     * Czy klient o tym adresie złożył zamówienie później niż podany moment
     *
     * Po adresie e-mail, nie po id klienta: gość przy kolejnym zakupie może dostać nowy wiersz klienta.
     */
    private function hasOrderSince(string $email, int $since): bool
    {
        $db = $this->db();
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__hikashop_order', 'o'))
            ->join('INNER', $db->quoteName('#__hikashop_user', 'u') . ' ON u.user_id = o.order_user_id')
            ->where('o.order_type = ' . $db->quote('sale'))
            ->where('u.user_email = ' . $db->quote($email))
            ->where('o.order_created > ' . $since);

        return (int) $db->setQuery($query)->loadResult() > 0;
    }

    /**
     * Czy adres jest aktywnym, potwierdzonym subskrybentem AcyMailing (zgoda na wiadomości handlowe)
     *
     * Bez AcyMailinga nikt nie ma zgody, więc przy włączonym wymogu nic nie wychodzi.
     * $lists: identyfikatory list po przecinku, puste = dowolna lista.
     */
    private function hasConsent(string $email, string $lists): bool
    {
        static $hasTables = null;

        $db = $this->db();

        if ($hasTables === null) {
            $hasTables = in_array($db->getPrefix() . 'acym_user_has_list', $db->getTableList(), true);
        }

        if (!$hasTables) {
            return false;
        }

        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__acym_user', 'au'))
            ->join('INNER', $db->quoteName('#__acym_user_has_list', 'ul') . ' ON ul.user_id = au.id')
            ->where('au.email = ' . $db->quote($email))
            ->where('au.active = 1')
            ->where('au.confirmed = 1')
            ->where('ul.status = 1');

        $lists = array_filter(array_map('intval', $this->csv($lists)));

        if ($lists) {
            $query->where('ul.list_id IN (' . implode(',', $lists) . ')');
        }

        return (int) $db->setQuery($query)->loadResult() > 0;
    }

    /**
     * Czy do klienta tego zamówienia można napisać z prośbą o opinię (pole „Kto dostaje prośbę o opinię”)
     */
    private function mayAskForReview(string $email, int $orderId): bool
    {
        switch ((string) $this->params->get('review_consent', 'acym')) {
            case 'all':
                return true;
            case 'field':
                return $this->orderFieldChecked($orderId, trim((string) $this->params->get('review_field', '')));
            default:
                return $this->hasConsent($email, (string) $this->params->get('review_acym_lists', ''));
        }
    }

    /**
     * Czy pole własne zamówienia (kolumna #__hikashop_order) ma zaznaczoną zgodę: wartość inna niż pusta i 0
     *
     * Pole, którego nie ma w tabeli, oznacza brak zgody, więc przy literówce w nazwie nic nie wychodzi.
     */
    private function orderFieldChecked(int $orderId, string $column): bool
    {
        static $columns = null;

        $db = $this->db();

        if ($columns === null) {
            $columns = array_keys($db->getTableColumns('#__hikashop_order'));
        }

        if (!in_array($column, $columns, true)) {
            return false;
        }

        $query = $db->getQuery(true)
            ->select($db->quoteName($column))
            ->from($db->quoteName('#__hikashop_order'))
            ->where($db->quoteName('order_id') . ' = ' . $orderId);
        $value = trim((string) $db->setQuery($query)->loadResult());

        return $value !== '' && $value !== '0';
    }

    /**
     * Warunek SQL dla kandydatów: sprawa nie jest zamknięta ani właśnie w toku
     *
     * Wpis "pending" starszy niż RETRY_AFTER to nieudana próba - taki rekord wraca do kandydatów.
     */
    private function openSql(string $type, string $refColumn, int $test, int $now): string
    {
        $db = $this->db();

        return 'NOT EXISTS (SELECT 1 FROM ' . $db->quoteName(self::TABLE, 'r')
            . ' WHERE r.type = ' . $db->quote($type) . ' AND r.ref_id = ' . $refColumn . ' AND r.test = ' . $test
            . ' AND (r.status <> ' . $db->quote('pending') . ' OR r.sent > ' . ($now - self::RETRY_AFTER) . '))';
    }

    /**
     * Rezerwuje wysyłkę wpisem "pending" PRZED wysłaniem maila
     *
     * Unikalny indeks (type, ref_id, test, email) pilnuje, żeby dwa równoczesne przebiegi crona
     * nie wysłały tego samego przypomnienia: drugi nie założy wpisu i odpuści.
     * Zwraca id wpisu albo 0, gdy ta wysyłka nie jest nasza.
     */
    private function claim(string $type, int $refId, string $email, int $test): int
    {
        $db = $this->db();
        $now = time();

        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'status', 'attempts', 'sent']))
            ->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('type') . ' = ' . $db->quote($type))
            ->where($db->quoteName('ref_id') . ' = ' . $refId)
            ->where($db->quoteName('test') . ' = ' . $test)
            ->where($db->quoteName('email') . ' = ' . $db->quote($email));
        $row = $db->setQuery($query)->loadObject();

        if (!$row) {
            $row = (object) [
                'type' => $type,
                'ref_id' => $refId,
                'email' => $email,
                'sent' => $now,
                'test' => $test,
                'status' => 'pending',
                'attempts' => 1,
            ];

            try {
                $db->insertObject(self::TABLE, $row, 'id');
            } catch (\RuntimeException $e) {
                return 0;
            }

            return (int) $row->id;
        }

        if ($row->status !== 'pending' || (int) $row->sent > $now - self::RETRY_AFTER) {
            return 0;
        }

        // Kolejna próba po nieudanej: przejmujemy wpis tylko wtedy, gdy nikt nie zrobił tego przed nami
        $query = $db->getQuery(true)
            ->update($db->quoteName(self::TABLE))
            ->set($db->quoteName('attempts') . ' = ' . $db->quoteName('attempts') . ' + 1')
            ->set($db->quoteName('sent') . ' = ' . $now)
            ->where($db->quoteName('id') . ' = ' . (int) $row->id)
            ->where($db->quoteName('status') . ' = ' . $db->quote('pending'))
            ->where($db->quoteName('attempts') . ' = ' . (int) $row->attempts);
        $db->setQuery($query)->execute();

        return $db->getAffectedRows() === 1 ? (int) $row->id : 0;
    }

    /**
     * Zamyka próbę wysyłki: "sent" albo zapis błędu
     *
     * Po MAX_ATTEMPTS nieudanych próbach wpis dostaje status "failed" i nie wraca do kandydatów.
     */
    private function finish(int $id, string $error): void
    {
        $db = $this->db();
        $query = $db->getQuery(true)
            ->update($db->quoteName(self::TABLE))
            ->where($db->quoteName('id') . ' = ' . $id);

        if ($error === '') {
            $query->set($db->quoteName('status') . ' = ' . $db->quote('sent'))
                ->set($db->quoteName('sent') . ' = ' . time())
                ->set($db->quoteName('error') . ' = ' . $db->quote(''));
        } else {
            $query->set($db->quoteName('status') . ' = IF(' . $db->quoteName('attempts') . ' >= ' . self::MAX_ATTEMPTS
                    . ', ' . $db->quote('failed') . ', ' . $db->quote('pending') . ')')
                ->set($db->quoteName('error') . ' = ' . $db->quote(mb_substr($error, 0, 255)));
        }

        $db->setQuery($query)->execute();
    }

    /**
     * Zapisuje odzyskaną sprzedaż: numer zamówienia, czas i kwotę
     *
     * Liczy się dopiero zamówienie opłacone (statusy fakturowane z konfiguracji HikaShop).
     * Samo kliknięcie w link niczego tu nie zmienia.
     */
    private function markRecovered(): void
    {
        $db = $this->db();
        $paid = $this->csv((string) hikashop_config()->get('invoice_order_statuses', 'confirmed,shipped'));

        if (!$paid) {
            return;
        }

        $paid = implode(',', array_map([$db, 'quote'], $paid));

        // Zamówienie, o którym przypomnieliśmy, zostało opłacone
        $db->setQuery(
            'UPDATE ' . $db->quoteName(self::TABLE, 'r')
            . ' INNER JOIN ' . $db->quoteName('#__hikashop_order', 'o') . ' ON o.order_id = r.ref_id'
            . ' SET r.order_id = o.order_id, r.recovered = UNIX_TIMESTAMP(), r.amount = o.order_full_price'
            . ' WHERE r.type = ' . $db->quote('order') . ' AND r.test = 0 AND r.status = ' . $db->quote('sent')
            . ' AND r.recovered = 0 AND o.order_status IN (' . $paid . ')'
        )->execute();

        // Po mailu o koszyku ten sam adres złożył zamówienie i je opłacił (z linku albo bez)
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'email', 'sent']))
            ->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('type') . ' = ' . $db->quote('cart'))
            ->where($db->quoteName('test') . ' = 0')
            ->where($db->quoteName('status') . ' = ' . $db->quote('sent'))
            ->where($db->quoteName('recovered') . ' = 0')
            // 30 dni zapasu na zaksięgowanie płatności za zamówienie złożone w oknie RECOVERY_DAYS
            ->where($db->quoteName('sent') . ' > ' . (time() - (self::RECOVERY_DAYS + 30) * 86400))
            ->order($db->quoteName('sent') . ' DESC');

        foreach ($db->setQuery($query, 0, 100)->loadObjectList() as $row) {
            $query = $db->getQuery(true)
                ->select($db->quoteName(['o.order_id', 'o.order_created', 'o.order_full_price']))
                ->from($db->quoteName('#__hikashop_order', 'o'))
                ->join('INNER', $db->quoteName('#__hikashop_user', 'u') . ' ON u.user_id = o.order_user_id')
                ->where('o.order_type = ' . $db->quote('sale'))
                ->where('u.user_email = ' . $db->quote($row->email))
                ->where('o.order_created > ' . (int) $row->sent)
                ->where('o.order_created < ' . ((int) $row->sent + self::RECOVERY_DAYS * 86400))
                ->where('o.order_status IN (' . $paid . ')')
                ->order('o.order_created ASC');
            $order = $db->setQuery($query, 0, 1)->loadObject();

            if (!$order) {
                continue;
            }

            $query = $db->getQuery(true)
                ->update($db->quoteName(self::TABLE))
                ->set($db->quoteName('order_id') . ' = ' . (int) $order->order_id)
                ->set($db->quoteName('recovered') . ' = ' . (int) $order->order_created)
                ->set($db->quoteName('amount') . ' = ' . (float) $order->order_full_price)
                ->where($db->quoteName('id') . ' = ' . (int) $row->id);
            $db->setQuery($query)->execute();
        }
    }

    /**
     * Czy adres jest na liście wykluczeń z konfiguracji (dotyczy koszyków i zamówień)
     */
    private function isExcluded(string $email): bool
    {
        static $list = null;

        if ($list === null) {
            $list = array_map('strtolower', preg_split('/[\s,;]+/', (string) $this->params->get('excluded_emails', ''), -1, PREG_SPLIT_NO_EMPTY));
        }

        return in_array($email, $list, true);
    }

    /**
     * Dane do przelewu z ustawień metody płatności (tylko wtyczka banktransfer)
     */
    private function bankInfo(\stdClass $order): string
    {
        if ($order->order_payment_method !== 'banktransfer') {
            return '';
        }

        $payment = hikashop_get('class.payment')->get((int) $order->order_payment_id);
        $info = $payment->payment_params->information ?? '';

        return is_string($info) ? trim(hikashop_translate($info)) : '';
    }

    /**
     * Kasuje stare wpisy dziennika (okres z konfiguracji); rezygnacje zostają bezterminowo
     */
    private function cleanLog(): void
    {
        $db = $this->db();
        $days = (int) $this->params->get('retention', 180);
        $days = in_array($days, [30, 60, 90, 180], true) ? $days : 180;

        $query = $db->getQuery(true)
            ->delete($db->quoteName(self::TABLE))
            ->where($db->quoteName('type') . ' IN (' . $db->quote('cart') . ',' . $db->quote('order') . ',' . $db->quote('review') . ')')
            ->where($db->quoteName('sent') . ' < ' . (time() - $days * 86400));
        $db->setQuery($query)->execute();
    }

    /**
     * Adres obsługiwany przez onAjaxCartreminder()
     */
    private function actionUrl(string $query): string
    {
        return Uri::root() . 'index.php?option=com_ajax&group=hikashop&plugin=cartreminder&format=raw&' . $query;
    }

    /**
     * Link "Nie chcę dostawać takich wiadomości" (wspólny dla maila o koszyku i prośby o opinię)
     */
    private function stopUrl(string $email, string $mark): string
    {
        return $this->actionUrl('a=stop&e=' . rtrim(strtr(base64_encode($email), '+/', '-_'), '=') . $mark . '&k=' . $this->sign('stop|' . $email . $mark));
    }

    /**
     * Podpis linku: HMAC z sekretem witryny, skrócony do 32 znaków
     */
    private function sign(string $payload): string
    {
        return substr(hash_hmac('sha256', $payload, (string) $this->getApplication()->get('secret')), 0, 32);
    }

    /**
     * Lista z pola tekstowego "a, b, c"
     */
    private function csv(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
    }

    private function db()
    {
        return Factory::getContainer()->get('DatabaseDriver');
    }

    /**
     * Przy cronie HikaShop jest już załadowany; przy linku z maila (com_ajax) trzeba go dołączyć
     */
    private function loadHikashop(): bool
    {
        if (function_exists('hikashop_get')) {
            return true;
        }

        $helper = JPATH_ADMINISTRATOR . '/components/com_hikashop/helpers/helper.php';

        if (!is_file($helper)) {
            return false;
        }

        if (!defined('DS')) {
            define('DS', DIRECTORY_SEPARATOR);
        }

        include_once $helper;

        return function_exists('hikashop_get');
    }
}
