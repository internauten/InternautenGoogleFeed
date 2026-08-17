# Feed für Google Merchant

Dieses PrestaShop-Modul (getestet mit 9.1.4) stellt alle aktuellen Produkte des Shops als tokengeschützten Online-Feed für das Google Merchant Center bereit. Der Feed wird bei jedem Abruf live aus dem Katalog erzeugt.

## Modulordner

- `internautengooglefeed/`

## Installation

1. Den Modulordner `internautengooglefeed` in `modules/` der PrestaShop-Installation kopieren.
2. Im Backoffice unter `Module` nach `Internauten Google Feed` suchen und installieren.
3. In der Modulkonfiguration die angezeigte Feed-URL kopieren und im Google Merchant Center als geplanten Abruf hinterlegen.

## Funktionsweise

### Feed-URL und Token-Schutz

Bei der Installation wird automatisch ein Zufallstoken erzeugt. Die Feed-URL wird in der Modulkonfiguration angezeigt und enthält das Token als Parameter:

```
https://<shop>/module/internautengooglefeed/feed?token=<token>
```

- Nur Aufrufe mit dem korrekt hinterlegten Token erhalten den Feed.
- Ohne oder mit falschem Token antwortet die URL mit HTTP 403.
- Der Tokenvergleich erfolgt zeitkonstant (`hash_equals`).
- Über den Button `Neues Token erzeugen` kann jederzeit ein neues Token gesetzt werden. Danach muss die URL im Merchant Center aktualisiert werden.
- Der Feed wird mit `X-Robots-Tag: noindex, nofollow` ausgeliefert.

### Kategorien ausschliessen

Im Backoffice steht ein Kategorienbaum mit Checkboxen zur Verfügung. Produkte, die einer der markierten Kategorien zugeordnet sind, werden nicht in den Feed übertragen. Geprüft werden alle Kategoriezuordnungen eines Produkts, nicht nur die Standardkategorie.

### Prüffunktion

Der Button `Feed prüfen` erzeugt den Feed testweise und listet alle gefundenen Probleme mit Produkt-ID, Feed-ID und Produktname auf.

Als **Fehler** gemeldet (Produkt wird nicht ausgeliefert):

- Produktname fehlt
- Preis konnte nicht berechnet werden
- Preis ist 0 oder negativ
- kein Produktbild vorhanden

Als **Warnung** gemeldet (Produkt wird ausgeliefert):

- Beschreibung fehlt, es wird der Produktname verwendet
- Marke (Hersteller) fehlt
- weder GTIN/EAN noch MPN vorhanden, `identifier_exists` wird auf `no` gesetzt
- nicht auf Lager und nicht bestellbar, daher nicht im Feed
- Titel bzw. Beschreibung enthielt durchgehende Grossschreibung und wurde angepasst

Über das Feld `Limit für die Prüfung` kann die Anzahl geprüfter Items begrenzt werden, um bei grossen Katalogen Timeouts zu vermeiden. `0` prüft alle Produkte. Im Bericht werden maximal 500 Zeilen angezeigt.

### Korrektur von Grossschreibung

Google Merchant wertet durchgehend grossgeschriebene Wörter als Werbetext und kann Artikel deshalb ablehnen. Das Modul normalisiert solche Wörter in Titel und Beschreibung auf Grossschreibung am Wortanfang:

| Original                              | Im Feed                               |
| ------------------------------------- | ------------------------------------- |
| `SOMMER AKTION Hemd`                  | `Sommer Aktion Hemd`                  |
| `SOMMER-AKTION Damen`                 | `Sommer-Aktion Damen`                 |
| `ACHTUNG: Nur solange VORRAT reicht!` | `Achtung: Nur solange Vorrat reicht!` |
| `LED-LEUCHTE mit 5W`                  | `LED-Leuchte mit 5W`                  |

Unverändert bleiben:

