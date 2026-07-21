# Bestellwesen — mini-WaWi Bestellassistent mit Bestellschienen pro Lieferant (N-Track)

> **ROADMAP-Bezug:** N-Track — die von [R9 §7](14_Lieferanten_Management_R9.md) **bewusst ausgeklammerte** Bestell-Ebene („kein Bestellen/Wareneingang/Rechnungsprüfung"). Dominique-Wunsch 2026-07-21.
> **Ziel:** Aus dem geplanten Bedarf echte, **versendbare Bestellungen pro Lieferant** formen — in **ganzen Gebinden**, mit Artikelnummer und Gebinde-Preis, gesammelt in einer persistenten **Bestellschiene** je Lieferant. Das Planungsblatt rechnet den Bedarf (read-only) — das Bestellwesen macht daraus einen **dauerhaften Beleg** (stateful).
> **Scope-Grenze ✅ ENTSCHIEDEN (2026-07-21):** **OHNE Bestand** — Bestellassistent, kein Lager/Wareneingang/Netting/Rechnungsprüfung/Inventur. Bestellmenge = geplanter Bedarf in Gebinde (kein Abzug gegen Lager). Bestand = späterer Ausbau (S4, Nicht-Ziel v1).
> **Reifegrad: ✅ S0–S3 KOMPLETT & ★ LIVE AUF DEMO 2026-07-21** (E1–E11). S0 `0d78bd2` (Gebinde-Bestellzeile) · S1 `3daf87d` (Bestell-Logistik) · S2 `bbc73e3`+`49f6c16` (Bestellschiene Engine+MCP+UI) · S3 `ef9a5fc` (Versand/Export PDF/CSV/mailto + `orders.UPDATE_LINE`). `OrderServiceTest` 14/14. **Deploy:** `update.sh` gefahren (FA `dc31c6d`), Migrationen `000002`+`000003` auf demo-MySQL durch, Schema verifiziert (`orders`/`order_lines`/`suppliers.delivery_days` da). **Offen nur:** manuelle UI-Klickstrecke (Dominique) + S4 Bestand (bewusstes Nicht-Ziel).

---

## 0. Code-Kartierung (verifiziert 2026-07-21)

**Bedarfs-Explosion ist da (reuse):** `PlanungsblattService` —
- `bestellvorschlag(team, ziel)` `:78`, `einkaufsliste(team, ziele[])` `:97` → GP-Bedarf, gruppiert nach Lead-Lieferant via `gruppiereNachLieferant()` `:431`. Liefert je Position schon `lead_artikel`, **`lead_artikel_nr`** (`:452`, wird heute nicht angezeigt) und `ausweich`. **ABER: Menge in kg + EK = `Gramm × Lead-€/g`** → theoretischer Anbruch-Preis, **kein ganzes Gebinde**. Genau hier setzt S0 an.
- `explodiere()` `:257` = Rezeptbaum → GP-Gramm, Diamond-sicher, VK linear / Basis auf ganze Ansätze. **Die Rechen-Wahrheit bleibt hier — Spec 17 erfindet sie NICHT neu.**

**Lieferant/Artikel-Wahl ist da (reuse):** `LeadLaService::rangliste(gp,team)` `:47` / `effektiverLead` `:105` (Pin gewinnt) → Lead-LA + Ausweichquellen fallen ab. Identisch zur Bestellvorschlag-Zuordnung → Schiene ist konsistent zum Blatt.

**Gebinde/Artikel/Preis ist da (reuse):** `FoodAlchemistSupplierItem` (Migration `…_000006`) — `article_number`, `packaging_unit` („Schlauch"/„Karton"), `ordering_unit`, `qty_ordering_per_packaging`, **`qty`** (Gebinde-Inhalt; `NULL` = GL-03-A-2-Preisfalle), `unit_code` (kg/l/Stk), `ean_packaging`/`ean_ordering`. Gebinde-Preis in `foodalchemist_prices.price` (+ `price_partial` = Anbruchpreis). `PriceService::preisProGramm(item,price)` `:264` = `price ÷ qty` → die **Umkehrung** `packs = ceil(needG ÷ (qty·1000))` ist trivial daraus. **Alle Daten für die Gebinde-Rechnung liegen — kein Import nötig.**

**Beschaffungs-Konditionen sind da (reuse, aus R9/Spec 14, verifiziert):** `suppliers.min_order_value` (Mindestbestellwert netto), `free_shipping_threshold` (Frei-Haus-Grenze), **`email_order`** (Bestellweg E-Mail), `payment_term_days`, `status`. → **MOQ-/Frei-Haus-Ampel + Bestellweg brauchen KEIN neues Schema.**

**Write-/MCP-/Signal-/Druck-Muster (reuse):** Write-Tool-Vorlage `FavoritesPutTool` + `suppliers.PUT` (`visibleToTeam` + `isOwnedBy`-Gate, D1). `SignalService::erzeuge` + Detektor-Muster `veraltetePreise` = Vorlage für „Bestellschluss heute"-Signal. Druck-Route `dokumente/blatt.blade.php` (`typ=bestellung`) = Vorlage fürs Bestell-PDF.

**ALLES NEU (greenfield — kein order-Scaffolding, verifiziert: keine `*order*`-Migration):** `foodalchemist_orders` + `foodalchemist_order_lines`, `OrderService`, `GebindeRechner` (die S0-Primitive), Bestellschienen-Review-UI, Snapshot-Logik, `suppliers.delivery_days`/`order_cutoff`, `orders.*` MCP-Tools, Export (PDF/CSV/mailto).

---

## 1. Vorhandener Kern (Startpunkt, kein Neubau)
Bedarf→Lieferant-Gruppierung (`bestellvorschlag`/`einkaufsliste`) · Lead-LA-Kette (`LeadLaService`) · Gebinde-Felder am LA · R9-Konditionen auf `suppliers`. → Spec 17 setzt die **stateful Bestell-Ebene obendrauf** und macht aus der read-only-Berechnung einen versendbaren, historischen Beleg.

## 2. Festgezurrte Entscheidungen (2026-07-21)

| # | Frage | Entscheid | Begründung |
|---|---|---|---|
| E1 | Was ist eine „Bestellschiene"? | **Ein persistenter Draft-Order pro (team, supplier).** Höchstens EIN offener `draft` je Lieferant sammelt den Bedarf; „Absenden" schließt ihn (`sent`), der nächste Bedarf öffnet einen neuen. Keine separate „Schienen"-Tabelle. | Minimal; die Schiene = Zustand `draft` des Orders, kein Extra-Konzept. |
| E2 | Live-FK oder Snapshot? | **Order-Line snapshottet** `article_number`, `designation`, `packaging_unit`, `pack_qty`, `unit_code`, `pack_price` zum Bestellzeitpunkt (+ `supplier_item_id`/`gp_id` als Herkunfts-Ref). | Eine Bestellung ist ein **historischer Beleg**; Preise/Artikel driften — ein versendeter Order darf sich nachträglich nicht ändern. |
| E3 | Bestellmenge-Rechnung | **Ganze Gebinde:** `qty_packs = ceil(needG ÷ (qty·1000))` für kg/l (Dichte 1.0, wie im Rest des Codes). **Gerundet wird IMMER auf dem je Schiene aggregierten GP-Bedarf, nie pro Quell-Zeile** — sonst Doppel-Aufrundung (1,2 kg + 0,7 kg bei 2-kg-Gebinde = 1 Schlauch, nicht 2). **Stk-LAs:** Gramm-Bedarf → Stück nur über Stückgewicht (`foodalchemist_gp_count_unit_defaults`, wie `RecipeRecomputeService:860`); ohne Stückgewicht wie `qty=NULL` behandeln. `needed_base_qty` (Original-Bedarf) mitführen → **Überkauf sichtbar**. `qty=NULL` → Zeile in Grundeinheit + **Warnung**, nie still schätzen. | Man bestellt keinen Anbruch; Überkauf ehrlich zeigen; Preisfalle nicht verstecken; Gramm-÷-Stück-Unsinn verhindern. |
| E4 | Bestand? | **OHNE Bestand** (Dominique 2026-07-21). Kein Netting gegen Lager, kein Wareneingang. Status `delivered` = optionaler manueller Haken **ohne** Bestandsbuchung. | Catering plant event-getrieben; Bestand ist eigenes Biest (S4/später). |
| E5 | Lieferanten-Zuordnung je Zeile | **= effektiver Lead-LA** (`LeadLaService::effektiverLead`), manuell auf Ausweichquelle umstellbar (aus `rangliste`, Rang>1). | Konsistent zum Bestellvorschlag; Mensch behält Override. |
| E6 | MOQ/Frei-Haus/Bestellweg | **Reuse R9-Spalten** (`min_order_value`, `free_shipping_threshold`, `email_order`). **Neu nur:** `suppliers.delivery_days` + `order_cutoff` (Bestellschluss/Vorlaufzeit). | Kein Doppel-Schema; nur die fehlende Liefer-Logistik ergänzen. |
| E7 | MCP | **Neu `orders.GET/PUT/SEND` im Lockstep**, D1 + `isOwnedBy`-Gate (Muster `FavoritesPutTool`/`suppliers.PUT`). | Schreibkaskade nie nachträglich retrofitten (Präzedenz R0.2). |
| E8 | „Einkauf"-Blatt | **Aus der UI ziehen** (war Dublette zu Bestellung). `einkaufsliste()`-Methode **bleibt** — sie füttert die Schiene aus MEHREREN Zielen (Event → eine Schiene je Lieferant). | Verwirrung raus, Event-Aggregations-Fähigkeit erhalten. |
| E9 | Bedarfs-Quelle-Granularität | **Beliebig:** ganzes Konzept/Event · einzelnes Gericht · einzelne Produktion (Basisrezept solo, N Ansätze) — alle über „Bedarf übernehmen" in die Schiene, akkumulierend. Nutzt die vorhandenen `Ziel`-Pfade (`topsAus`: concept_id / recipe_id + Menge; `rezeptTopBatches` deckt Basisrezept-solo). | Dominique 2026-07-21: „man muss auch einzelne Produktionen bestellen können." Fällt aus dem Warenkorb-Modell (E1) natürlich ab — kein Zwang zum Voll-Event. |
| E10 | Re-Import-Idempotenz | **`addNeed` upserted pro (Schiene, Artikel, `source_ref`):** dieselbe Quelle erneut übernehmen ERSETZT ihren Beitrag (Mengen-Update); nur VERSCHIEDENE Quellen akkumulieren. | Pläne ändern sich (100→120 P.) und Doppelklicks passieren — der Bedarf darf sich dadurch nie verdoppeln. |
| E11 | Draft-Preise leben, Beleg friert | Im `draft` werden `pack_price`/`line_total` bei Anzeige/`recomputeTotal` aus dem **aktiven Preis** aufgefrischt; erst `send` friert den Snapshot ein (E2 unverändert). | Eine 10 Tage offene Schiene darf keine stalen Preise zeigen — sonst rechnet die MOQ-Ampel falsch. |

## 3. Stufe 0 — Gebinde-Bestellzeile + Blatt-Bereinigung · M · hängt an nichts · ✅ GEBAUT+GETESTET+GEPUSHT 2026-07-21 (`0d78bd2`)

> **Gebaut 2026-07-21 (`0d78bd2`, main):** `GebindeRechner` (pure/read-only, `berechne(leadLa, needG, pieceG)` → qty_packs/pack_qty/pack_unit_code/packaging_unit/article_number/pack_price/line_total/needed_base/ueberkauf_base/grund) + `PlanungsblattService`-Verdrahtung (Konstruktor-Inject, `gruppiereNachLieferant` liefert `gebinde` je Position + echte `ek_summe`, `explodiere` gibt VK-`portionen` aus) + Blade Live (`blaetter/index.blade.php`) + PDF (`dokumente/blatt.blade.php`) auf Gebinde-Spalten (Artikel · Bestellen · Bedarf · EK) + P1-Relabel + Einkauf-Block/Checkbox raus (Livewire-Default `['produktion','bestellung']`) + `BestellvorschlagGetTool`-Beschreibung (Lockstep, Output trägt gebinde durch). **Tests: `GebindeRechnerTest` 10/10 + `PlanungsblattServiceTest` 8/8 angepasst (echte Gebinde-EK); volle FA-Suite 906/908 (1 skip, 1 vorbestehender VoiceHuellenTest-Fehler ohne S0-Bezug), 0 Regressionen.** Blade-Falle gefunden+gefixt: `kg@endif` (Direktive an Wort geklebt → literal). Offen: Live-Klickstrecke auf demo (Deploy Martin).

**DoD:**
- [x] `GebindeRechner` mit Pest-Test: Gebinde-Aufrundung, Überkauf-Rest, `qty=NULL`-Fallback, Stück **mit + ohne Stückgewicht** vs. kg/l; API nimmt den **aggregierten** GP-Bedarf (E3).
- [x] Bestell-Blatt zeigt je Position: Artikel-Nr · Gebinde-Einheit · **Anzahl Gebinde** · Preis/Gebinde · **echte Zeilensumme** (packs × Preis) statt kg-Theorie.
- [x] EK-Summe je Lieferant = Summe echter Gebinde-Kosten (nicht mehr Gramm-Theorie).
- [x] Produktionsblatt: VK-Gericht als „N Portionen · gesamt X,X kg"; Basisrezepte unverändert (Ansätze).
- [x] „Einkauf"-Blatt aus UI entfernt; `einkaufsliste()` bleibt im Service.
- [x] `BestellvorschlagGetTool` gibt Gebinde-Felder aus (Lockstep), MySQL-verifiziert (Suite grün).

## 4. Stufe 1 — Beschaffungs-Stammdaten vervollständigen · S · hängt an nichts · ✅ GEBAUT+GETESTET+GEPUSHT 2026-07-21 (`3daf87d`)

> **Gebaut 2026-07-21 (`3daf87d`, main):** Migration `2026_07_21_000002` → `suppliers.delivery_days` (CSV ISO-Wochentag 1=Mo..7=So), `order_cutoff_time` (HH:MM), `order_lead_days` (Vorlaufzeit-Tage). MOQ/Frei-Haus/`email_order` lagen schon (R9). `SupplierService::updateConditions` um die 3 Keys erweitert (leer→NULL), `stammblatt` liefert neuen `bestellung`-Block. `SupplierDetail`-Modal (Konditionen-Tab): Liefertage als 7 Wochentag-Checkboxen + Bestellschluss/Vorlaufzeit + „Bestell-Logistik speichern". `suppliers.PUT` im Lockstep (Schema + Description + intersect-Keys). Test `SupplierRelationTest` „S1: Bestell-Logistik" (persistiert + aggregiert + leer-nullt); gezielte Suite 25/25.

**DoD:**
- [x] `delivery_days` + `order_cutoff_time` + `order_lead_days` auf `suppliers`, editierbar im R9-Konditionen-Tab, `suppliers.PUT` erweitert (Lockstep).
- [x] `stammblatt.bestellung` liefert Liefertag/Bestellschluss/Vorlaufzeit → Grundlage für S2-Ampel.

## 5. Stufe 2 — Persistente Bestellschiene (Kern) · L · hängt an S0 + S1 · ✅ KOMPLETT+GEPUSHT (Engine+MCP `bbc73e3` · UI-Slice `49f6c16`, 2026-07-21)

> **UI-Slice 2026-07-21 (`49f6c16` main):** Livewire `Orders/Index` + Blade („Bestellungen"-Seite: Schienen-Liste nach Status, Detail mit Gebinde-Zeilen + MOQ-Ampel + Status-Buttons [Absenden/bestätigt/geliefert/storniert, guarded] + inline-editierbare Gebinde-Anzahl mit Auto/Manuell-Marker + Zeile entfernen) · Route `/bestellungen` + Sidebar-Eintrag (Gruppe Planung) · „＋ Bedarf in Bestellschiene"-Button im Planungsblatt (`bedarfUebernehmen`, source_ref stabil pro Ziel → E10) + Erfolg-Hinweis mit Deeplink. Livewire-Tests (Bedarf-übernehmen idempotent + Bestellungen-Seite select/setStatus/updateLineQty/Freeze) → `OrderServiceTest` **12/12**.
>
> **Gebaut 2026-07-21 (Engine + MCP, `bbc73e3` main):** Migration `2026_07_21_000003` (`foodalchemist_orders` + `foodalchemist_order_lines`, Snapshot + `source_contributions` JSON) · `OrderStatus`-Enum (draft→sent→confirmed→delivered→cancelled, `darfWechselnZu`-Guard, delivered=Endstation) · Models `FoodAlchemistOrder`/`FoodAlchemistOrderLine` · `OrderService` (`draftForSupplier` mit Lock-Guard E1, `addNeedFromTarget` E9/E10, `recomputeLine`/`recomputeOrder` E3-Aggregat + E11-Live-Preis, `updateLine`/`removeLine`, `setStatus` guarded + E2-Freeze, `moqAmpel`) · `PlanungsblattService` liefert `lead_la_id` je Position. **MCP im Lockstep:** `orders.GET` (Liste/Detail+MOQ), `orders.ADD_NEED` (E9/E10), `orders.SET_STATUS` (guarded). **Test `OrderServiceTest` 10/10** (Draft-Guard, addNeed pro Lieferant, E10 ersetzen+akkumulieren, E3 Aggregat-Rundung 2×0,4→1 Sack, Status-Guard, E11/E2-Freeze, MOQ-Ampel, removeLine, MCP-E2E). Rechen-Wahrheit = derselbe `GebindeRechner` wie S0.
>
> **Offen (UI-Slice):** „Bestellungen"-Seite (Livewire `Orders/Index` + Blade: Schienen-Liste, Detail mit Gebinde-Zeilen + MOQ-Ampel + Status-Buttons + manuelle qty) · Sidebar-Eintrag + Route · „Bedarf in Bestellschiene übernehmen"-Button im Planungsblatt (auf jeder Ebene, E9).

**Bau (Spec):**
- Migrationen `foodalchemist_orders` (team_id, supplier_id, `status` enum draft|sent|confirmed|cancelled|delivered, reference/name, desired_delivery_date, note, total_net cache, sent_at/confirmed_at, created_by, uuid, timestamps, softDeletes, LogsActivity) + `foodalchemist_order_lines` (order_id, supplier_item_id, gp_id nullable, **Snapshot-Spalten** article_number/designation/packaging_unit/pack_qty/unit_code/pack_price, qty_packs, line_total, needed_base_qty, source_ref, position).
- `OrderService`: `draftForSupplier(team,supplier)` (get-or-create, **mit Unique-Guard „ein offener draft je (team, supplier)"** — partieller Index bzw. `lockForUpdate`, sonst erzeugt ein Doppelklick zwei Schienen), `addNeed(team, needPositions[], sourceRef)` (übernimmt Bedarf aus `bestellvorschlag`/`einkaufsliste` → je Lieferant in Schiene; **Upsert pro `source_ref` E10, Gebinde-Rundung auf dem GP-Aggregat der Schiene E3**), `updateLine`/`removeLine`/`recomputeTotal` (**frischt Draft-Preise aus aktivem Preis auf, E11**), `send` (draft→sent, Snapshot einfrieren), `cancel`, `listForTeam`/`forSupplier`. Schreib-Gate `isOwnedBy`.
- UI: Button „Bedarf in Bestellschiene übernehmen" im Planungsblatt — **auf jeder Ebene (E9):** ganzes Ziel, einzelnes Gericht ODER einzelne Produktions-Zeile (Basisrezept). → Schienen-Übersicht (pro Lieferant: Zeilen editierbar, **MOQ-Ampel** total vs. `min_order_value`, Frei-Haus-Hinweis, Liefertag/Bestellschluss aus S1). Neuer Sidebar-Eintrag „Bestellungen".

**DoD:**
- [ ] Bedarf aus 1 Ziel, MEHREREN Zielen (Event, `einkaufsliste`) ODER einer einzelnen Produktion/Gericht (E9) landet je Lieferant in genau EINER offenen Schiene (akkumuliert, nicht dupliziert).
- [ ] Zeilen manuell anpassbar (Gebinde-Anzahl ±, Zeile raus, Ausweich-LA wählen E5); Summe rechnet live.
- [ ] MOQ-Ampel: Schienen-Summe < `min_order_value` → Warnung; ≥ `free_shipping_threshold` → „frei Haus".
- [ ] Erneutes Übernehmen derselben Quelle ersetzt deren Beitrag (E10); Test „2 Quellen × Teil-Gebinde = 1 Gebinde" beweist Aggregat-Rundung (E3).
- [ ] Draft-Preise frischen bei Anzeige auf (E11): Preis ändern → draft folgt, versendeter Order bleibt eingefroren.
- [ ] `send`: Status draft→sent, Snapshot eingefroren, Schiene geschlossen; neue Bedarfs-Übernahme öffnet neue Schiene.
- [ ] Team-scoped, LogsActivity (Historie: angelegt/geändert/versendet), Pest (Akkumulation, Snapshot-Immutabilität nach send, MOQ-Ampel).

## 6. Stufe 3 — Versand/Export · M · hängt an S2 · ✅ GEBAUT+GETESTET 2026-07-21

> **Gebaut 2026-07-21:** `OrderService::dokument()` (Lieferant-Stammdaten + Zeilen + MOQ) + `mailtoData()` (Betreff/Body aus den Zeilen). Blade `dokumente/bestellung.blade.php` (Kopf Lieferant/Adresse/Liefertermin · Zeilen Artikel-Nr · Gebinde · Anzahl · Preis · Summe · Fuß Netto + MOQ-Ampel). Route `/bestellungen/{order}/dokument` — Druck-HTML · `?pdf=1` (DomPDF, guarded) · `?csv=1` (Excel-tauglich, `;`-getrennt + UTF-8-BOM). Buttons in der Bestellungen-Seite (Dokument/PDF/CSV/✉ E-Mail an `email_order`). **MCP-Lockstep:** `orders.UPDATE_LINE` (qty-Override/reset/remove) — schließt den Zeilen-Edit-Schreibpfad. `OrderServiceTest` **14/14** (+ 1 Route-Test, skip wenn Modul-Route im Harness fehlt). `orders.GET`/`ADD_NEED`/`SET_STATUS` lagen aus S2.

**DoD:**
- [x] Bestell-PDF + CSV + Druck-HTML pro Lieferant (Route `orders.dokument`).
- [x] `mailto:`-Vorbefüllung an `email_order` (Betreff + Zeilen-Body).
- [x] MCP: `orders.GET/ADD_NEED/SET_STATUS` (S2) + `orders.UPDATE_LINE` (S3), D1 + `isOwnedBy`.

## 7. Stufe 4 — Bestand & Wareneingang · XL · NICHT-ZIEL v1 (später)
Bedarf−Lagerbestand=Bestellmenge, Wareneingang, Inventur, MHD, Lagerorte. **Bewusst ausgeklammert** (E4). Erst wenn Dominique wirklich Lager führen will — dann eigene Spec.

## 8. Reuse-vs-Neu

| Reuse | Neu |
|---|---|
| `PlanungsblattService` (`bestellvorschlag`/`einkaufsliste`/`explodiere`/`gruppiereNachLieferant`), `LeadLaService` (`rangliste`/`effektiverLead`), `SupplierItem`-Gebinde-Felder + `prices.price`, `PriceService::preisProGramm`, R9-Konditionen (`min_order_value`/`free_shipping_threshold`/`email_order`), `FavoritesPutTool`/`suppliers.PUT`-Write-Muster, `SignalService`-Detektor-Muster, `dokumente/blatt.blade.php`-Druck-Vorlage, R9 `SupplierDetail`-Modal | `GebindeRechner`, `foodalchemist_orders` + `order_lines` (Snapshot), `OrderService`, Bestellschienen-UI + „Bestellungen"-Sidebar, `suppliers.delivery_days`/`order_cutoff`, `SignalTyp::BestellschlussHeute` (optional), `orders.GET/PUT/SEND` MCP, Bestell-PDF/CSV/mailto |

## 9. Verzahnung mit dem Kern
- **[14](14_Lieferanten_Management_R9.md)/R9:** Spec 17 ist der **explizit ausgeklammerte N-Track** von R9 §7 — es bedient dessen Konditionen (MOQ/Frei-Haus/Bestellweg) auf der Bestell-Ebene.
- **R7.1/Planungs-Blätter:** liefert den Bedarf; S0 macht dessen Bestell-Sicht Gebinde-echt, S2 macht sie versendbar.
- **[07](07_LA_First_GP_Mint_ueberall.md)/LA-First:** GPs ohne LA → keine Bestellzeile möglich → als Sourcing-Wunsch ans Lieferanten-Management (R9) zurück.
- **[13](13_Preis_Katalog_Ingest_Q2.md)/Q2:** frische Gebinde-Preise erhöhen die Bestell-Genauigkeit; versendete Snapshots bleiben davon unberührt (E2).

## 10. Bewusste Nicht-Ziele (v1)
- Kein Bestand/Wareneingang/Inventur/Netting (E4, S4/später).
- Keine Rechnungsprüfung/3-Way-Match.
- Kein automatischer Versand ohne Menschen (Absenden ist ein bewusster Klick).
- Kein EDI/Lieferanten-Portal-Anschluss (mailto/CSV/PDF reichen v1).
- Kein Anbruch-Bestellmodus, obwohl `prices.price_partial` (Anbruchpreis) im Schema existiert — v1 höchstens Info-Badge; bewusst simpel gehalten.
- `qs`/„nach Bedarf"-Zutaten (Salz, Öl …) erscheinen NICHT im Bestellvorschlag — sie sind heute schon vom Bedarf ausgeschlossen; Staple-/Lagerware, konsistent mit „ohne Bestand".

*Verzahnt: [14](14_Lieferanten_Management_R9.md) (N-Track-Fortsetzung), R7.1-Planungs-Blätter, [07](07_LA_First_GP_Mint_ueberall.md), [13](13_Preis_Katalog_Ingest_Q2.md). Dossier 2026-07-21, Scope „ohne Bestand" + E1–E8 festgezurrt. Nächster Schritt: S0 bauen (Gebinde-Primitive + Blatt-Fix).*
