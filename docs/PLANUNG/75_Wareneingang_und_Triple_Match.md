# Spec 75 · Wareneingang & Triple Match — Bestellung ⇄ Lieferschein ⇄ Rechnung

Stand 2026-10-08 · Branch `feat/wareneingang-triple-match`

> **Tracking:** Office Dev-Package 23, Features-Board.
> **Status:** **75a + 75b gebaut** (Lieferscheine, Rechnungen, Abgleich, Spec-61-Rechte-Basis; PR offen) · 75c (Editor-Kurzwege, Freigabe-Box, Bestand, ) offen.
> Bezug: [61 · Rollen und Rechte](61_Rollen_und_Rechte.md) (Basis hier gebaut), [66/67 · Lager](66_Lager_und_Inventur_Stufe1.md), [71 · Bestellrunde Stufe 2](71_Bestellrunde_Stufe2.md).

## Anlass

Wunsch Dominique (08.10.): Unter Einkauf ein eigener Reiter, in dem alle Lieferungen gebündelt
stehen — Lieferscheine erfassen und buchen — plus ein sauberes Triple Match aus Bestellung,
Lieferschein und Rechnung. Heute ist das verstreut.

## Ist-Stand (was es schon gibt)

Wareneingang und Rechnung leben heute **als Felder an der Bestellzeile**, nicht als eigene Belege:

| Ebene | Felder | Pflege |
|---|---|---|
| Bestellzeile WE | `received_qty_packs`, `received_at`, `received_note` | `OrderService::updateReceiptLine`, `completeReceipt` |
| Bestellzeile RE | `invoice_qty_packs`, `invoice_pack_price`, `invoice_checked_at`, `invoice_note` | `updateInvoiceLine`, `completeInvoiceFromReceipt` |
| Bestellzeile Reklamation | `claim_status`, `claim_qty_packs`, `credit_expected_net`, `claim_note` | `updateClaimLine` |
| Bestellkopf RE | `invoice_number`, `invoice_date`, `invoice_note`, `payment_status`, `invoice_paid_at` | `updateInvoiceHeader`, `updatePayment` |
| Folgebuchungen | Lagerzugang (`InventoryService::syncReceiptLine`, idempotent per `source_hash`), Kontingent, Einkaufsjournal | hängen alle an `received_qty_packs` |

Der „Drei-Abgleich" im Editor stellt bestellt / geliefert / berechnet je Zeile nebeneinander.

### Warum das für ein Triple Match nicht reicht

1. **Ein Lieferschein ist kein eigener Beleg.** Keine Lieferscheinnummer, kein Lieferdatum je
   Lieferung, kein Beleg-Foto. Zwei Teillieferungen auf eine Bestellung überschreiben sich in
   `received_qty_packs`.
2. **Eine Rechnung ist kein eigener Beleg.** Die Rechnungsnummer steht am Bestellkopf — eine
   **Sammelrechnung** (Hanos/Chefs Culinar rechnen oft wochenweise über mehrere Lieferungen) lässt
   sich nicht abbilden; dieselbe Nummer müsste an jede Bestellung kopiert werden.
3. **Nachlieferung bricht ab:** `guardReceiptLine` erlaubt WE nur bei `gesendet/bestätigt` — ist die
   Bestellung `geliefert`, kommt der Rest-Lieferschein nicht mehr dran.
4. **Ware ohne Bestellung** (Fahrer bringt Zusatzartikel, Ersatzartikel) hat keinen Platz im
   Wareneingang — nur über Spec 67 „Zugang von Hand" im Lager, also ohne Bezug zu Lieferschein
   und Rechnung.
5. **Keine Liste aller Lieferungen.** Man muss jede Bestellung einzeln öffnen.

## Ziel

Lieferschein und Rechnung werden **eigene Belege** mit Positionen, die auf Bestellzeilen zeigen.
Die Bestellzeile bleibt der Anker; ihre heutigen WE-/RE-Felder werden zur **abgeleiteten Summe**
aus den Belegen. Dadurch laufen Lager, Kontingent und Einkaufsjournal unverändert weiter.