- Wörter unterhalb der konfigurierten Mindestlänge, z. B. `XL`
- Wörter aus der Ausnahmeliste, z. B. `USB`, `HDMI`, `INOX`
- Wörter des Herstellernamens, sofern der Markenschutz aktiv ist (siehe unten)
- Zeichenfolgen mit Ziffern oder Sonderzeichen, z. B. Artikelnummern wie `AB-12/X`
- Satzzeichen, Klammern und Anführungszeichen

Die Korrektur wirkt ausschliesslich im Feed. Der Shop-Katalog wird nicht verändert.

#### Leerzeichen

Google beanstandet überflüssige Leerzeichen („Don't use extra white spaces"). Titel und Beschreibung werden deshalb normalisiert: Folgen aus Leerzeichen, Tabulatoren, Zeilenumbrüchen und Unicode-Leerzeichen (etwa `&nbsp;` aus dem HTML-Editor) werden zu einem einzelnen Leerzeichen zusammengefasst.

Bindestriche bleiben erhalten. Sie sind laut Googles Spezifikation das empfohlene Format zur Abgrenzung von Varianten, siehe deren Beispiel `Google Organic Cotton Men's T-Shirt - Blue - M`. Kombinationen werden im Modul nach demselben Muster angehängt, z. B. `Hemd - Blau, M`.

#### Markenschutz

Ist `Herstellernamen von der Korrektur ausnehmen` aktiv, wird der Herstellername des Produkts (Feld _Hersteller_, `ps_manufacturer.name`) an Nicht-Buchstaben zerlegt und jedes Teilwort vor der Korrektur geschützt. So bleibt bei Hersteller `Hugo Boss` der Titel `HUGO BOSS PARFUM` als `HUGO BOSS Parfum` erhalten.

Der Vergleich erfolgt wortweise über den gesamten Text, nicht positionsbezogen. Enthält ein Herstellername einen Gattungsbegriff, wird dieser dadurch überall im Titel geschützt:

| Hersteller     | Titel                  | Schutz aktiv           | Schutz aus             |
| -------------- | ---------------------- | ---------------------- | ---------------------- |
| `Gutschein AG` | `GUTSCHEIN 20 Franken` | `GUTSCHEIN 20 Franken` | `Gutschein 20 Franken` |
| `Wein & Co`    | `WEIN aus Italien`     | `WEIN aus Italien`     | `Wein aus Italien`     |
| `Hugo Boss`    | `HUGO BOSS PARFUM`     | `HUGO BOSS Parfum`     | `Hugo Boss Parfum`     |

In solchen Fällen den Markenschutz abschalten. Die Ausnahmeliste bleibt davon unberührt und greift weiterhin — einzelne Marken können dort gezielt eingetragen werden.

## Konfiguration im Backoffice

| Einstellung                                 | Standard     | Beschreibung                                                                                                        |
| ------------------------------------------- | ------------ | ------------------------------------------------------------------------------------------------------------------- |
| Feed-Token                                  | zufällig     | Token für den Zugriff auf die Feed-URL. Erlaubt sind Buchstaben, Zahlen, `-` und `_` (8 bis 64 Zeichen).            |
| Ausgeschlossene Kategorien                  | leer         | Produkte dieser Kategorien werden nicht übertragen.                                                                 |
| Kombinationen einzeln ausgeben              | Ja           | Jede Kombination wird als eigenes Item mit `item_group_id` ausgegeben.                                              |
| Nicht lieferbare Produkte mitliefern        | Nein         | Wenn aktiv, werden ausverkaufte Produkte mit `availability = out_of_stock` übertragen.                              |
| Bildformat                                  | Originalbild | Bildgrösse, die im Feed verlinkt wird.                                                                              |
| Grossschreibung korrigieren                 | Ja           | Korrigiert durchgehende Grossschreibung in Titel und Beschreibung.                                                  |
| Mindestlänge für die Korrektur              | 4            | Nur Wörter ab dieser Buchstabenzahl werden korrigiert (erlaubt: 2 bis 30).                                          |
| Abkürzungen in Grossschreibung behalten     | siehe unten  | Wörter, die nie umgeschrieben werden. Trennung per Komma oder Leerzeichen.                                          |
| Herstellernamen von der Korrektur ausnehmen | Ja           | Schützt Wörter des Herstellernamens vor der Korrektur. Abschalten, wenn Herstellernamen Gattungsbegriffe enthalten. |
| Präfix für Artikelnummern                   | leer         | Wird der Feed-ID vorangestellt, z. B. `shop-`.                                                                      |
| Limit für die Prüfung                       | 0            | Maximale Anzahl geprüfter Items. `0` bedeutet: alle Produkte.                                                       |

Vorbelegung der Ausnahmeliste: `USB, HDMI, LED, LCD, OLED, GPS, WLAN, WIFI, USV, ABS, PVC, INOX, XXL, XXXL, MwSt`

## Welche Produkte enthält der Feed?

Berücksichtigt werden Produkte, die

- im aktuellen Shop aktiv sind,
- die Sichtbarkeit `both` oder `catalog` haben,
- keiner ausgeschlossenen Kategorie zugeordnet sind,
- einen Preis grösser 0, einen Namen und mindestens ein Bild besitzen.

Die Verfügbarkeit wird wie folgt abgeleitet:

- Lagerbestand grösser 0 → `in_stock`
- Bestellung bei Nichtverfügbarkeit erlaubt → `backorder`
- sonst → nicht im Feed, ausser `Nicht lieferbare Produkte mitliefern` ist aktiv (`out_of_stock`)

## Ausgegebene Feed-Felder

`id`, `title`, `description`, `link`, `image_link`, `additional_image_link` (bis zu 10), `availability`, `price`, `sale_price`, `condition`, `identifier_exists`, `gtin`, `mpn`, `brand`, `product_type`, `item_group_id`, `shipping_weight`

Hinweise:

- Preise werden inklusive Steuer in der Shop-Währung ausgegeben. Liegt eine Reduktion vor, enthält `price` den regulären und `sale_price` den reduzierten Preis.
- `gtin` wird aus `ean13`, `isbn` oder `upc` befüllt, bei Kombinationen zuerst aus den Kombinationsdaten.
- `product_type` enthält den Kategoriepfad der Standardkategorie, z. B. `Kleidung > Herren > Hemden`.
- `item_group_id` wird nur bei Kombinationen gesetzt.

## Aufbau des Moduls

| Datei                                      | Zweck                                                 |
| ------------------------------------------ | ----------------------------------------------------- |
| `internautengooglefeed.php`                | Modulklasse, Backoffice-Konfiguration und Prüfbericht |
| `classes/InternautenGoogleFeedBuilder.php` | Produktabfrage, Validierung und XML-Erzeugung         |
| `controllers/front/feed.php`               | Feed-Endpoint mit Token-Prüfung                       |
| `views/templates/admin/report.tpl`         | Darstellung des Prüfberichts                          |

Die Modul-Metadaten (`config.xml` bzw. `config_<iso>.xml`) werden von PrestaShop selbst aus der Modulklasse erzeugt, sobald die Modulliste im Backoffice aufgerufen wird. Sie sind deshalb per `.gitignore` ausgeschlossen und müssen nicht gepflegt werden. Änderungen an Name, Version, Beschreibung oder Tab erfolgen ausschliesslich in `internautengooglefeed.php`; PrestaShop schreibt die Datei neu, sobald sie älter als die Modulklasse ist.

## Ursprüngliche Anforderung

Hole alle aktuellen Produkte aus dem Shop. Prüfe auf Gültigkeit für Google Merchant und liefere sie als Feed aus.

Der Schutz soll über ein im BO konfigurierbares Token erfolgen. Nur wenn dies stimmt, sollen die Produkte geliefert werden.

Im BO soll zusätzlich eine Excludeliste von Kategorien pflegbar sein. Wenn eine Kategorie da drinnen ist, werden diese Daten nicht übertragen.

Zudem soll im BO auch eine Funktion hinterlegt werden, die das Feed-Ergebnis prüft und bei Problemen diese auflistet (z. B. fehlender Preis etc.).
