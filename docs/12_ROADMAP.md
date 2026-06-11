---
typ: Arbeits-Roadmap (fein-granular)
stand: 2026-06-11
status: verbindliche Abarbeitungs-Reihenfolge — ein Paket = eine Arbeits-Session
---

# 12 — Roadmap: Food Alchemist Modul-Aufbau (Paket für Paket)

**Arbeitsweise:** Jedes Paket ist klein genug für eine Session, hat Referenzen in den
Spec-Korpus und eine messbare Definition-of-Done (DoD). Pakete in Reihenfolge abarbeiten,
Abhängigkeiten beachten (`nach:`). Nach jedem Paket: `php -l`, betroffene Pest-Tests,
Sandbox-Check. **Kein commit/push ohne Freigabe durch Dominique.**

**Status:** ☐ offen · ◐ teilweise · ☑ fertig

**Verbindliche Grundlagen:** `01_ARCHITEKTUR` (§0 Produktvision!) · `08_ENTSCHEIDUNGEN`
(D1 = Eltern→Kinder-Vererbung!) · `11_UI_PATTERNS` (P-1…P-8) · D-Specs + GL-Specs ·
`09_TESTKATALOG` (Golden-Tests = Abnahme). Bei Widerspruch: Regelwerk > GL > D > Roadmap.

**Reihenfolge:** M0 → M1 → M2 → M3 → M4 → M5 → M6. M7 (KI-Engine) startet nach M3 parallel.
M8 läuft mit. Jedes Modul endet mit einem Abnahme-Paket (Dominique reviewt in der Sandbox).

---

## Dirigenten-Protokoll — so wird JEDES Paket abgearbeitet

1. **Vorbereiten:** Paket-Zeile lesen → alle referenzierten Spec-Abschnitte ÖFFNEN und lesen
   (D-Spec-§, GL-Spec mit Golden-Tests, P-Pattern in `11_UI_PATTERNS`). Bei `nach:`-Abhängigkeit
   prüfen, dass das Vorgänger-Paket ☑ ist.
2. **Bauen:** Nur den Paket-Scope umsetzen — nichts aus späteren Paketen vorziehen.
   Code im Modul-Repo (`platforms-foodalchemist`), nie in Core/UI/Fremdmodulen (GIT.HUB/CLAUDE.md
   Goldene Regeln). Tenancy: jede neue Query durch `visibleToTeam()`, jeder Edit durch
   `isOwnedBy()`/`canCurate()` (D1).
3. **Verifizieren:** Standard-DoD (unten) + die Paket-DoD aus der Tabelle. UI-Pakete zusätzlich:
   Sandbox-Browser-Check, bei neuen Screens Screenshot gegen den Ist-App-Referenz-Screen.
4. **Dokumentieren:** Status-Spalte hier auf ☑ (mit Datum), neue Erkenntnisse/Abweichungen in
   die betroffene D-/GL-Spec bzw. `08_ENTSCHEIDUNGEN`, offene Folgearbeit als Notiz am Paket.
5. **Stoppen:** Nach dem Paket Review anbieten — NICHT eigenmächtig ins nächste Paket
   weiterlaufen. Abnahme-Pakete (M2-13, M3-13, M4-13, M6-08) macht Dominique persönlich.

## Standard-DoD — gilt ZUSÄTZLICH zu jeder Paket-DoD

- [ ] `php -l` grün auf allen berührten PHP-Dateien; Blade kompiliert (Seite einmal echt gerendert)
- [ ] Betroffene Pest-Tests grün; bei neuen Logik-Services: Golden-Tests aus `09_TESTKATALOG` umgesetzt
- [ ] Tenancy-Check: neue Queries gescoped (Leak-Test aus M0-06 läuft, sofern Harness existiert)
- [ ] Sandbox läuft: `migrate` fehlerfrei (bei Schema-Paketen), betroffene Seite lädt ohne 500
- [ ] Lineage respektiert: KI-Schreibwege setzen `*_quelle`/Konfidenz, manuelle Edits → `manual` (GL-07)
- [ ] Dichte/Optik: neue Views nutzen die `_density`-Maps + Bausteine aus M0 — keine Insellösungen
- [ ] Status-Spalte in dieser Roadmap aktualisiert (☐→◐→☑ mit Datum)
- [ ] **Kein `git commit`/`push`** — Staging ok, Commit nur nach Freigabe durch Dominique

---

## M0 — Fundament (Tenancy, UI-Bausteine, Test-Harness)