```
Bestellung (1) ──< Bestellzeile (n)
                      ▲            ▲
Lieferschein (n) ──< LS-Zeile ─────┘│        (0..1 Bestellzeile je LS-Zeile: „ohne Bestellung" erlaubt)
                      ▲             │
Rechnung (n) ─────< RE-Zeile ───────┘        (zeigt auf LS-Zeile und/oder Bestellzeile)
```

- 1 Bestellung → n Lieferscheine (Teillieferung, Nachlieferung)
- 1 Lieferschein → n Bestellungen desselben Lieferanten (Fahrer bringt zwei Liefertage auf einmal)
- 1 Rechnung → n Lieferscheine (Sammelrechnung) bzw. n Bestellungen

**F2 entschieden: n:m von Anfang an.** Ob Sammelbelege bei den Lieferanten vorkommen, ist offen —
das Modell trägt beides ohne Mehraufwand, weil der Kopf nur am Lieferanten hängt und jede Position
einzeln auf ihre Bestellzeile zeigt. Erfassung belegt deshalb immer **alle offenen Bestellzeilen
des Lieferanten** vor (gruppiert nach Bestellung), nicht nur eine Bestellung.

## Datenmodell (neu, eigene Tabellen)

**`foodalchemist_delivery_notes`** — Lieferschein-Kopf
`team_id`, `supplier_id`, `delivery_note_number`, `delivered_on` (Datum), `received_by` (User),
`status` (`entwurf` → `gebucht` → `storniert`), `inventory_location_id` (Ziel-Lager, Standard =
Stammplatz-Logik Spec 67), `note`, `booked_at`, `attachment` (Foto/PDF, optional — siehe Frage F5).
Eindeutig: (`team_id`, `supplier_id`, `delivery_note_number`).

**`foodalchemist_delivery_note_lines`** — Lieferschein-Position
`delivery_note_id`, `order_line_id` (nullable = ohne Bestellung), `supplier_item_id`, `gp_id`,
`qty_packs` (geliefert), `pack_price_snapshot` (aus Bestellung), `abweichung_grund`
(`fehlt`, `zu_wenig`, `zu_viel`, `ersatzartikel`, `beschaedigt`, `temperatur`, `mhd_kurz`, `sonstiges`),
`note`. HACCP-Felder (Temperatur, MHD, Charge, Verpackung) kommen in eine eigene Spec (F4) — die
LS-Position ist so geschnitten, dass sie später ergänzt werden kann.

**`foodalchemist_supplier_invoices`** — Rechnungs-Kopf
`team_id`, `supplier_id`, `invoice_number`, `invoice_date`, `due_date` (aus Zahlungsziel),
`total_net` (laut Beleg, zur Summenkontrolle), `status` (`erfasst` → `geprüft` → `freigegeben`
→ `bezahlt`, Nebenstatus `strittig`), `paid_at`, `note`, `attachment`.
Eindeutig: (`team_id`, `supplier_id`, `invoice_number`).

**`foodalchemist_supplier_invoice_lines`** — Rechnungs-Position
`invoice_id`, `delivery_note_line_id` (nullable), `order_line_id` (nullable), `supplier_item_id`,
`qty_packs`, `pack_price`, `line_net`, `note`. Zusätzlich Positionen ohne Artikel
(Fracht, Pfand, Mindermengenzuschlag) mit `art` = `fracht|pfand|zuschlag|rabatt`.

**Reklamation:** bleibt vorerst an der Bestellzeile (`claim_*`), wird aber aus dem Abgleich heraus
gesetzt. Gutschrift als eigener Beleg = später (siehe Abgrenzung).

## Ableitung auf die Bestellzeile (Kompatibilität)

