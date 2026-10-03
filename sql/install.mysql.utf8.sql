-- Dziennik przypomnień i lista adresów, które z nich zrezygnowały.
-- type:   cart (ref_id = cart_id), order (ref_id = order_id), optout (ref_id = 0)
-- status: pending (rezerwacja przed wysyłką albo nieudana próba), sent, failed (po 3 próbach), optout
-- test:   1 = wpis z trybu testowego (nie blokuje późniejszej prawdziwej wysyłki)
-- order_id, recovered, amount: opłacone zamówienie, które przyszło po przypomnieniu
-- Unikalny indeks idx_ref nie pozwala dwóm przebiegom crona zarezerwować tej samej wysyłki.
CREATE TABLE IF NOT EXISTS `#__hikashop_cartreminder` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `type` varchar(10) NOT NULL,
    `ref_id` int unsigned NOT NULL DEFAULT 0,
    `email` varchar(255) NOT NULL DEFAULT '',
    `sent` int unsigned NOT NULL DEFAULT 0,
    `clicked` int unsigned NOT NULL DEFAULT 0,
    `test` tinyint unsigned NOT NULL DEFAULT 0,
    `status` varchar(10) NOT NULL DEFAULT 'sent',
    `attempts` tinyint unsigned NOT NULL DEFAULT 1,
    `error` varchar(255) NOT NULL DEFAULT '',
    `order_id` int unsigned NOT NULL DEFAULT 0,
    `recovered` int unsigned NOT NULL DEFAULT 0,
    `amount` decimal(17,5) NOT NULL DEFAULT 0.00000,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_ref` (`type`, `ref_id`, `test`, `email`(150)),
    KEY `idx_email` (`email`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
