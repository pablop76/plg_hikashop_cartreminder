-- 1.1.0: status i licznik prób wysyłki, zapis odzyskanego zamówienia, unikalny indeks rezerwacji
ALTER TABLE `#__hikashop_cartreminder`
    ADD COLUMN `status` varchar(10) NOT NULL DEFAULT 'sent',
    ADD COLUMN `attempts` tinyint unsigned NOT NULL DEFAULT 1,
    ADD COLUMN `error` varchar(255) NOT NULL DEFAULT '',
    ADD COLUMN `order_id` int unsigned NOT NULL DEFAULT 0,
    ADD COLUMN `recovered` int unsigned NOT NULL DEFAULT 0,
    ADD COLUMN `amount` decimal(17,5) NOT NULL DEFAULT 0.00000,
    DROP KEY `idx_ref`,
    ADD UNIQUE KEY `idx_ref` (`type`, `ref_id`, `test`, `email`(150));

UPDATE `#__hikashop_cartreminder` SET `status` = 'optout' WHERE `type` = 'optout';
