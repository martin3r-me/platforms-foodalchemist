# Produktion + Einkauf v2 — benannte Produktionen, Ziel-Ebenen, 3-Panel-Einkauf (Ausbau von Spec 17+18)

> **ROADMAP-Bezug:** v2-Ausbau der N-Track-Geschwister [Spec 17](17_Bestellwesen_MiniWaWi.md) (Bestellwesen, ★live) + [Spec 18](18_Produktionsauftraege.md) (Produktionsaufträge). Dominique-Wunsch 2026-07-23.
> **Ziel:** Produktion mit **Namen** und mehreren Aufträgen pro Tag, Ziele auf **allen Ebenen** (Gericht / Konzept / Foodbook-Kapitel / Basisrezept mit kg), logisch neu aufgebaute Panels, sichtbare Einkaufs-Verdrahtung. Einkauf mit **3-Panel-Aufbau**, **Preisstrategie-Switch mit Neu-quellen** und **direkt bestellbaren Lieferantenartikeln** (unabhängig von jeder Produktion).
> **Reifegrad: ⚪ Dossier, bau-reif.** Abarbeitung über die **2h-Routine `produktion-einkauf-v2-umsetzung`** (täglich 13–23 Uhr, ab 2026-07-24) — **eine Stufe (oder sinnvoller Teilschritt) pro Run**, Fortschritt lebt in den DoD-Checkboxen + im Stand-Log unten.

---

## 0. Ist-Stand-Kartierung (verifiziert 2026-07-23, 6 parallele Code-Leser)

**Bleibt unverändert (reuse):** Rechenkern `PlanungsblattService::produktionsblattFuerZiele()/explodiere()/topsAus()` + `GebindeRechner` (E3-Aggregat-Rundung), Status-Lebenszyklen (`ProductionOrderStatus`, `OrderStatus` inkl. `darfWechselnZu`), Snapshot/Freeze-Verhalten (E2/E11 bzw. planned→in_progress), Handover `OrderService::addNeedFromTarget()` (idempotent per `source_ref`, E10), Rück-Sicht `ProductionOrderService::verknuepfteOrders()` (Commit `5490c84`), Export-Strecke (Dokument/PDF/CSV/mailto), MOQ-Ampel, Tenancy-Muster (`visibleToTeam`/`isOwnedBy`).

**Präzise lokalisierte Lücken:**
- Kein `name`-Feld an `production_orders` (nur `reference` = Anlass); `draftForDate()` + `saveNew()`-Merge erzwingen EINEN Auftrag je (team, production_date) — `ProductionOrderService.php:39-87`.
- Editor-Rezeptsuche filtert hart auf `->verkauf()` (`Livewire/Produktion/Editor.php:162`) — Basisrezepte nicht wählbar, obwohl `rezeptTopBatches()` sie kann (Zahl = Ansätze, `PlanungsblattService.php:262-266`). Keine kg-Eingabe im Ziel-Schema.
- `topsAus()` kennt nur `concept_id`/`recipe_id` (`PlanungsblattService.php:143-181`) — kein Kapitel-Ziel. Foodbook-Blocks tragen `quantity`+`unit_vocab_id`+`variant_group_id`, werden aber produktionsseitig von niemandem konsumiert.
- Einkauf ist EINE Livewire-Komponente (`Orders/Index.php`, 2-Karten-Grid) — kein Browser/DetailPanel-Split, keine Artikelsuche, keine Einstellungen im Modul.
- `OrderService` hat **kein** `addManualLine()` — Zeilen entstehen ausschließlich aus Bedarfs-Übernahme. Datenmodell trüge manuelle Zeilen fast schon: Cleanup-Guard `empty(source_contributions) && !is_manual_qty` (`OrderService.php:190`).
- `LeadLaStrategie` (guenstigster_preis|stamm_lieferant|prioritaets_kette) nur als Team-Setting (`Settings/Einkauf.php`); `rangliste()` nimmt keinen Override-Parameter; `recomputeLine()` wechselt den LA nie.
- Kopf-Felder `reference`/`desired_delivery_date`/`note` an Orders existieren im Schema, sind aber nirgends editierbar; Zeilen-`note` hat Service+MCP, aber kein UI-Feld.
- MCP-Lockstep-Lücken schon heute: kein `production_orders.REMOVE_TARGET`, kein Kopf-UPDATE, verknüpfte Bestellungen fehlen in `production_orders.GET`.
- Anzeige-Bug: Bedarf-Spalte zeigt bei Stk-Artikeln „kg" (`orders/index.blade.php:115`).