| ID | Paket | Inhalt | Ref | DoD | Status |
|---|---|---|---|---|---|
| M0-01 | Tenancy-Concern | `BelongsToTeamHierarchy` (scopeVisibleToTeam via ancestryIds, isOwnedBy), alle 7 Models umgestellt, alter Concern entfernt | D1 in 08 | php -l grün; kein `ScopedToTeamOrGlobal`-Rest | ☑ 2026-06-11 |
| M0-02 | Import `--team` | ImportSliceCommand: Pflicht-Option `--team`, alle `team_id`-Inserts; Sandbox: Backfill `team_id=1` auf Bestand ODER Re-Import | D1; 07 §3 | dry-run validiert Team; Sandbox-Daten team_id=1; Row-Gates grün | ☑ 2026-06-11 (Backfill + dry-run-Validierung) |
| M0-03 | Overlay-Migration | `foodalchemist_gp_team_overrides` (team×gp → lead_la_override_id, blocked_supplier_item_ids JSON, unique) | D1/V-27 | migrate grün in Sandbox | ☑ 2026-06-11 |
| M0-04 | GpService Team-Scope | Signaturen `?int $teamId` → `?Team $team`, `scoped()`-Helper (null ⇒ 1=0 Leak-Schutz), Livewire-Aufrufer | D1 | php -l; GP-Browser lädt in Sandbox unverändert | ☑ 2026-06-11 (Browser-Beweis mit Hierarchie-Scope) |
| M0-05 | Pest-Harness | Test-Setup im Modul (orchestra/testbench oder Tests via Sandbox-App entscheiden + dokumentieren), 1 Beispieltest läuft | 09 §0 | `vendor/bin/pest` (o. Sandbox-Äquivalent) grün mit 1 Dummy | ☑ 2026-06-11 (Entscheid: Host-App/Sandbox statt Testbench, Doku in 09 §0; Tests im Modul-`tests/`, Suite `FoodAlchemist`; 3 Smoke-Tests grün. Offen → M0-06: RefreshDatabase-Konzept; → M3-06: Postgres-Connection) |
| M0-06 | Leak-Test-Harness | Wiederverwendbarer Pest-Helper: Root-Team + 2 Geschwister-Kinder seeden; Assertion „Geschwister sieht nichts, Kind sieht Eltern-Katalog" | D1-Risiko | Helper + 1 Test gegen `foodalchemist_gps` grün | ☑ 2026-06-11 (`tests/Support/SeedsTeamHierarchy` migriert selektiv teams+Modul-Tabellen in :memory:; 4 Leak-Tests grün inkl. „Eltern sieht Kind nicht" + isOwnedBy. Concern: Ancestry-Cache flushbar gemacht) |
| M0-07 | Baustein master-detail | `components/master-detail.blade.php`: 3 Zonen (tree/table/panel-Slots), Panel kollabierbar, Ist-App-Dichte | P-1 | Demo-Seite rendert 3 Zonen in Sandbox | ☑ 2026-06-11 (`<x-foodalchemist::master-detail>`, tree/panel optional, Alpine-Kollaps mit Rail; Demo auf /foodalchemist/test; 2 Render-Tests. $card-Klassen inline → Konsolidierung in M0-12) |
| M0-08 | Baustein modal | `components/modal.blade.php`: großes scrollbares Sektions-Modal, Alpine open/close, Event `modal.open`, Footer-Aktionen-Slot | P-2 | Demo-Modal öffnet/schließt ohne State-Leak | ☑ 2026-06-11 (`<x-foodalchemist::modal>` + `modal-section`; Custom-Frosted als Fassade [x-ui-Frage bei Martin offen]; Kopf-Aktionen fix oben links P-2 + Footer-Slot; `modal.closed`-Reset-Vertrag; Browser-verifiziert inkl. State-Leak-Probe + Screenshot. Erkenntnis: Alpine `.dot`-Syntax, s. P-2) |
| M0-09 | Baustein ki-header | `components/ki-header.blade.php`: Label · Quelle(ki/manual) · Konfidenz% · Reset/Manuell/Autopilot-Buttons, Events nach GL-07 (ai_/accept_/clear_) | P-3, GL-07 | rendert alle 3 Quellen-Zustände | ☑ 2026-06-11 (`<x-foodalchemist::ki-header>`; wire:click-Vertrag `ai_/accept_/clear_/manual_<field>`; Übernehmen nur bei `:has-proposal`; Begründung als Tooltip; 4 Render-Tests + Live-Zyklus inkl. Override-First im Browser verifiziert. Demo-Fake-Roundtrip auf Test.php bis M0-14) |
| M0-10 | Baustein tri-state | `components/tri-state.blade.php`: −/≈/✓ + unbekannt (Alpine, ein wire:model aufs Array), Farbcode grau/amber/rot | P-4, GL-01 | 4 Zustände togglebar, Array-Binding korrekt | ☑ 2026-06-11 (`<x-foodalchemist::tri-state>`; entangle deferred [Sync mit nächstem Request, P-8-konform], readonly-Variante; fehlende Keys ⇒ unbekannt; 4 Render-Tests + Browser-Beweis: Toggle-Zyklus, Server-Array nach Sync korrekt) |
| M0-11 | Baustein chips | `components/chips.blade.php`: Chips mit ×, „+ manuell…"-Combobox gegen Vokabular-Array, optional ★-Prefix | P-5 | Add/Remove funktioniert via Livewire | ☑ 2026-06-11 (`<x-foodalchemist::chips>`; Datalist-Combobox, Enter fügt hinzu, Duplikat-Guard, entangle deferred, readonly-Variante; 5 Render-Tests + Browser-Beweis Add/Remove/Sync) |
| M0-12 | Dichte-Maps | `livewire/_density.blade.php`: zentrale Klassen-Maps (Tabelle 13px/py-1.5, Labels uppercase-xs, Pills) — Slice-Maps dorthin konsolidieren | P-Abschnitt „Dichte" | GP-Slice-Views nutzen die Maps (Beweis der Wiederverwendung) | ☑ 2026-06-11 (als `Support/Ui::maps()` statt Blade-Partial — @include leakt keine Vars in den Eltern-Scope; Views: `@php(extract(Ui::maps()))`. gps/index+show komplett umgestellt [13px/py-1.5 live verifiziert, 0 Insel-Klassen], master-detail+ki-header konsolidiert, 3 Unit-Tests) |
| M0-13 | KPI-Leiste | `KpiService` (Cache 60 s: n Lieferanten/GPs/LAs/Rezepte je Team-Kette) + Anzeige in Actionbar | P-7-Header | Zahlen stimmen mit SQL-Counts überein | ☑ 2026-06-11 (`KpiService::forTeam()` + `<x-foodalchemist::kpi-bar>`; LAs = Strukturen [9.803, Ist-App-Semantik]; Rezepte NULL bis M4-01 [hasTable-Guard]; Sandbox: 120 · 7.774 · 9.803 == SQL; 3 Tests inkl. Geschwister-Leak + Cache/flush. Platzierung vorerst Content-Header — Actionbar/Navbar-Frage bei Martin offen) |
| M0-14 | KI-Gateway-Basis | `AiGatewayService` (Fassade, Transport → Core `LLMProviderContract`), `FakeAiProvider` (deterministisch, Sandbox ohne Key), `config: prompts`-Skeleton | D3/D-4, GL-06 | Fake-Roundtrip-Test grün; echter Provider per config wählbar | ☑ 2026-06-11 (`Services/Ai/`: Gateway.propose() [GL-07-Propose, Confidence-Clamp, Hüllen-Hook für M7-05], FakeAiProvider implementiert Cores Contract [Kontext-Echo, conf 0.87], `ai.provider`-Weiche core\|fake + prompts-Skeleton. 5 Tests + Sandbox-Live-Roundtrip. Falle dokumentiert: Prompt-Keys mit Punkt nie via config()-Dot-Pfad lesen) |

## M1 — Einstellungen & Vokabulare (D-1)

