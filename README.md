# HikaShop – przypomnienie o koszyku i płatności

Wtyczka dla Joomla 6 i HikaShop. Wysyła klientowi jedno przypomnienie e-mail, gdy zostawił
koszyk po podaniu adresu albo złożył zamówienie i nie zapłacił.

- **Typ:** plugin Joomla, grupa `hikashop`, element `cartreminder`
- **Wymagania:** Joomla 6 (a więc PHP 8.3+ i MySQL lub MariaDB), HikaShop 6; testowana na HikaShop 6.6 Business
- **Wersja:** 1.1.0

## Co robi

Przy każdym wywołaniu crona HikaShop:

1. **Nieopłacone zamówienia.** Zamówienie ze statusem nieopłaconym (z konfiguracji HikaShop)
   dostaje mail z listą produktów, kwotą i przyciskiem płatności. Płatność online po 24 h,
   przelew po 72 h, zamówienia za pobraniem są pomijane. Przy przelewie mail pokazuje dane
   do przelewu z ustawień metody płatności. Klient z kontem dostaje link do nowego hasła,
   bo HikaShop wymaga od niego logowania przed płatnością.
2. **Porzucone koszyki.** Koszyk przypisany do klienta (czyli z adresem e-mail), niezmieniany
   od 4 h, dostaje mail z listą produktów i przyciskiem powrotu do koszyka.

Koszyki anonimowe są pomijane, bo nie mają adresu.

Wtyczka nie wysyła przypomnienia, gdy:

- adres złożył w międzyczasie zamówienie,
- na ten adres poszło już przypomnienie w ostatnich 7 dniach,
- adres zrezygnował (link w mailu) albo jest na liście wykluczeń,
- w koszyku nie ma nic, co da się dziś kupić,
- koszyk zawiera produkt z kategorii wyłączonej w konfiguracji,
- włączony jest wymóg zgody, a adres nie jest potwierdzonym subskrybentem AcyMailing.

## Instalacja

1. Zainstaluj paczkę ZIP w panelu Joomli (System → Instaluj → Rozszerzenia).
2. Wtyczka instaluje się wyłączona, z włączonym trybem testowym.
3. Skonfiguruj cron HikaShop (niżej), włącz wtyczkę i wpisz adres testowy.

Paczkę ZIP buduje się z zawartości repozytorium: `cartreminder.xml` oraz katalogi `services`,
`src`, `sql`, `mail`, `language`. Ścieżki w archiwum muszą mieć ukośniki zwykłe (`/`).

## Cron

Wtyczka działa na zdarzeniu `onHikashopCronTrigger`, więc potrzebuje prawdziwego crona
serwera wywołującego adres crona HikaShop, np. co 15 minut:

```
*/15 * * * * curl -s "https://example.com/index.php?option=com_hikashop&ctrl=cron" > /dev/null
```

Ten adres pokazuje HikaShop → Konfiguracja → Cron. Uwaga: cron HikaShop uruchamia też inne
włączone wtyczki, które na niego czekają (np. aktualizację kursów walut).

## Konfiguracja

| Pole | Domyślnie | Znaczenie |
|---|---|---|
| Co przypominać | zamówienia i koszyki | można ograniczyć do jednego |
| Tryb testowy | tak | wszystko idzie na adres testowy, właściwy adresat jest w temacie |
| Limit wiadomości na przebieg | 10 | prób wysyłki na jedno wywołanie crona |
| Nie starsze niż | 7 dni | starsze koszyki i zamówienia są pomijane |
| Odstęp dla jednego adresu | 7 dni | najwyżej jedno przypomnienie na adres w tym czasie |
| Wykluczone adresy | puste | do tych adresów wtyczka nie pisze nigdy |
| Przechowywanie dziennika | 180 dni | po tym czasie wpisy są kasowane |
| Opóźnienie koszyka | 4 h | czas od ostatniej zmiany koszyka |
| Ważność linku do koszyka | 7 dni | po terminie link niczego nie przywraca |
| Kto dostaje przypomnienie o koszyku | subskrybenci AcyMailing | albo wszyscy z adresem e-mail |
| Listy AcyMailing | dowolna | identyfikatory list po przecinku |
| Pomiń koszyki z kategorii | puste | identyfikatory kategorii HikaShop |
| Opóźnienie zamówienia | 24 h | dla płatności online |
| Metody z dłuższym opóźnieniem | `banktransfer` | 72 h |
| Metody bez przypomnień | `collectondelivery` | |