## 1. Festgezurrte Entscheidungen (Dominique, 2026-07-23)

| # | Frage | Entscheid | Konsequenz |
|---|---|---|---|
| V1 | Mehrere Produktionen pro Tag? | **Ja — Name+Datum = Identität.** P1 aus Spec 18 (ein Auftrag je Tag) fällt. | Ansatz-Rundung nur noch *innerhalb* eines Auftrags gemeinsam (bewusster Trade-off); `saveNew()` legt immer neu an, Adressierung auf `order_id`. |
| V2 | Foodbook-Kapitel als Ziel | **Kapitel + Personenzahl** (Vorbelegung aus `foodbook.personen`), Varianten-Wahl bei `variant_group_id`, als aufgelöste Einzel-Ziele gespeichert (kein Live-Bezug). | Danach je Gericht anpassbar; header/text/spacer/image-Blocks werden geskippt. |
| V3 | Preis-Einstellung im Einkauf | **Pro Schiene umschaltbar + „Neu quellen"** (Zeilen können die Schiene wechseln) + Alternativ-Artikel-Wahl je Zeile (löst E5-Versprechen aus Spec 17 ein). | `rangliste()`/`bestellvorschlag()` brauchen Strategie-Override-Parameter; `orders.sourcing_strategy` nullable. |
| V4 | Modultrennung | **Bleibt** (bestätigt Spec-18-P3). | Kopplung nur über `addNeedFromTarget` + zentralen `source_ref`-Helper. |

## 2. Stufen (Reihenfolge = Abhängigkeit; Routine arbeitet von oben nach unten, P und E parallel erlaubt)

| Stufe | Größe | Hängt an |
|---|---|---|
| P0 Namen + Mehrfach-Aufträge | S | — |
| P1 Ziel-Typen (kg/Basis/Kapitel) | L | P0 |
| P2 Editor v2 | M | P1 |
| P3 Browser/DetailPanel | M | P0 |
| P4 Verdrahtung härten | S | P0 |
| E1 3-Panel-Umbau | M | — |
| E2 Direktbestellung | M | E1 |
| E3 Strategie-Switch + Neu-quellen | L | E1 |

### P0 · Benannte Produktionen (Datenmodell + Service) · S
- [ ] Migration: `production_orders.name` (string nullable, Backfill aus `reference` bzw. Datums-Label; englische Identifier, Sequenz-Nr.).
- [ ] `saveNew()` legt immer einen neuen Auftrag an (kein Tages-Merge); `draftForDate()` nur noch als findOrCreate für den MCP-Kompat-Pfad; Auftrags-Adressierung auf `order_id`.
- [ ] Editor-Stammdaten: Name (Pflicht), Datum, Anlass, Notiz; Browser: Name als Hauptspalte, Sortierung/Gruppierung nach Datum.
- [ ] MCP-Lockstep: `ADD_TARGET` nimmt `order_id` ODER (`production_date` [+ `name`], legt an wenn fehlt); NEU `production_orders.UPDATE` (Kopf) + `production_orders.REMOVE_TARGET`.
- [ ] Pest: zwei benannte Aufträge am selben Tag koexistieren; Rundung je Auftrag separat dokumentiert.