| ID | Paket | Inhalt | Ref | DoD | Status |
|---|---|---|---|---|---|
| M1-01 | Settings-Gerüst | Route `/einstellungen`, Sidebar-Gruppe „Einstellungen", Sektions-Navigation (vertikale Tabs), nur Katalog-Besitzer sieht Edit | D-1 §4 | Seite rendert, Read-only für Kind-Team | ☑ 2026-06-11 (`Settings/Index` + Sektion in URL [V-17]; 5 Sektionen als eigene Livewire-Komponenten; Kind-Team-Banner; Edit-Gating zeilenweise via `Curate::canCurate` [M1-08-Kern, 3 Tests]. Provider-Fix: Alias-Kebab je Segment. Beifang: `foodalchemist_team_settings`+Service als M1-05/07-Speicher) |
| M1-02 | Einheiten-Verwaltung | CRUD `vocab_einheiten` + Stück-Default-Gewichte (Ist-App „Einheiten verwalten"); Lineage bei Änderung | D-1, GL-05 | Einheit anlegen/ändern wirkt im GP-Detail | ☑ 2026-06-11 (VocabularyService-Einheiten-Familie [D1-Ownership, Slug-Kollision V-06, Inaktiv-Lebenszyklus AT-D1-04, Delete-Guard bei GP-Referenz] + Inline-Edit-UI; 6 Tests; DoD-Beweis: Rename „Dose" → sofort im GP-2-Detail. Lineage = LogsActivity [Schema hat keine Spalten-Lineage — manuelle Pflege, kein GL-07-Feld]. Blade-php-Block-Falle gefunden+dokumentiert, s. 11 P-2) |
| M1-03 | Warengruppen & Sub-Kategorien | WG read-mostly (§3-Codes fix!), Sub-Kategorien CRUD mit Regelwerk-Hinweisen | Regelwerk GP §3 | §3-Codes nicht löschbar; Sub-Kat CRUD ok | ☑ 2026-06-11 (Delete §3-Codes 01–15 wird IMMER verweigert [+UI disabled mit Hinweis]; Name-Pflege nur Besitzer; Sub-Kat-Übersicht mit Zählern, Rename/Clear propagiert via GpService NUR auf eigene GPs [AT-D1-03+D1-Schutz geerbter Zeilen]; 6 Tests) |
| M1-04 | Rezept-Taxonomie | Hauptgruppen (30) + Kategorien (139) CRUD + Sortierung; Quelle Skript 204-Stand | D-1, Regelwerk BR §1 | Browser-Bäume (M4) lesen daraus | ☑ 2026-06-11 (2 Tabellen+Models, Import-Phasen [30 HG + **186** Kat — Quellstand gewachsen ggü. Roadmap-139, Row-Gates ✅], CRUD+Sortierung mit D1-Ownership + Delete-Guard AT-D1-02 [recipe_count via hasTable bis M4-01], Settings-Sektion HG-Baum+Kategorien; M4-Lese-Vertrag = listMainGroups/listRecipeCategories; 4 Tests. Merge-Modal folgt mit M4-Kontext) |
| M1-05 | **Lead-LA-Strategie** | Team-Einstellung: `guenstigster_preis` \| `stamm_lieferant` \| Prioritäts-Kette; + „Ausweich-Kette anzeigen"-Toggle. Speist LeadLaService (M3-06) | V-27; D1-Overlay | Einstellung ändert Lead-Wahl nachweisbar (Test) | ☑ 2026-06-11 (`LeadLaStrategieResolver.sortiere()` = M3-06-Vorstufe [Strategie-Stufe → NULLS-LAST-Preis → supplier_item_id-Tiebreaker, PHP-seitig = engine-agnostisch 07 §7]; Einkauf-Sektion mit Radio+Prioritäts-Ketten-Editor+Toggle; 5 Tests inkl. DoD-Beweis: 3 Strategien ⇒ 3 verschiedene Leads; Stamm-IDs kommen als Parameter aus M1-06) |
| M1-06 | Stamm-Lieferanten-Matrix | Import `stamm_lieferant`/`stamm_lieferant_wg` (Vault-Skript 212) + Pflege-UI (Lieferant×WG-Grid) | GL-03/V-27 | Matrix editierbar, von M3-06 gelesen | ☑ 2026-06-11 (Tabelle+Model [NULL-WG = global], dedizierte Import-Phase [21 global + 113 WG, Gates ✅], StammLieferantService mit Lese-Vertrag `stammSupplierIdsFor(team, wg)` = WG+global für M3-06/Resolver; Matrix-UI in Einkauf-Sektion [134 Chips, geerbte fixiert]; 4 Tests inkl. D1-Vererbung+Geschwister-Trennung) |
| M1-07 | Kalkulations-Defaults | Garverlust-Defaults je GP-Klasse, MwSt-Defaults, Rundungsregeln — eine Settings-Sektion | GL-02 | Defaults greifen im Rezept-Editor (M4) | ☐ |
| M1-08 | Katalog-Pflege-Gate | Policy-Helper `canCurate(User,Team)` (Owner-Team-Mitglied), zentral genutzt von allen Edit-UIs | D1 | Leak-Test: Kind-User sieht keine Edit-Buttons | ☑ 2026-06-11 (`Support/Curate::canCurate` [seit M1-01 einzige Edit-Gating-Quelle: Einheiten/WG/Taxonomie-Zeilen, Stamm-Chips]; Harness um `makeUser()` erweitert [Core users+ai_user_fields-Migrationen, core_ai_models-Stub wegen SQLite-FK-Validierung]; UI-Leak-Test: Kind-User ⇒ readonly+geerbt-Pill, Besitzer ⇒ Edit-Buttons; 3+2 Tests) |

## M2 — Lieferanten (D-2)