Ein **gebuchter** Lieferschein schreibt je Bestellzeile seine Menge als **Zuwachs** auf
`received_qty_packs` (Storno nimmt ihn zurück) — Zuwachs statt Summe, weil bis 75c auch der Editor direkt Mengen setzt — über den vorhandenen Weg `OrderService::updateReceiptLine`. Damit bleiben
Lagerzugang (idempotent, Differenz-Buchung), Kontingentverbrauch und Einkaufsjournal unverändert
und es gibt **keinen zweiten Buchungsweg**. Analog schreibt eine geprüfte Rechnung Summe Menge und
gewichteten Preis in `invoice_qty_packs` / `invoice_pack_price`.

Folge (F7 entschieden): Die heutigen Direkt-Eingaben im Bestell-Editor (Reiter Wareneingang/Rechnung)
bleiben als **Kurzwege, die intern einen Lieferschein bzw. eine Rechnung anlegen** — sonst laufen zwei Quellen
auseinander (Memory „Halb umgestellt ist schlechter"). Umstellung erst nach Merge von
`feat/einkauf-runde2`, als minimaler Einhängepunkt.

**Lagerzugang ohne Bestellung:** LS-Zeile ohne `order_line_id` bucht direkt über
`InventoryService` (neuer, eigener Hash `delivery_note_line:<id>`), Quelle `wareneingang`.

**Nachlieferung:** `guardReceiptLine` bleibt unverändert (abgestimmt mit dem Besitzer von
OrderService). Eine Lieferung nach `geliefert` läuft als Lieferschein auf die **Nachlieferungs-Bestellung**
aus `createBackorderFromReceipt`, nie auf die abgeschlossene Bestellung. Der Lieferschein-Dialog
bietet bei offener Fehlmenge „Nachlieferung anlegen" an und belegt die neue Bestellzeile vor.

## Triple Match — Regeln

Je Bestellzeile ein Abgleich-Status aus drei Mengen und zwei Preisen:

| Status | Bedingung |
|---|---|
| **passt** | bestellt = geliefert = berechnet (Menge) und Rechnungspreis = Bestellpreis, innerhalb Toleranz |
| **Mengenabweichung LS** | geliefert ≠ bestellt |
| **Mengenabweichung RE** | berechnet ≠ geliefert |
| **Preisabweichung** | Rechnungspreis ≠ Bestellpreis über Toleranz |
| **nicht geliefert, berechnet** | RE-Menge > 0, keine LS-Menge — höchste Priorität |
| **geliefert, nicht berechnet** | LS gebucht, nach X Tagen keine Rechnung |
| **ohne Bestellung** | LS-/RE-Zeile ohne Bestellzeile |
| **offen** | noch kein LS bzw. keine RE |

Toleranzen (Frage F3): Vorschlag Menge exakt (Gebinde), Preis ±0,5 % **oder** ±0,05 € je Gebinde,
je Team einstellbar. Auf Rechnungsebene zusätzlich: Summe der Positionen + Nebenkosten = `total_net`
laut Beleg (Erfassungskontrolle).

**Freigabe zur Zahlung:** Eine Rechnung ist freigebbar, wenn alle Zeilen `passt` sind oder jede
Abweichung begründet bzw. als Reklamation markiert ist. Freigeben ist ein bewusster Klick, kein
Automatismus.

## UI

Neue Seite **Einkauf → Wareneingang** (eigene Route `/wareneingang`, eigene Livewire-Komponente
`Wareneingang\Index`, eigener Sidebar-Eintrag zwischen Bestellungen und Bestellvorlagen) — F1 entschieden.

Reiter:

1. **Lieferungen heute / erwartet** — alle gesendeten/bestätigten Bestellungen nach Liefertag
   gruppiert, je Lieferant: „Lieferschein erfassen". Überfällige oben.
2. **Lieferscheine** — Liste aller Lieferscheine (Filter Lieferant, Zeitraum, Status, mit
   Abweichung). Erfassen: Lieferant wählen → offene Bestellzeilen des Lieferanten vorbelegt (über
   mehrere Bestellungen) → Mengen korrigieren, Grund wählen, Zusatzartikel ergänzen → **Buchen**.
   „Alles wie bestellt" als Ein-Klick.
3. **Rechnungen** — Rechnung erfassen: Kopf (Nr., Datum, Summe laut Beleg), Lieferscheine
   zuordnen → Positionen aus LS vorbelegt, Preise aus Bestellung → Abweichungen überschreiben,
   Nebenkosten ergänzen.
4. **Abgleich** — Triple-Match-Tabelle über alle offenen Fälle, sortiert nach Euro-Wirkung:
   bestellt | geliefert | berechnet | Δ Menge | Δ Preis | Δ € | Status | Aktion (Reklamation,
   Nachlieferung, begründen). Kopfzahlen: offene Lieferungen, Rechnungen in Prüfung, Summe
   Abweichungen €, erwartete Gutschriften.

Aus der Bestellung (Editor) führt ein Link je Lieferschein/Rechnung in die neue Seite — Einhängepunkt
erst nach Merge `feat/einkauf-runde2`.

## Services & MCP (Lockstep)

- `WareneingangService` — Lieferschein anlegen/vorbelegen/buchen/stornieren, Ableitung auf
  Bestellzeile, Zugang ohne Bestellung.
- `LieferantenRechnungService` — Rechnung anlegen, aus LS vorbelegen, prüfen, freigeben, bezahlt.
- `TripleMatchService` — reine Lese-Auswertung (Status je Zeile, Kopfzahlen), von UI, Dokument
  und MCP gemeinsam genutzt (ein Rechenweg).

MCP-Tools: `delivery_notes.GET/POST/PUT/BOOK/STORNO`, `supplier_invoices.GET/POST/PUT/APPROVE`,
`triple_match.GET`. Die bestehenden `orders.RECEIPT` / `orders.UPDATE_INVOICE` bleiben und laufen
intern über die neuen Belege (gleicher Umstellungszeitpunkt wie der Editor).

## Migration Bestand

Migrationsnummern ab `2026_10_09_*` (`2026_10_08_170000–190000` belegt durch `feat/einkauf-runde2`).
MCP-Registrierung im ServiceProvider **nicht** direkt nach `EigenproduktionEntnahmeTool` (dort hängt die andere Session an).


Je Bestellung mit `received_qty_packs` ein Lieferschein (`delivery_note_number` = „Altbestand
ord-<id>", Datum = `received_at`), je Bestellung mit `invoice_number` eine Rechnung. Idempotent,
`--dry-run`. Lagerbewegungen werden **nicht** neu gebucht (Hash bleibt an der Bestellzeile).

## Stufen

- **75a** Belege + Services + Seite Reiter 1–2 (Lieferscheine buchen) + MCP + Tests. Eigene Dateien, keine Berührung von OrderService/Editor/Index.
- **75b** Rechnungen + Abgleich (Reiter 3–4) + Toleranzen + Freigabe.
- **75c** nach Merge `feat/einkauf-runde2` — Bestell-Editor (Hinweis Dominique 08.10., Screenshot Reiter Kopf/Wareneingang/Rechnung + Box *Freigabe*):
  - Reiter **Wareneingang** und **Rechnung** im Editor bleiben als Kurzweg, schreiben aber über `WareneingangService` bzw. den Rechnungs-Service (legen Beleg an) — eine Datenquelle.
  - Box **Freigabe** an der Bestellung: `freigegeben`/`abgelehnt` setzen nur mit Rolle **Freigeben** (`FaRechte::pruefe` in `OrderService::updateApproval`), Anfrage stellen ab Kuratieren. Senden/Stornieren laut Spec 61 §3 ebenfalls Freigeben.
  - Rest von 75c: Editor-Kurzwege auf die Belege umhängen, Bestand migrieren, Links Editor ↔ Wareneingang, Doku `einkauf.md`.

## Bewusst nicht in Spec 75

Beleg-/PDF-Erkennung (OCR, Rechnungsimport, E-Rechnung/XRechnung/ZUGFeRD), Gutschrift als eigener
Beleg, Kreditorenbuchhaltung/DATEV-Export, mehrstufige Rechnungsfreigabe, Pfandkonto.

## Fragen an Dominique

Entschieden 08.10.: **F1** eigene Seite · **F2** unklar → n:m von Anfang an · **F4** HACCP eigene
Spec · **F7** Kurzweg im Editor behalten.

Offen:

- ~~**F1 Ort:** Eigene Seite *Einkauf → Wareneingang* (Vorschlag — hält die Bestellliste schlank und
  kollidiert nicht mit der laufenden Arbeit) oder als Sicht „Lieferungen" in *Bestellungen*?~~
- ~~**F2 Sammelrechnung / Sammellieferschein:** Kommt das bei euren Lieferanten real vor (Hanos,
  Chefs Culinar, Kluth)? Davon hängt ab, ob die n:m-Zuordnung gleich in 75a mit muss.~~
- ~~**F3 Toleranzen:** Preis ±0,5 % bzw. ±0,05 €/Gebinde, Menge exakt — passt das?~~ **Entschieden: Vorschlag, je Team einstellbar.**
- ~~**F4 HACCP-Wareneingangskontrolle:** Temperatur, MHD, Charge, Verpackung je Position mit erfassen
  (Pflicht in Kühlkette) — jetzt oder eigene Spec?~~
- ~~**F5 Beleg-Foto:** Lieferschein/Rechnung als Foto/PDF anhängen in 75a (ohne Erkennung)?~~ **Entschieden: ja, in 75a** (Core `ContextFileService`, JPG/PNG/HEIC/PDF ≤ 15 MB).
- ~~**F6 Wer bucht:** Küche erfasst Lieferschein, Büro prüft Rechnung — braucht es dafür Rollen
  (Spec 61) oder reicht v1 ohne Rechte-Trennung?~~ **Entschieden: gleich mit Rollen** — Spec-61-Basis wird in 75a mitgebaut — Rollen aus den Plattform-Team-Einstellungen, siehe Architektur-Entscheidung. Lieferschein erfassen/buchen/stornieren = Kuratieren, Rechnung freigeben (75b) = Freigeben.
- ~~**F7 Direkt-Eingabe im Bestell-Editor:** behalten als Kurzweg (legt intern Belege an) oder
  ganz in die neue Seite verlegen?~~

## Umsetzung 75a (2026-10-08)

- Tabellen `foodalchemist_delivery_notes` + `_lines` (Migration `2026_10_09_100000`), `foodalchemist_team_member_flags` (`2026_10_09_100100`, Freigabe-Häkchen).
- `WareneingangService`: `erwarteteLieferungen`, `vorbelegen`, `speichern`, `buchen`, `stornieren`, `loeschen`, `nachlieferung`, Beleg-Anhang über Core `ContextFileService`.
  Buchen = Zuwachs über `OrderService::updateReceiptLine` (Lager/Kontingent/Journal unverändert). Abschließen setzt nicht erfasste Positionen auf 0, bevor `setStatus(Delivered)` sie mit der Bestellmenge vorbelegen würde.
- `FaRolle` (abgeleitete Stufe), `FaRechte` (`rolle`, `darf`, `pruefe`, `mitglieder`, `setzeFreigabe`), `FaRechtFehltException` — siehe Architektur-Entscheidung.
- UI: Einkauf → Wareneingang (`Wareneingang\Index`, Reiter Erwartet / Lieferscheine), Einstellungen → Zugriffsrechte.
- MCP: `delivery_notes.GET/POST/PUT/BOOK/STORNO/BACKORDER`, `team_roles.GET/PUT` (Häkchen; FORBIDDEN bei fehlender Rolle).
- Tests: `tests/Feature/WareneingangTest.php` (11).

Freigabe-Box im Bestell-Editor: entschieden, siehe Architektur-Entscheidung unten.

## Umsetzung 75b (2026-10-08)

- Tabellen `foodalchemist_supplier_invoices` + `_lines`, Team-Einstellungen `tm_toleranz_preis_pct`, `tm_toleranz_preis_eur` (Migration `2026_10_09_100200`, eigene kurze Indexnamen, auf MySQL hoch und runter geprüft).
- `TripleMatchService` (eine Rechenstelle): Befund je Bestellzeile in zwei Teilen — Lieferung (`ok|zu_wenig|zu_viel|offen`) und Rechnung (`ok|menge|preis|nicht_geliefert|nicht_berechnet|offen`); Unterlieferung mit passender Rechnung = ok. Toleranz ±pct % oder ±€/Gebinde, das Großzügigere gilt.
- `LieferantenRechnungService`: vorbelegen aus nicht abgerechneten Lieferschein-Positionen, speichern, begründen, freigeben (Summe = Beleg + jede Abweichung begründet), bezahlt, strittig, stornieren (Prüfwerte neu aus den verbleibenden Rechnungen), reklamieren (über `OrderService::updateClaimLine`).
- Freigabe schreibt Menge + gewichteten Preis per `OrderService::updateInvoiceLine` und den Kopf per `updateInvoiceHeader`; bezahlt per `updatePayment`.
- UI: Reiter *Rechnungen* und *Abgleich* (eigene Komponenten `Wareneingang\Rechnungen`, `Wareneingang\Abgleich`); Toleranz im Abgleich (FA-Admin).
- MCP: `supplier_invoices.GET/POST/PUT/APPROVE/PAY/STORNO`, `triple_match.GET` (read_only) + `triple_match.PUT` (Toleranz).
- Tests: `tests/Feature/TripleMatchTest.php` (7), Fixture geteilt in `tests/Support/SeedsWareneingang.php`.

## Architektur-Entscheidung Rechte (mit Dominique abgestimmt 2026-10-08)

- **Rollen kommen aus den Team-Einstellungen der Plattform** (`team_user.role`, Core `StandardRole`: owner/admin/member/viewer) — dort werden Bearbeiten/Lesen ohnehin gepflegt; das FA führt **keine zweite Rollenliste**. Ein früherer Zwischenstand mit eigener FA-Rollentabelle und Team-Standard ist vor dem ersten Deploy wieder entfernt worden.
- **Abbildung in `FaRechte`** (eine Prüfstelle, Service-Schicht, gilt für UI und MCP): Plattform-Admin/owner/admin → FA-Admin · member → Kuratieren · member + Häkchen „darf Rechnungen freigeben" → Freigeben · viewer/kein Mitglied → Lesen. Höchste Rolle über Team + Eltern-Teams gewinnt. KI-Benutzer höchstens Kuratieren.
- **Einziges FA-Zusatzrecht:** Häkchen „darf Rechnungen freigeben" (Tabelle `foodalchemist_team_member_flags`, Einstellungen → Zugriffsrechte, MCP `team_roles.PUT`), damit das Büro freigeben kann, ohne Admin zu werden (Entscheidung Dominique). Admins dürfen immer.
- **Core nur lesen:** keine Änderung in core/organization. Spec 65 (`MitBearbeitungssperre::istTeamAdmin`) liest dieselbe Core-Rolle — beide Wege kommen für Inhaber/Admin zum selben Ergebnis.
- **Modulweit gedacht** (Spec 61 §5), durchgesetzt vorerst nur im Wareneingang; weitere Bereiche hängen sich an `FaRechte` an, wenn sie umgestellt werden. Spec 61 §4 (eigene FA-Rollentabelle) ist damit überholt.
- **Freigabe-Box im Bestell-Editor (75c):** wird zur echten Übergabe — Kuratieren „Freigabe anfragen", Freigeben „Freigeben & senden" / „Ablehnen" (Entscheidung Dominique).