### P1 · Ziel-Typen: Basisrezept + kg, Foodbook-Kapitel · L
- [ ] Dritter `zielTyp 'basisrezept'` im Editor, Suche ohne `->verkauf()`-Scope.
- [ ] targets-Feld `amount_kg`; `rezeptTopBatches()`: `batches = ceil(kg ÷ yield_kg)`, Warnung bei `yield_kg NULL`; Editor-Einheiten-Umschalter Ansätze ⇄ kg.
- [ ] Kapitel-Ziel `{chapter_id, persons}`: `topsAus()`-Zweig Kapitel → sichtbare `concept_ref`/`recipe_ref`-Blocks (Rest skippen); `concept_ref` → `konzeptTops()`, `recipe_ref` → VK-Ziel (Default 1 Portion/Person, Block-`quantity`+`unit_vocab_id` wenn gesetzt); Varianten-Dialog, gewählte Variante als aufgelöste Einzel-Ziele.
- [ ] `labelFor()` um kg-/Kapitel-Labels erweitern.
- [ ] MCP-Lockstep in ALLEN 5 zielnehmenden Tools gleichzeitig (`production_orders.ADD_TARGET`, `orders.ADD_NEED`, `produktionsblatt.GET`, `bestellvorschlag.GET`, `einkaufsliste.GET`); Doppel-Bedeutung von `portions` (VK=Portionen, Basis=Ansätze) in allen Descriptions dokumentieren.
- [ ] Pest: kg→Ansätze inkl. NULL-yield; Kapitel-Explosion inkl. Varianten-Wahl + Block-Typ-Filter; `source_ref`-Idempotenz bleibt.

### P2 · Editor v2 („schlau eingeben") · M
- [ ] 3 Karteien (bestehende `modal-section`-Bausteine): Stammdaten → Ziele (4-Typen-Picker, typabhängiges Mengenfeld mit Einheiten-Suffix, Ziel-Liste mit Edit/Remove) → Live-Vorschau (bestehend).
- [ ] Kapitel-Picker: Foodbook-Select → Kapitel-Baum → Personenzahl (Pax-Vorbelegung) → ggf. Varianten-Dialog.

### P3 · Browser + DetailPanel logisch neu · M
- [ ] Browser-Tabelle: Name · Datum · Ziele (Kurz-Labels) · KPI (Ansätze/Portionen) · Status · Einkaufs-Indikator (keine/offen/versendet).
- [ ] DetailPanel-Sektionen: Kopf → KPI → **Ziele** (je Ziel: Label, Menge, übergeben ✓/–) → Rezepte & Ansätze → **Einkauf** (verknüpfte Bestellungen + Deckungsgrad je Ziel) → Warnungen → Aktionen.

### P4 · Einkaufs-Verdrahtung härten · S
- [ ] Stale-Marker: `last_handover_at` + Ziel-Hash am Auftrag; Ziele nach Übergabe geändert → Hinweis „Bestellung veraltet — erneut übergeben".
- [ ] `source_ref`-Präfix-Bau (`produktion:{id}:`) in EINEN Helper zentralisieren; LIKE-Suche mit trailing-Doppelpunkt bleibt (kein FK — eine Zeile kann N Quellen haben).
- [ ] `production_orders.GET`-Detail liefert verknüpfte Bestellungen mit; optional `production_orders.HANDOVER`-Tool.

### E1 · 3-Panel-Umbau Einkauf (Muster: Produktion) · M
- [ ] `Orders/Index` aufteilen: **Panel 1** Schienen-Browser (Status-/Lieferant-Filter, Suche, Drafts zuerst) · **Panel 2** Positionen (Artikel · Gebinde · Anzahl Auto/Manuell · Bedarf · Preis · Summe · Herkunfts-Badge · Alternativ-Artikel-Dropdown · Zeilen-Notiz · ✕) · **Panel 3** Detail/Aktionen (Status-Buttons, MOQ-/Frei-Haus-Ampel, Liefer-Logistik, editierbare Kopf-Felder via NEU `OrderService::updateHeader()` + `orders.UPDATE`, Herkunft aus geparsten `source_contributions` mit Links, Einstellungen [E3], Export).
- [ ] Anzeige-Bug fixen: Stk-Artikel zeigen „kg" in der Bedarf-Spalte (`orders/index.blade.php:115`).

### E2 · Direktbestellung: manuelle Artikel + Bedarf-Schnellerfassung · M
- [ ] `OrderService::addManualLine(team, supplierItemId, qtyPacks, note)` → `draftForSupplier()` + Zeile `is_manual_qty=true`, `source_contributions=[]` (Cleanup-Guard verschont sie); Preis-Snapshot über `recomputeLine`-Pfad (`needed_base_g=0`).
- [ ] UI: „＋ Artikel"-Button mit globaler LA-Livesearch (Artikel → Draft-Schiene seines Lieferanten, anlegen wenn nötig) + „Neue Bestellung" je Lieferant.
- [ ] Bedarf-Schnellerfassung im Einkauf: Mini-Form Gericht/Basisrezept + Menge → `addNeedFromTarget` direkt (nutzt P1-Zieltypen inkl. kg).
- [ ] MCP-Lockstep: `orders.ADD_LINE` + `orders.CREATE`; Tenancy-Muster spiegeln.
- [ ] Pest: manuelle Zeile übersteht Recompute + Bedarfs-Übernahme in dieselbe Schiene; Preis-Refresh im Draft; Freeze bei send.