| ID | Paket | Inhalt | Ref | DoD | Status |
|---|---|---|---|---|---|
| M2-01 | Browser-Gerüst | `Suppliers/Browser`: master-detail-Baustein, Lieferanten-Liste links („n Artikel · m gemapped" grün), Suche, Inaktive-Toggle | P-7; D-2 §4 | Liste mit echten Counts (SQL-verifiziert) | ☑ 2026-06-11 (`Suppliers/Index` /lieferanten; Liste in linker Page-Sidebar [Platzierungs-Entscheid statt master-detail-Zone], Auswahl in URL `?lieferant=`; Counts SQL-verifiziert: BOS 17.879/630) |
| M2-02 | Artikel-Tabelle | Mitte: ArtNr · Bezeichnung · Gebinde · Status · EK · GP-Mapping-Link; Pagination; „Nur aktive" | P-7 | 17.879 BOS-Artikel flüssig blätterbar | ☑ 2026-06-11 (~200 ms/Seite bis Seite 716; EK aus PriceService-Subquery; qty?-Warn-Pill; Kopf-Aktionen als Platzhalter bis M2-11/12/M3-11) |
| M2-03 | Übergreifende Suche | Artikel-Suche über alle Lieferanten (Feld oben links, Ist-App), Treffer-Liste mit Lieferant-Spalte | P-7 | Suche „Limettensaft" findet die GT-1-LAs | ☑ 2026-06-11 (?q= in URL, LOWER-Suche auf designation + ArtNr-Prefix; Sandbox: 16 Treffer inkl. GT-1-Lead Delta Fleisch; 4 Service-Tests inkl. Geschwister-Leak) |
| M2-04 | PriceService | Aktiv-Preis-Regel aus `GpService::lasForGp()` extrahieren (eine Stelle!), Historie-Query, „neuer Preis schließt alten" | GL-03/GL-11 | Pest: Aktiv-Preis-Golden (GT-1 47,50 € status 2) | ☑ 2026-06-11 (PriceCategory-Enum [§3.1, price<0 vor status], PriceService: scopeAktiv index-fähig, activeFor [§3.3 neueste aktive, NULL-valid_to engine-agnostisch ans Ende], Subquery für Listen, historyFor; GpService umgestellt. GT-1 real bestätigt: 47,50 € status 2. „Schließt alten" folgt in M2-08 [createFor]) |
| M2-05 | Vergleichspreis | Normalisierung €/kg-€/l-€/Stk aus qty+Einheit; Spalte in Tabelle + Modal-Kopf | D-2 §3 | Stichproben gegen Ist-App-Werte (0.81 €/kg Golden Delicious) | ☑ 2026-06-11 (PriceService §3.2 [I4/I5-Guards] + Tabellen-Spalte; Modal-Kopf folgt M2-06. **Abweichung:** 0.81 €/kg Golden Delicious im Quellstand nicht reproduzierbar [Minimum real 2,10 €/kg — Preisstand gewachsen]; stattdessen GL-11-GT-2 real verifiziert: Zucker 42 €/25 kg = 1,68 €/kg exakt) |
| M2-06 | ItemModal lesend | modal-Baustein: Sektionen Stammdaten/Verpackung/Eigenschaften/Preise — erst read-only komplett | P-2/P-6 | GT-1-Artikel zeigt alle Felder + Preis-Historie | ☑ 2026-06-11 (`Suppliers/ItemModal` auf modal-Baustein, öffnet via Zeilen-Klick/`item-modal.oeffnen`; Kopf mit EK+Vergleichspreis [M2-05-Rest]; Browser-Beweis GT-1: 47,50 € · 4 Sektionen · Historie mit Kategorie-Pill · qty-NULL ⇒ „kein Vergleichspreis") |
| M2-07 | ItemModal Edit | Edit Stammdaten+Verpackung+Eigenschaften (nur Besitzer-Team), Validierung, LogsActivity | P-2; M1-08 | Edit-Roundtrip; Kind-Team read-only | ☑ 2026-06-11 (Curate-Gate [Felder disabled + read-only-Pill für Kind], Pflicht-Validierung Bezeichnung, modal.closed ⇒ Full-Reset [P-2 State-Leak]; Live-Roundtrip im Browser bewiesen [brand speichern→reload→revert]) |
| M2-08 | Preis-Edit | „+ Neuer Preis" (P-6, schließt Vorgänger), Preis löschen, Kategorie-Pill | P-6; M2-04 | Historie konsistent nach 3 Operationen | ☑ 2026-06-11 (PriceService::createFor [Tx: Vorgänger valid_to-Stempel, neue Zeile unbefristet ⇒ sofort aktiv] + deleteFor; Guards: nur Besitzer, kein Negativ-Preis [I5], Status 0|2. **Erkenntnis:** Aktiv-Ranking auf NULL-FIRST gedreht — 108.310/111.543 aktive Realzeilen sind unbefristet, Append-only stempelt die ALTE Zeile; 3 Lebenszyklus-Tests) |
| M2-09 | **LA-Allergen-Import** | Import-Phase `allergens` (wawi `allergens`/`v_allergen_named` → Zielform laut 02_DATENMODELL) — **GL-01-Blocker!** | GL-01; 07 §3 | Row-Gate; GT-1-LA zeigt 14 Werte | ☑ 2026-06-11 (`foodalchemist_item_allergens`: 14 EU als 4-Wert-Strings [0⇒NULL=unbekannt], Unterarten lossless als details-JSON, quelle-Lineage; Import 139.012, Gate ✅; GT-1-Soll-Lead 29344887 zeigt 14 Werte [Ist-Lead 31141191 hat auch in der Quelle keine Zeile]) |
| M2-10 | Allergen-Anzeige+Edit | P-4 tri-state im Modal (read-only für Kind), 14 EU + Lineage | P-4, GL-01 | Edit ändert Aggregation (sichtbar ab M3-05) | ☑ 2026-06-11 (tri-state-Baustein 2-spaltig im Modal, Quelle-Pill [Import/manual], get/setAllergens [nie Lücken: 14 Werte immer; unbekannt⇒NULL; manual-Stempel GL-07; D1-Gate]; 4 Tests + Browser-Beweis 29344887) |
| M2-11 | Artikel-CRUD | „+ Neuer Artikel" (Minimal-Pflichtfelder), Deaktivieren (soft), eigener Artikel im Kind-Team möglich! | D-2 §4; D1 | Kind-Team legt eigenen Artikel an, Eltern sehen ihn NICHT | ☑ 2026-06-11 (create [Pflicht Bezeichnung, Sichtbarkeits-Guard, team_id = anlegendes Team] + setDiscontinued [soft, nur Besitzer]; Anlage-Modal im Browser, öffnet danach direkt den LA-Editor; DoD-Test: Kind-Artikel für Eltern UND Geschwister unsichtbar) |
| M2-12 | Preis-Anomalien | Report-Modal: Sprünge >x % zwischen Preis-Generationen, Ausreißer je WG | V-Register | Liste mit echten Treffern aus Bestand | ☑ 2026-06-11 (detectAnomalies: Generationen-Sprünge >30 % + WG-Median-Ausreißer Faktor ≥4 [flache Join-Query, 934 ms/85 MB über 9.5k gemappte LAs]; **echte Treffer: 1.041 Ausreißer** [top: Safran ×502 — fachlich plausibel; „Leitungswasser 0 €" erkannt] + 1 Sprung; Report-Modal mit Top-50) |
| M2-14 | Lieferanten-Editor + lokale Suche | „Bearbeiten"-Modal (Stammdaten) + „Deaktivieren" am Lieferanten-Kopf (Service `setInactive` existiert seit Feedback-Commit); Artikel-Suchfeld INNERHALB des gewählten Lieferanten (Ist-App hat beides) | Screen-2-Abgleich 2026-06-11 | Edit-Roundtrip; lokale Suche filtert nur den gewählten Lieferanten | ☑ 2026-06-11 (update [Owner-Gate] + Edit-Modal vorbefüllt + Deaktivieren/Aktivieren-Button [Curate-gated]; lokale Suche `?aq=` [LOWER-designation + ArtNr-Prefix, nur gewählter Lieferant — Test]; Browser-verifiziert) |
| M2-15 | **Deklarationen-Import + Edit (GL-09-Quelle!)** | Import `declarations` (112.605 Zeilen, 18 LMIV-Stoffe, 2-State −/✓ + unbekannt) + Toggles im LA-Modal (read-only Kind) — Blocker für M3-05/GL-09 analog M2-09/GL-01 | Screen-3-Abgleich; GL-09 | Row-Gate; LA zeigt 18 Stoffe; Edit setzt quelle=manual | ☑ 2026-06-11 (Tabelle mit ROHER Necta-Domäne {0,1,3,NULL} [GL-09 §4.1 A1 — MAX-Aggregation in M3 rechnet direkt darauf], Spaltennamen 1:1 §4.2; Import 112.605 Gate ✅; UI ja/nein/unbekannt-Übersetzung, 18 Toggles im Modal [Browser-verifiziert am Screen-3-Artikel], manual-Stempel + D1-Gate; 3 Tests) |
| M2-16 | LA-Feld-Lücken schließen | Import + Modal für die im Slice verworfenen Quell-Spalten: `text` (Zusatztext), `vat` (MwSt %), `origin_country_id`, `organic_control_number`, `is_halal`, `is_gmo_free`, `is_preorder`, `ingredients` (Zutatenliste); EANs + `qty_ordering_per_packaging` im Modal ANZEIGEN; `prices.note` mitimportieren + Preis-Notiz/-Edit im P-6-Block | Screen-3-Abgleich | Felder im Modal == Ist-App-Screen-3-Inventar; Re-Import-Gates grün | ☑ 2026-06-11 (9 Spalten + prices.note; Import-Map erweitert + Backfill-Phase [264.452 Artikel · 62 Notizen, exakt == Quelle]; Modal: Zusatztext/EANs/VPE/MwSt/Ursprungsland/Bio-Nr/Halal/GVO/Zutatenliste; **V-29 Vorbestellzeiten** [Dominique]: 5.340 Artikel real, Pill „Vorbestellung · n T" in Tabelle + Feld im Modal — LOGIK-Verortung als offener Entscheid registriert) |
| M2-17 | **Nährwerte-Import (GL-08-Quelle!)** | Import `nutritional` (127.644 Zeilen: energy_kcal/kj, protein, fat, carbs, … + bls_key) → `foodalchemist_item_nutritionals` — Blocker für M3-04-Nährwert-Ø + Panel-Sektion M3-05, analog M2-09/M2-15 | Screen-1-Abgleich (13_REFERENZ); GL-08 | Row-Gate; LA zeigt Nährwerte je 100 g | ☑ 2026-06-11 (44 BLS-Spalten 1:1 + raw_json; Import 127.644 Gate ✅; Model mit KERNWERTE-Konstante + salzG() [GL-08: sodium × 0.0025]; Relation am Item) |
| M2-13 | **Abnahme M2** | Re-Import komplett, GT-2 Edna, Screenshots vs. Ist-App-Screen 2/3, Leak-Test Lieferanten | 09 | Dominique-Review in Sandbox ✅ | ☑ 2026-06-11 (**an Claude delegiert durch Dominique**. Protokoll: --fresh-Re-Import 14 Phasen/0 Skips/alle 10 Gates ✅ · GT-1: 14 LAs, Ist-Lead 31141191 47,50 € status 2, qty NULL · GT-2: Brotkonfekt WG 09 → Edna als LA-Lieferant + in Stamm-Matrix · Suite 100/100 inkl. Leak-Tests · UI-Klickpfade live: Lieferant anlegen ✓, Artikel anlegen ✓ [Auto-Modal-Öffnen nach Anlage träge — kosmetisch, Klick-Pfad ok], Preis anlegen ✓ EK+Vergleichspreis im Kopf · Screen-2/3-Abgleich mit Dominiques nachgereichten Referenzen → Lücken als M2-14/15/16 + M3-Nachträge erfasst) |

## M3 — Grundprodukte (D-3) — Neubau, ersetzt Slice-Views

| ID | Paket | Inhalt | Ref | DoD | Status |
|---|---|---|---|---|---|
| M3-01 | Browser-Gerüst | `Gps/Browser` mit WG-Baum links (Counts, Sub-Kategorien bei Auswahl), Status-Filter, Suche; URL-Sync `?gp=` — Baum in **linker Page-Sidebar** (P-1-Platzierungs-Entscheid 2026-06-11) | P-1 | Baum-Counts == SQL; Auswahl in URL teilbar | ☑ 2026-06-11 (alle 15 WG-Counts + Sub-Counts == SQL live verifiziert; ?gp/wg/sub/status/q/zeilen in URL; Achtung: 10 GPs mit WG `00` + 4 ohne WG nur über „Alle“ erreichbar — Hygiene-Fall analog V-22) |
| M3-02 | GP-Tabelle | Name · WG · Status · LAs · **Lead-Preis** · **Rezepte** · **Allergen-Badges**; Ist-App-Dichte | P-1; Screen 1 | 7.774 GPs flüssig; Spalten korrekt | ☑ 2026-06-11 (Lead-Preis = Vergleichspreis über activePriceSubquery(lead.id), 79/100 Zeilen mit Preis; Rezepte-Spalte hasTable-Guard bis M4; Allergen-Badges vorerst Override-Layer — Effektivwerte ab M3-04) |
| M3-03 | DetailPanel-Gerüst | `Gps/DetailPanel` (hört auf Auswahl-Event) + Stammdaten-Sektion (Status, gp_key, Hauptzutat, WG, Zustand/Bio, Verarbeitung) — Panel in **rechter Page-Sidebar** (P-1-Platzierungs-Entscheid 2026-06-11) | P-1 | Panel-Wechsel ohne Full-Reload | ☑ 2026-06-11 (eigene LW-Komponente, hört auf gp-selected; rechte Page-Sidebar storeKey=activityOpen — eigene Keys kollidieren im UI-Store; Reload mit ?gp= befüllt Panel sofort; D1-Leak-Test grün; Harness braucht jetzt modules/team_user/usage-Migrationen + checkins-Stub für Full-Page-LW-Tests) |
| M3-04 | GpAggregateService | GL-01 ALL-MAXIMAL (COALESCE Override>LA-MAX, Ränge 3/2/1/0, Konfidenz HIGH/MED/LOW) + Nährwert-Ø je 100 g | **GL-01** | **Pest-Golden GL-01-Cases aus 09 grün** | ☑ 2026-06-11 (GP-auflösbares Teilset: GT-04/06/07 + §4.5-Konfidenz [A1-SOLL] + GL-09/GL-08-GP-Pfade, 10 Golden-Tests; DB-Spotchecks: GP 3672 gluten=enthalten, GP 4534 kcal-Ø 236.0 — beide == Spec; Rezept-Ebene GT-01/02/03/05/08 folgt in M4 mit RecomputeService) |
| M3-05 | Panel: Allergene+Nährwerte | Sektionen Allergene („aggregiert aus LAs n/m"), Zusatzstoffe (LMIV-Pills), Nährwerte-Tabelle — lazy | P-1; GL-01 | Werte == Service-Output; lazy nachgeladen | ☑ 2026-06-11 (toggleSektion rechnet nur offene Sektionen; Aufklapp-Zustand übersteht GP-Wechsel; Tabellen-Badges jetzt Effektivwerte via laMaxRaengeBulk [1 Query/Seite, inkl. Derivat-Mutter]; live verifiziert: Konfidenz-Pill, n/m-LAs, LMIV-Zähler, Big-5 mit GL-08-Rundung) |
| M3-06 | LeadLaService | GL-03 5-Stufen + **V-27-Auflösung**: COALESCE(Team-Override, GP-Lead) − gesperrte LAs, Strategie aus M1-05, Ausweich-Kette | GL-03, V-27 | Pest: GT-1-Lead + Override-Fälle + Sperr-Fall | ☑ 2026-06-11 (LeadLaService: T1-Kaskade als Sortier-Tupel [I3 weich, NULLS LAST PHP-seitig = engine-agnostisch]; V-27-Overlay gp_la_preferences [Sperre/Pin team-scoped, EIN globaler Lead]; 10 Golden-Tests GT-1…7-Essenzen + Strategie/Sperre/Pin; DB-Spotcheck GT-1: GP 6723 → Soll-Lead 29344887 == Spec, Ist-Lead 31141191 = A-2-Abweichung; V-27-Default jetzt stamm_lieferant statt guenstigster_preis [GL-03 §6, M1-05-Korrektur]) |
| M3-07 | Panel: LA-Sektion | Verknüpfte LAs (Lead-Stern, Preis, qty?-Warnung), Aktionen: Lead setzen, **LA sperren** (Override-UI), lösen, verknüpfen. **+Nachtrag (Screen-2-Abgleich):** Lead-★-Spalte auch in der Artikel-Tabelle des Lieferanten-Browsers | P-1; M3-06 | „LA A sperren → B wird Lead" im Browser beweisbar | ☑ 2026-06-11 (Panel-LA-Sektion: Kette mit effektivem ★ + global-★, Stamm/ausgelistet/gepinnt/gesperrt-Marker, qty-⚠; Aktionen Pin/Sperre [team] + Lead setzen/Lösen/Verknüpfen [Kurator, D1-Fehlertext]; Sperr-DoD live + als Pest bewiesen; Lead-★ in Lieferanten-Artikel-Tabelle — Fix: eager-load structure.gp braucht lead_la_supplier_item_id-Spalte) |
| M3-08 | MatchService v1 | exact (artno+supplier, EANs) + fuzzy-Name (GL-04-Schwellen 0.85/0.70/0.50, Prefix-Bonus, 0.45-Cap) für LA-Verknüpfen-Vorschläge | GL-04 | Pest: GL-04-Teilset (exact+fuzzy) grün | ☑ 2026-06-11 (TokenEngine = 1:1-Port von §3.1–3.3 [tokenize/stem/token_matches/match_score+Floor, A-5-Akzente bewusst NICHT gefoldet]; MatchBand §4.1; MatchService v1: exact_ean/exact_artno-Dubletten-Brücke + fuzzy GL-04-Kern; 15 Tests; Realdaten-Probe: Agar-Agar → richtiger GP fuzzy_low in 249ms/7.774 GPs; Voll-Port 96 Golden = M4-09 gegen DIESELBE TokenEngine) |
| M3-09 | GP-Modal | Edit/Neu: Name (GL-12-Slug-Vorschau, §6-Hints), Klassifikation, Pflichtangaben-Warnung (§8 `fehlt_*`). **+Nachtrag (13_REFERENZ):** Naming-Builder mit AUTO-SYNC + Zusatz-Klammer; Tags-Selects mit MIN/MAX-Initial aus LAs; Derivat-§11-Sektion; „✨ KI-Vorschlag"-Kopf-Button (gp_suggest) für NEUE GPs | P-2, GL-12 | Neuanlage validiert; Slug byte-identisch zu GL-12-Referenz | ☑ 2026-06-11 (GpNamingService: slugify kanonisch ä→a [I6, NICHT Str::slug], buildGpKey 3 Slots, render/validate [§7.1-Wort-Boundary, §9+A2-Normalisierung tiefgekuehlt→TK, I4-Drift-Warning], Anlage-Guard gp_key+Jaccard≥0.92 mit force-~n-Suffix [DB-UNIQUE bleibt scharf]; Modal: Naming-Builder AUTO-SYNC + Zusatz-Klammern + Derivat-§11-Sektion + ✨gp.suggest; 9 GL-12-Golden [GT-01…04/09/10] + 7 Modal-Tests; Slug-Paritäts-Scan: 95% Kopf==Slug, Abweichungen = Hauptzutat≠Kopf oder Legacy-Slug-Variante ü→_ im Seed; Live-Anlage verifiziert) |
| M3-10 | KI-Felder | Tags + Zustand mit ki-header-Baustein (Autopilot→Gateway, accept/clear, Konfidenz%) | P-3, GL-07; M0-14 | Fake-Provider-Roundtrip ändert Feld + Lineage | ☑ 2026-06-11 (zustand-Lineage-Trio per Migration 000020 [GL-07-Pattern]; ki-header auf zustand+tags im Modal: ai_/accept_/clear_/manual_ mit Override-First + Confidence-Clamp; Fake-Roundtrips als Tests grün; Prompts gp.suggest/gp.zustand/gp.tags in der Registry) |
| M3-11 | Bulk-Match | M2-Kopf-Button echt: Lauf je Lieferant über MatchService, Ergebnis-Queue (tentative), Review-Liste. **+Nachtrag (Screen-2-Abgleich):** Checkbox-Selektion + Bulk-Leiste in der Artikel-Tabelle (GP zuweisen · Mapping entfernen · Einstellen · Reaktivieren · Löschen — D-2 §4) | GL-04; D-2 | Lauf über 1 Lieferant erzeugt nachvollziehbare Vorschläge | ☑ 2026-06-11 (Queue foodalchemist_match_proposals [Migration 000021, offen/akzeptiert/verworfen]; Bulk über Stem-invertierten Token-Index + EAN/Artno-Brücken-Prefetch [mehrdeutige EANs verworfen]; Übernehmen = verknuepfen + Lead-Neuwahl T3; Review-Liste + Lauf-Statistik im Lieferanten-Browser; Bulk-Leiste mit Checkboxen [GP zuweisen/Mapping entfernen/Einstellen/Reaktivieren/Löschen]; Live: Hanos 121 Non-Food → korrekt 0 Treffer in 840ms, Handelshof → AEPFEL FUJI/RUBENS→Aepfel Fuji fuzzy_low 0.67 [als echte offene Review-Position belassen]; 4 Tests) |
| M3-12 | Aufräumen | Alt-Routen `/gps/{id}` → Redirect `?gp=`; Slice-Index/Show-Views + Komponenten löschen | — | keine toten Routen/Views | ☑ 2026-06-11 (Gps/Index+Show samt Views gelöscht; /gps/liste und /gps/{id} → Redirect auf ?gp= [Kontext-Erhalt]; Redirect live verifiziert; Suite grün) |
| M3-13 | **Abnahme M3** | GT-1 komplett im Browser; GL-01/03/04-Suiten grün; Screenshot-Abgleich Ist-App-Screen 1; Leak-Test | 09 | Dominique-Review ✅ | ☐ |

## M4 — Basisrezepte (D-5)

| ID | Paket | Inhalt | Ref | DoD | Status |
|---|---|---|---|---|---|
| M4-01 | Migrations Rezepte | `recipes` (ein Modell, Scopes basis/verkauf, Aggregat-Spalten, `yield_kg_manual`), `recipe_ingredients`, Eignungs-Satelliten | D-5 §2 | migrate grün | ☐ |
| M4-02 | Import Rezepte | Phasen recipes+ingredients+Taxonomie (1.407/9.590; Lineage!), Row-Gates. **+Nachtrag (13_REFERENZ):** vocab_kochequipment (40) + recipe_equipment (836) mitimportieren — Equipment-Chip-Sektion ist Teil des Editors (M4-05/06) | 07 §3 | Gates grün; Stichprobe BBQ-Sauce vollständig | ☐ |
| M4-03 | RecomputeService | GL-02 (Yield-Auto-Sum mit Verlust-Faktor, EK-Kaskade) + GL-01-Vererbung, topologisch (Sub-Rezepte), Trigger-Konzept | **GL-02**; Skript 206 als Referenz | **Pest-Golden: BBQ Eastern Texas 5,61 €/kg · 0,387 kg** u. w. | ☐ |
| M4-04 | Browser-Gerüst | Hauptgruppen-Baum (M1-04-Daten), Tabelle (Name·Hauptgruppe·Kategorie·Geschmack·Fertigung·Status·Zutaten·Yield·Allergen-Konf) | P-1; Screen 4 | 1.407 Rezepte, Filter funktionieren | ☐ |
| M4-05 | DetailPanel | KPI-Karte (EK/kg·EK·Yield·Konfidenz), Beschreibung, Zutaten read-only mit GP-Links, Eignungs-Chips. **+Nachtrag (13_REFERENZ):** Diät-&-Spezifikations-Sektion (✓-Liste aus GP-Tag-MIN-Aggregation), Zutaten-Zeilen mit Lineage kursiv + EK je Zeile, Verwandte-Rezepte mit Kohäsions-Score (Daten ab M5) | P-1 | Werte == Recompute-Output | ☐ |
| M4-06 | Modal: Stammdaten | Name (§1.2-Syntax-Hint, „Name putzen"-KI), Herkunft, Hauptgruppe/Kategorie, Basisrezept-Flag | P-2; Regelwerk BR §1 | Edit-Roundtrip + Recompute-Trigger | ☐ |
| M4-07 | Zutaten-Editor Kern | P-8: Alpine-first-Tabelle (Menge/Einheit/Hinweis/Garv.%), Zeilen-EK + Summen client-seitig, Sync bei Save | **P-8** | Tippen ohne Server-Roundtrip (Network-Tab-Beweis) | ☐ |
| M4-08 | Zutaten-Editor Komfort | Drag-Sort (sortablejs), Add-Zeile mit GP-Picker + Auto-Fill, optional-Flag, Lineage-Hinweis kursiv | P-8 | Reorder persistiert; Picker findet GPs der Team-Kette | ☐ |
| M4-09 | IngredientMatchService | GL-04 voll für Zutat→GP (alle 96 Golden-Tests) | **GL-04** | 96/96 grün | ☐ |
| M4-10 | Sub-Rezepte | Stub-Anlage (draft), Klammer-Suffix-Versionierung, Guards (max 3 Ebenen, Zyklen), Parents-Anzeige | Regelwerk BR §4; GL-02 §3.5 | Guard-Tests grün; Rekursion im Browser sichtbar | ☐ |
| M4-11 | KI-Anreicherung | Beschreibung (§8-Stil) / Kategorie / Garverlust-Vorschlag via Gateway, je mit ki-header | GL-06/07; 06_KI | 3 Felder mit Fake-Provider end-to-end | ☐ |
| M4-12 | Workflow-Kram | Duplizieren, Als-Template, Status-Workflow (REVIEW→approved), Bulk-Status | D-5 §1 | Aktionen + ActivityLog | ☐ |
| M4-14 | Basisrezept-Generator | ✨-KI-Rezept-Button: Basisrezept aus Beschreibung mit Richtungs-Parametern (Convenience/Niveau/Bio/Aroma/Sektor/Diät-hart) + **Bestand-Nutzung Hybrid** (agentischer Resolver: Bestand zuerst, Neues nur für Lücken); Aus-Foto/PDF/Text blockiert auf Martin-Vision-Frage | 13_REFERENZ Nachlieferung 3; GL-04/06/07/13 | 1 Rezept end-to-end aus Beschreibung (Fake-Provider) | ☐ |
| M4-13 | **Abnahme M4** | Recompute-Golden-Suite, Editor-Roundtrip, Screenshots vs. Screens 4/5, Leak-Test | 09 | Dominique-Review ✅ | ☐ |

## M5 — Wissen & Pairing (D-4 Klasse A + D-7-MVP-Anteil)

| ID | Paket | Inhalt | Ref | DoD | Status |
|---|---|---|---|---|---|
| M5-01 | Wissens-Migrations | `knowledge_*`-Tabellen (Klasse A: Cross_Cutting 33, Domains 36, Regelwerk-Snippets) | D4 in 08 | migrate grün | ☐ |
| M5-02 | knowledge-import | EINBAHN-Command Vault→DB (MD-Parser, Frontmatter, Alias-Tabelle 258) | D4; GL-13 | Import-Gates; Alias-Count 258 | ☐ |
| M5-03 | Pairing-Kanten-Import | 767 Pairing-MDs → Kanten-Tabelle (Parser-Kopplung wie `_oneshot_F_2`); Anker-Graph | D4/D-7 | Kanten-Count == Quell-Parser; Stichproben | ☐ |
| M5-04 | Kern-Anker am Rezept | Chips-Sektion (★, Autopilot via Anker-Extraktion), Verknüpfen-Flow | GL-10; Screen 4 | BBQ-Sauce zeigt 5 Anker wie Ist-App | ☐ |
| M5-05 | Pairing-Sektion | Pairing-Chips (27 bei BBQ-Sauce), Kohäsions-Anzeige, „verwandte Rezepte". **+Nachtrag (13_REFERENZ N3):** Aroma-Nachbarn-Sektion (Foodpairing-Discovery: Modi Klassiker/Signature, % = Ø-Kantenstärke zu getroffenen Komponenten, Allrounder-Label) | GL-10; D-6 §5.x | Werte gegen Ist-App-CLI (`232_query_pairing`) verprobt | ☐ |
| M5-06 | Generator-Grounding | GL-13-Wissenskontext in Prompts (7 Always-Load, Budgets 4000/6000) | **GL-13** | Kontext-Assembly-Test: Budget eingehalten | ☐ |
| M5-07 | Aroma-Netz-Graph | Interaktive Graph-Visualisierung: Quell-Rezept zentral, Anker-Ring, verwandte Rezepte außen; Brücken-Typen klassisch/modern/kontrast (GL-10); Hover = Anker-Brücken, Klick = Rezept öffnen; Toggle Alle-Aroma-Brücken + Vorschlags-Modus je Anker | 13_REFERENZ Nachlieferung 2; GL-10 | Netz für BBQ-Sauce rendert mit echten Kanten | ☐ |

## M6 — VK-Rezepte (D-6)

| ID | Paket | Inhalt | Ref | DoD | Status |
|---|---|---|---|---|---|
| M6-00 | **GATE: D6+D7** | Deckungsbeitrags-Formel + Verlust-Formel mit Dominique entscheiden, in 08 dokumentieren | 08 D6/D7 | Entscheide schriftlich | ☐ |
| M6-01 | Verkaufslayer-Migrations | VK-Spalten (Skript-200-Schema), `speisen_klassen`, `recipe_regenerations` (V-19). **+Nachtrag (13_REFERENZ):** VK-Hauptgruppen-Taxonomie mit 16 Codes [APE]…[GET] + Kategorien je HG | D-6 §2 | migrate grün | ☐ |
| M6-02 | MargeService | VK-Mathematik Single-Source (D6-Formel, Aufschlagsklassen, Portionsfaktoren, MwSt) | D-6 §3.2 | Pest-Kalkulations-Golden | ☐ |
| M6-03 | VK-Browser | VK-Scope-Liste (Marge-Spalten, Klasse, Status) auf master-detail. **+Nachtrag (13_REFERENZ):** Geschmacks-Filter-Pills; Panel mit VERKAUFT-ALS-Box (Stück · g/Stück · Yield), KPI-Karten inkl. WARENEINSATZ % + VK-BRUTTO mit Formel-Klartext | D-6 §4.1 | Liste + Panel mit Marge-KPIs | ☐ |
| M6-04 | VK-Editor | VK-Stammdaten/Klassifikation/Regeneration/Behälter im Modal; Zutaten im Komponenten-Modus (Basisrezepte). **+Nachtrag (13_REFERENZ N3):** VK-Wording-Feld (kanonischer Marketing-Name + ✨; Foodbook-Schreibstile transformieren später); Verkaufseinheit-Logik (Stück/Portion: Anzahl je Rezept ⇒ g/Stück aus Yield; kg/l auto); Rolle-SPALTE im Zutaten-Editor; Plating-&-Service-Markdown (getrennt von Produktion, ✨); Spezifikation (Bio-Anteil % aus Zutaten, Regional, Diät-Verletzungs-Pills); Nährwert-Sektion mit Konfidenz + pro-Stück; Kunden-Bezeichnungen (Phase-3-Stub) | D-6 §4.2-4.3 | VK anlegen aus Basisrezept manuell | ☐ |
| M6-05 | Rollen-Verteilung | `ai_verteile_rollen` (Komponenten-Rollen Hauptkomponente/Beilage/Sauce…) mit Proposal-UI. **+Nachtrag (13_REFERENZ):** Kulinarische-Kohärenz-Check (Score % + Label + Begründung + Modell/Datum + Erneut-prüfen) und Was-hebt-den-Teller-Vorschlag als VK-KI-Features | D-6; GL-07 | Fake-Roundtrip + Accept | ☐ |
| M6-06 | **VK-Generator v1** | Der Pain-Point: Basisrezept→VK automatisiert (Matching + Hüllen + GL-07-Proposal-Flow mit Accept/Reject). **+Nachtrag (13_REFERENZ N3):** Richtungs-Parameter (Convenience/Niveau/Kompositions-Stil/Bio-Präferenz [Default konventionell!]/Aroma/Anlass/Serviceform/Sektor/Diät-HART) + Bestand-Nutzung-Hybrid-Resolver; Aus-Foto/PDF/Text blockiert (Martin-Vision) | §0 Produktvision; GL-04/06/07 | 3 echte Rezepte end-to-end generiert | ☐ |
| M6-07 | VK-Generator v2 | Matching-Reuse-at-Generation, Pool-Normalisierung, Decompounding (Audit-Hebel 2–4) | V-Register | messbare Quote-Verbesserung vs. v1 | ☐ |
| M6-08 | **Abnahme M6** | Kalkulations-Suite, Generator-Review, Leak-Test, Screenshots | 09 | Dominique-Review ✅ | ☐ |

## M7 — KI-Engine Vollausbau (D-4) — parallel ab M3

| ID | Paket | Inhalt | Ref | DoD | Status |
|---|---|---|---|---|---|
| M7-01 | ai_call_log | Audit-Tabelle + Schreiber in Gateway (Modul, Prompt-Key, Tokens, Dauer, Erfolg) | D-4 | jeder Call geloggt | ☐ |
| M7-02 | Tiering | Tier-Klassen A–D abstrakt, Zuordnung je TASK_PROMPT in config (V-01) | 06_KI §2 | Tier wählbar, Default sinnvoll | ☐ |
| M7-03 | Retry-Schutz | Structural-Retry + Degenerations-Erkennung generalisiert (V-02) | 06_KI | Test: kaputte Fake-Antwort → Retry → Erfolg | ☐ |
| M7-04 | Prompt-Registry | Die 42 TASK_PROMPTs aus 06_KI migrieren (Feld-Hüllen modul-eigen) | **06_KI** | Registry vollständig; Keys == Inventar | ☐ |
| M7-05 | Voice-Hüllen | Anbindung core.semantic_layer (`SemanticLayerResolver::resolveFor`) für Ton/Perspektive | D3; GL-06 §6 | Layer-Wechsel ändert Prompt nachweisbar | ☐ |
| M7-06 | Bulk-Queue | „Alles anreichern"/Bulk-Autopilot als Queue-Jobs + Fortschritt (Notifications-Modul) | P-3 Bulk | 50er-Bulk läuft, Fortschritt sichtbar | ☐ |
| M7-07 | Küchen-Profil | Küchen-Kontext (commands.rs:12590-Pendant) als Team-Einstellung in Prompts | D-5 §4.3 | Profil ändert Generator-Output | ☐ |
| M7-08 | KI-Settings | Settings-Sektion: Provider-Status, Tier-Zuordnung, Budget-Anzeige, Kill-Switch | M1-01 | Kill-Switch stoppt Autopilot-Buttons | ☐ |
| M7-09 | Embeddings/RAG | GL-04-RAG + V-24 — **wartet auf Martin** (Embedding-Support Plattform-LLM) | GL-04; D3-Rest | blockiert markieren | ☐ blockiert |
| M7-10 | Voice-Interface | Sprachbedienung als zweiter Bedienweg (UI bleibt parallel): Mikro-Aufnahme (MediaRecorder, Opus mono — Vorbild `platforms-whisper`) → **eigener sync Kurz-Audio-STT** (`SttServiceContract` + `AssemblyAiSttService`, **D8**: kein Fremdmodul-Require, kein Core-Eingriff) → agentischer Tool-Loop (`callWithTools`, Tier D) über M8-01-Tools; Schreibaktionen NUR via GL-07-Proposal-Flow (sprechen → Proposal → bestätigen); nach: M7-04 + erste M8-01-Tools | **D8 in 08**; Dev-Modul Discussion #1; 06_KI §1; GL-07; M8-01; martin3r-me/platforms-whisper | 3 Sprachbefehle end-to-end (Suche, Detail öffnen, Schreib-Proposal mit Accept); Befehls-Latenz gemessen + dokumentiert | ☐ (Deploy braucht `ASSEMBLYAI_API_KEY` — Martin; Sandbox-Bau frei) |

## M8 — Querschnitt (laufend)

| ID | Paket | Inhalt | Ref | DoD | Status |
|---|---|---|---|---|---|
| M8-01 | MCP-Tools | `foodalchemist.gps.GET/SEARCH`, `recipes.GET` … (ToolContract, REST-Verben, Tools→Services); **auch Schreib-Tools** (POST/PUT nur via GL-07-Proposal-Flow, nie Direkt-Write); je Meilenstein mitziehen (nach M2/M3/M4/M6), nicht gesammelt am Ende — Vorleistung für M7-10 Voice | 01 §Tools; GL-07 | Tools im Registry, Smoke via MCP; nach jedem abgeschlossenen Modul sind dessen Tools vorhanden | ☐ |
| M8-02 | Policies + ActivityLog | Policies je Model (curate-Gate M1-08), LogsActivity-Abdeckung prüfen | Plattform-Muster | Policy-Tests grün | ☐ |
| M8-03 | Leak-Suite | Geschwister-Test je Sektion (M2/M3/M4/M6) in CI-Lauf bündeln | D1-Risiko | alle grün | ☐ |
| M8-04 | Performance-Pass | Indizes (gps name/status/WG; items designation; prices item+valid_to), Lazy-Audit, N+1-Check | 02 | Browser-Seiten < 300 ms Server-Zeit (Sandbox) | ☐ |
| M8-05 | Team-Onboarding | Command/UI: Kind-Team anlegen + Modul freischalten + optional Rezept-Startpaket (D2-Snapshots) | D1/D2 | neues Kind-Team sieht Katalog sofort | ☐ |
| M8-06 | Doku-Sync | Nach jedem Modul: D-Spec §4 + 00_INDEX + dieser Roadmap-Status aktualisieren | Kaizen | Status-Spalten aktuell | ☐ |

---

## Offene Entscheide / externe Abhängigkeiten

| Was | Wer | Blockiert |
|---|---|---|
| D6 Deckungsbeitrags-Formel + D7 Verlust-Formel | Dominique | M6 (Gate M6-00) |
| V-08 GP-Allergen-Override-Strategie (Detailgrad) | Dominique | M3-04 Feinheiten (Annahme: Override-Layer wie Spec) |
| Push/Repo-Sichtbarkeit (public + Kern-IP in docs/) | Dominique/Martin | jeden Commit |
| x-ui-modal im Content erlaubt? | Martin | M0-08 (bis dahin Custom-Modal) |
| Embedding-Support Plattform-LLM, Vision, Team-Rate-Limits | Martin | M7-09 |
| `ASSEMBLYAI_API_KEY` auf office/demo vorhanden/teilbar? + Heads-up: foodalchemist bindet AssemblyAI direkt an (STT-Weg selbst entschieden: **D8** = eigener sync Kurz-Audio-Pfad im Modul, Präzedenz `platforms-whisper`) | Martin | nur M7-10-**Deploy** (Sandbox-Bau frei) |
| Dark-Mode-Strategie Shell (`.dark`-Klasse) | Martin | kosmetisch |
| Core-Fixes (undeklarierte Deps, MySQL-only-Migrationen, Index-Kollision) | Martin | Sandbox-Komfort |
| Paritäts-Suite-Engine: Testkatalog §1 sagt Postgres, Prod-DB ist MySQL (gemeinsame Plattform-DB, Martin-Info 2026-06-11 → 07 §7) | Martin | GL-03-Tests (M3-06); bis dahin NULL-Sortierung engine-agnostisch |
| V-29 Vorbestellzeiten-LOGIK: Verortung (M4-Rezept-Editor-Warnung vs. eigenes Bestell-Modul) — Felder/Anzeige sind seit M2-16 da | Dominique | Logik-Einbau (Felder unkritisch) |