### Zgoda odbiorcy

Przypomnienie o koszyku jest wiadomością handlową i wymaga zgody odbiorcy. Dlatego domyślnie
dostają je tylko potwierdzeni subskrybenci AcyMailing. Przypomnienie o nieopłaconym zamówieniu
dotyczy zawartej umowy i tego wymogu nie ma.

## Maile

Oba maile są zgłoszone do HikaShop → System → E-maile („Przypomnienie o porzuconym koszyku”,
„Przypomnienie o nieopłaconym zamówieniu”). Można tam zmienić HTML, temat i nadawcę albo
wyłączyć mail. Szablony leżą w `mail/`, teksty w plikach językowych
(`PLG_HIKASHOP_CARTREMINDER_CART_*`, `PLG_HIKASHOP_CARTREMINDER_ORDER_*`).

W mailu o koszyku nie ma cen: liczy je kasa (strefa podatkowa, rabaty). W mailu o zamówieniu
jest jedna kwota do zapłaty, zapisana w zamówieniu.

## Linki w mailu

Linki obsługuje `com_ajax` (`onAjaxCartreminder`). Są podpisane HMAC-SHA256 z sekretem witryny.

- **Powrót do koszyka.** Koszyk gościa jest w HikaShop związany z sesją przeglądarki. Link
  przepina go na sesję odwiedzającego jako anonimowy, bez adresów, i przekierowuje do kasy.
- **Rezygnacja.** Dopisuje adres do listy rezygnacji z przypomnień o koszyku.
- **Tryb testowy.** Linki z maili testowych mają osobny podpis i niczego nie zmieniają.

## Dziennik

Tabela `#__hikashop_cartreminder`:

| Kolumna | Znaczenie |
|---|---|
| `type`, `ref_id` | `cart` (id koszyka), `order` (id zamówienia), `optout` |
| `status` | `pending` (rezerwacja albo nieudana próba), `sent`, `failed`, `optout` |
| `attempts`, `error` | liczba prób wysyłki i ostatni błąd |
| `clicked` | czas pierwszego kliknięcia w link do koszyka |
| `order_id`, `recovered`, `amount` | opłacone zamówienie, które przyszło po przypomnieniu |
| `test` | wpis z trybu testowego |

Wysyłka jest rezerwowana wpisem `pending` przed wysłaniem maila. Unikalny indeks
`(type, ref_id, test, email)` nie pozwala dwóm równoczesnym przebiegom crona wysłać tego
samego przypomnienia. Nieudana wysyłka jest ponawiana do 3 razy w odstępach godziny.

Odzysk liczy się dopiero przy opłaconym zamówieniu (statusy fakturowane z konfiguracji
HikaShop). Dla koszyka: zamówienie tego samego adresu złożone do 14 dni od maila. Kliknięcie
w link nie jest odzyskiem.

```sql
SELECT type,
       SUM(status = 'sent') wyslane,
       SUM(status = 'failed') nieudane,
       SUM(clicked > 0) klikniete,
       SUM(recovered > 0) odzyskane,
       ROUND(SUM(amount), 2) kwota
FROM `#__hikashop_cartreminder`
WHERE test = 0 AND type <> 'optout'
GROUP BY type;
```

## Changelog

### 1.1.0

- Dane do przelewu w mailu o zamówieniu z przelewem.
- Link do nowego hasła dla klienta z kontem.
- Zapis odzyskanego zamówienia: numer, czas, kwota.
- Rezerwacja wysyłki przed wysłaniem (bez duplikatów przy równoczesnych przebiegach crona).
- Limit 3 prób wysyłki z zapisem błędu.
- Ważność linku do koszyka.
- Konfigurowalny okres przechowywania dziennika i lista wykluczonych adresów.

### 1.0.0

- Przypomnienie o nieopłaconym zamówieniu i o porzuconym koszyku.
- Link przywracający koszyk, link rezygnacji, tryb testowy.
- Filtr zgody (AcyMailing) i pomijanie kategorii.

## Licencja

GNU General Public License version 2 or later.