### E3 · Preisstrategie-Switch + „Neu quellen" · L
- [ ] `LeadLaService::rangliste()`/`effektiverLead()` mit optionalem `LeadLaStrategie`-Override; durchreichen bis `gruppiereNachLieferant()`/`bestellvorschlag()`.
- [ ] Migration: `orders.sourcing_strategy` (string nullable) = Override je Schiene, NULL = Haupteinstellung.
- [ ] `OrderService::resourceOrder(order, strategy)`: je Zeile mit contributions Lead-LA neu auflösen; anderer Lieferant → Contribution in dessen Draft-Schiene verschieben (anlegen wenn nötig); E3-Rundung + E10-Idempotenz bleiben; manuelle Zeilen + `is_manual_qty` unangetastet.
- [ ] Alternativ-Artikel-Dropdown je Zeile aus `rangliste(gp)` (Rang > 1, Preisvergleich); Lieferanten-Wechsel = Zeile wandert mit Bestätigung.
- [ ] UI: Strategie-Select in Panel 3 + „Neu quellen"-Button (nur draft, mit Vorschau der Wechsel).
- [ ] MCP-Lockstep: `strategy`-Parameter in `bestellvorschlag.GET` + `einkaufsliste.GET` + `orders.ADD_NEED` (Vorschlag ≠ Übernahme verhindern) + NEU `orders.RESOURCE`.
- [ ] Pest: Neu-quellen wechselt Lieferant + verschiebt Contributions; Strategien deterministisch; gesendete Belege unberührt.

## 3. Verifikation (Abschluss-Gate, nach letzter Stufe)

- [ ] Volle Modul-Suite grün (Referenz ~928, 2 bekannte Fremd-Fails).
- [ ] Sandbox-Klickstrecke: Produktion „Sommerfest" (Kapitel-Ziel mit Varianten-Wahl + Basisrezept 5 kg) → zweite Produktion am selben Tag → übergeben → Bestellungen: 3 Panels, Herkunft sichtbar, Strategie „günstigster Preis" + Neu quellen → manueller Artikel + „Neue Bestellung" → PDF/CSV/mailto → Status-Kaskade → Rück-Sicht inkl. Stale-Marker.
- [ ] MySQL-Smoke für alle neuen Migrationen (SQLite≠MySQL-Falle) — Deploy selbst = Martin/Runbook.
- [ ] Spec 17/18 mit v2-Verweis versehen; Matrix-Zeile 20 auf ✅; Routine `produktion-einkauf-v2-umsetzung` deaktivieren.

## 4. Bewusste Nicht-Ziele (v2)

- Kein Bestand/Wareneingang/Netting (Spec 17 E4/S4 bleibt Nicht-Ziel).
- Kein Auto-Sync Produktion→Bestellung (Übergabe bleibt bewusster Klick; nur Stale-Hinweis P4).
- Keine Stationen-/Personal-Zuweisung in der Produktion (Spec 18 Nicht-Ziel bleibt).
- Kein FK statt `source_ref` (eine Bestellzeile kann N Quellen haben — Helper statt Schema-Umbau).

## 5. Stand-Log der Routine

> Jeder Run trägt hier eine Zeile ein: Datum/Uhrzeit · bearbeitete Stufe · Ergebnis (Commit-Hash, Tests) · nächster Schritt.

| Wann | Stufe | Ergebnis | Nächster Schritt |
|---|---|---|---|
| _(noch kein Run)_ | — | Dossier angelegt (Session 2026-07-23) | P0 starten |

*Verzahnt: [17](17_Bestellwesen_MiniWaWi.md) · [18](18_Produktionsauftraege.md) · [14](14_Lieferanten_Management_R9.md) (LeadLaStrategie). Dossier 2026-07-23; Entscheide V1–V4 mit Dominique festgezurrt.*
