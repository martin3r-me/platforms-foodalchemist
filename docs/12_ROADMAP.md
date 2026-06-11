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
| M0-14 | KI-Gateway-Basis | `AiGatewayService` (Fassade, Transport → Core `LLMProviderContract`), `FakeAiProvider` (deterministisch, Sandbox ohne Key), `config: prompts`-Skeleton | D3/D-4, GL-06 | Fake-Roundtrip-Test grün; echter Provider per config wählbar | ☐ |

## M1 — Einstellungen & Vokabulare (D-1)

| ID | Paket | Inhalt | Ref | DoD | Status |
|---|---|---|---|---|---|
| M1-01 | Settings-Gerüst | Route `/einstellungen`, Sidebar-Gruppe „Einstellungen", Sektions-Navigation (vertikale Tabs), nur Katalog-Besitzer sieht Edit | D-1 §4 | Seite rendert, Read-only für Kind-Team | ☐ |
| M1-02 | Einheiten-Verwaltung | CRUD `vocab_einheiten` + Stück-Default-Gewichte (Ist-App „Einheiten verwalten"); Lineage bei Änderung | D-1, GL-05 | Einheit anlegen/ändern wirkt im GP-Detail | ☐ |
| M1-03 | Warengruppen & Sub-Kategorien | WG read-mostly (§3-Codes fix!), Sub-Kategorien CRUD mit Regelwerk-Hinweisen | Regelwerk GP §3 | §3-Codes nicht löschbar; Sub-Kat CRUD ok | ☐ |
| M1-04 | Rezept-Taxonomie | Hauptgruppen (30) + Kategorien (139) CRUD + Sortierung; Quelle Skript 204-Stand | D-1, Regelwerk BR §1 | Browser-Bäume (M4) lesen daraus | ☐ |
| M1-05 | **Lead-LA-Strategie** | Team-Einstellung: `guenstigster_preis` \| `stamm_lieferant` \| Prioritäts-Kette; + „Ausweich-Kette anzeigen"-Toggle. Speist LeadLaService (M3-06) | V-27; D1-Overlay | Einstellung ändert Lead-Wahl nachweisbar (Test) | ☐ |
| M1-06 | Stamm-Lieferanten-Matrix | Import `stamm_lieferant`/`stamm_lieferant_wg` (Vault-Skript 212) + Pflege-UI (Lieferant×WG-Grid) | GL-03/V-27 | Matrix editierbar, von M3-06 gelesen | ☐ |
| M1-07 | Kalkulations-Defaults | Garverlust-Defaults je GP-Klasse, MwSt-Defaults, Rundungsregeln — eine Settings-Sektion | GL-02 | Defaults greifen im Rezept-Editor (M4) | ☐ |
| M1-08 | Katalog-Pflege-Gate | Policy-Helper `canCurate(User,Team)` (Owner-Team-Mitglied), zentral genutzt von allen Edit-UIs | D1 | Leak-Test: Kind-User sieht keine Edit-Buttons | ☐ |

## M2 — Lieferanten (D-2)

| ID | Paket | Inhalt | Ref | DoD | Status |
|---|---|---|---|---|---|
| M2-01 | Browser-Gerüst | `Suppliers/Browser`: master-detail-Baustein, Lieferanten-Liste links („n Artikel · m gemapped" grün), Suche, Inaktive-Toggle | P-7; D-2 §4 | Liste mit echten Counts (SQL-verifiziert) | ☐ |
| M2-02 | Artikel-Tabelle | Mitte: ArtNr · Bezeichnung · Gebinde · Status · EK · GP-Mapping-Link; Pagination; „Nur aktive" | P-7 | 17.879 BOS-Artikel flüssig blätterbar | ☐ |
| M2-03 | Übergreifende Suche | Artikel-Suche über alle Lieferanten (Feld oben links, Ist-App), Treffer-Liste mit Lieferant-Spalte | P-7 | Suche „Limettensaft" findet die GT-1-LAs | ☐ |
| M2-04 | PriceService | Aktiv-Preis-Regel aus `GpService::lasForGp()` extrahieren (eine Stelle!), Historie-Query, „neuer Preis schließt alten" | GL-03/GL-11 | Pest: Aktiv-Preis-Golden (GT-1 47,50 € status 2) | ☐ |
| M2-05 | Vergleichspreis | Normalisierung €/kg-€/l-€/Stk aus qty+Einheit; Spalte in Tabelle + Modal-Kopf | D-2 §3 | Stichproben gegen Ist-App-Werte (0.81 €/kg Golden Delicious) | ☐ |
| M2-06 | ItemModal lesend | modal-Baustein: Sektionen Stammdaten/Verpackung/Eigenschaften/Preise — erst read-only komplett | P-2/P-6 | GT-1-Artikel zeigt alle Felder + Preis-Historie | ☐ |
| M2-07 | ItemModal Edit | Edit Stammdaten+Verpackung+Eigenschaften (nur Besitzer-Team), Validierung, LogsActivity | P-2; M1-08 | Edit-Roundtrip; Kind-Team read-only | ☐ |
| M2-08 | Preis-Edit | „+ Neuer Preis" (P-6, schließt Vorgänger), Preis löschen, Kategorie-Pill | P-6; M2-04 | Historie konsistent nach 3 Operationen | ☐ |
| M2-09 | **LA-Allergen-Import** | Import-Phase `allergens` (wawi `allergens`/`v_allergen_named` → Zielform laut 02_DATENMODELL) — **GL-01-Blocker!** | GL-01; 07 §3 | Row-Gate; GT-1-LA zeigt 14 Werte | ☐ |
| M2-10 | Allergen-Anzeige+Edit | P-4 tri-state im Modal (read-only für Kind), 14 EU + Lineage | P-4, GL-01 | Edit ändert Aggregation (sichtbar ab M3-05) | ☐ |
| M2-11 | Artikel-CRUD | „+ Neuer Artikel" (Minimal-Pflichtfelder), Deaktivieren (soft), eigener Artikel im Kind-Team möglich! | D-2 §4; D1 | Kind-Team legt eigenen Artikel an, Eltern sehen ihn NICHT | ☐ |
| M2-12 | Preis-Anomalien | Report-Modal: Sprünge >x % zwischen Preis-Generationen, Ausreißer je WG | V-Register | Liste mit echten Treffern aus Bestand | ☐ |
| M2-13 | **Abnahme M2** | Re-Import komplett, GT-2 Edna, Screenshots vs. Ist-App-Screen 2/3, Leak-Test Lieferanten | 09 | Dominique-Review in Sandbox ✅ | ☐ |

## M3 — Grundprodukte (D-3) — Neubau, ersetzt Slice-Views

| ID | Paket | Inhalt | Ref | DoD | Status |
|---|---|---|---|---|---|
| M3-01 | Browser-Gerüst | `Gps/Browser` mit WG-Baum links (Counts, Sub-Kategorien bei Auswahl), Status-Filter, Suche; URL-Sync `?gp=` — Baum in **linker Page-Sidebar** (P-1-Platzierungs-Entscheid 2026-06-11) | P-1 | Baum-Counts == SQL; Auswahl in URL teilbar | ☐ |
| M3-02 | GP-Tabelle | Name · WG · Status · LAs · **Lead-Preis** · **Rezepte** · **Allergen-Badges**; Ist-App-Dichte | P-1; Screen 1 | 7.774 GPs flüssig; Spalten korrekt | ☐ |
| M3-03 | DetailPanel-Gerüst | `Gps/DetailPanel` (hört auf Auswahl-Event) + Stammdaten-Sektion (Status, gp_key, Hauptzutat, WG, Zustand/Bio, Verarbeitung) — Panel in **rechter Page-Sidebar** (P-1-Platzierungs-Entscheid 2026-06-11) | P-1 | Panel-Wechsel ohne Full-Reload | ☐ |
| M3-04 | GpAggregateService | GL-01 ALL-MAXIMAL (COALESCE Override>LA-MAX, Ränge 3/2/1/0, Konfidenz HIGH/MED/LOW) + Nährwert-Ø je 100 g | **GL-01** | **Pest-Golden GL-01-Cases aus 09 grün** | ☐ |
| M3-05 | Panel: Allergene+Nährwerte | Sektionen Allergene („aggregiert aus LAs n/m"), Zusatzstoffe (LMIV-Pills), Nährwerte-Tabelle — lazy | P-1; GL-01 | Werte == Service-Output; lazy nachgeladen | ☐ |
| M3-06 | LeadLaService | GL-03 5-Stufen + **V-27-Auflösung**: COALESCE(Team-Override, GP-Lead) − gesperrte LAs, Strategie aus M1-05, Ausweich-Kette | GL-03, V-27 | Pest: GT-1-Lead + Override-Fälle + Sperr-Fall | ☐ |
| M3-07 | Panel: LA-Sektion | Verknüpfte LAs (Lead-Stern, Preis, qty?-Warnung), Aktionen: Lead setzen, **LA sperren** (Override-UI), lösen, verknüpfen | P-1; M3-06 | „LA A sperren → B wird Lead" im Browser beweisbar | ☐ |
| M3-08 | MatchService v1 | exact (artno+supplier, EANs) + fuzzy-Name (GL-04-Schwellen 0.85/0.70/0.50, Prefix-Bonus, 0.45-Cap) für LA-Verknüpfen-Vorschläge | GL-04 | Pest: GL-04-Teilset (exact+fuzzy) grün | ☐ |
| M3-09 | GP-Modal | Edit/Neu: Name (GL-12-Slug-Vorschau, §6-Hints), Klassifikation, Pflichtangaben-Warnung (§8 `fehlt_*`) | P-2, GL-12 | Neuanlage validiert; Slug byte-identisch zu GL-12-Referenz | ☐ |
| M3-10 | KI-Felder | Tags + Zustand mit ki-header-Baustein (Autopilot→Gateway, accept/clear, Konfidenz%) | P-3, GL-07; M0-14 | Fake-Provider-Roundtrip ändert Feld + Lineage | ☐ |
| M3-11 | Bulk-Match | M2-Kopf-Button echt: Lauf je Lieferant über MatchService, Ergebnis-Queue (tentative), Review-Liste | GL-04; D-2 | Lauf über 1 Lieferant erzeugt nachvollziehbare Vorschläge | ☐ |
| M3-12 | Aufräumen | Alt-Routen `/gps/{id}` → Redirect `?gp=`; Slice-Index/Show-Views + Komponenten löschen | — | keine toten Routen/Views | ☐ |
| M3-13 | **Abnahme M3** | GT-1 komplett im Browser; GL-01/03/04-Suiten grün; Screenshot-Abgleich Ist-App-Screen 1; Leak-Test | 09 | Dominique-Review ✅ | ☐ |

## M4 — Basisrezepte (D-5)

| ID | Paket | Inhalt | Ref | DoD | Status |
|---|---|---|---|---|---|
| M4-01 | Migrations Rezepte | `recipes` (ein Modell, Scopes basis/verkauf, Aggregat-Spalten, `yield_kg_manual`), `recipe_ingredients`, Eignungs-Satelliten | D-5 §2 | migrate grün | ☐ |
| M4-02 | Import Rezepte | Phasen recipes+ingredients+Taxonomie (1.407/9.590; Lineage!), Row-Gates | 07 §3 | Gates grün; Stichprobe BBQ-Sauce vollständig | ☐ |
| M4-03 | RecomputeService | GL-02 (Yield-Auto-Sum mit Verlust-Faktor, EK-Kaskade) + GL-01-Vererbung, topologisch (Sub-Rezepte), Trigger-Konzept | **GL-02**; Skript 206 als Referenz | **Pest-Golden: BBQ Eastern Texas 5,61 €/kg · 0,387 kg** u. w. | ☐ |
| M4-04 | Browser-Gerüst | Hauptgruppen-Baum (M1-04-Daten), Tabelle (Name·Hauptgruppe·Kategorie·Geschmack·Fertigung·Status·Zutaten·Yield·Allergen-Konf) | P-1; Screen 4 | 1.407 Rezepte, Filter funktionieren | ☐ |
| M4-05 | DetailPanel | KPI-Karte (EK/kg·EK·Yield·Konfidenz), Beschreibung, Zutaten read-only mit GP-Links, Eignungs-Chips | P-1 | Werte == Recompute-Output | ☐ |
| M4-06 | Modal: Stammdaten | Name (§1.2-Syntax-Hint, „Name putzen"-KI), Herkunft, Hauptgruppe/Kategorie, Basisrezept-Flag | P-2; Regelwerk BR §1 | Edit-Roundtrip + Recompute-Trigger | ☐ |
| M4-07 | Zutaten-Editor Kern | P-8: Alpine-first-Tabelle (Menge/Einheit/Hinweis/Garv.%), Zeilen-EK + Summen client-seitig, Sync bei Save | **P-8** | Tippen ohne Server-Roundtrip (Network-Tab-Beweis) | ☐ |
| M4-08 | Zutaten-Editor Komfort | Drag-Sort (sortablejs), Add-Zeile mit GP-Picker + Auto-Fill, optional-Flag, Lineage-Hinweis kursiv | P-8 | Reorder persistiert; Picker findet GPs der Team-Kette | ☐ |
| M4-09 | IngredientMatchService | GL-04 voll für Zutat→GP (alle 96 Golden-Tests) | **GL-04** | 96/96 grün | ☐ |
| M4-10 | Sub-Rezepte | Stub-Anlage (draft), Klammer-Suffix-Versionierung, Guards (max 3 Ebenen, Zyklen), Parents-Anzeige | Regelwerk BR §4; GL-02 §3.5 | Guard-Tests grün; Rekursion im Browser sichtbar | ☐ |
| M4-11 | KI-Anreicherung | Beschreibung (§8-Stil) / Kategorie / Garverlust-Vorschlag via Gateway, je mit ki-header | GL-06/07; 06_KI | 3 Felder mit Fake-Provider end-to-end | ☐ |
| M4-12 | Workflow-Kram | Duplizieren, Als-Template, Status-Workflow (REVIEW→approved), Bulk-Status | D-5 §1 | Aktionen + ActivityLog | ☐ |
| M4-13 | **Abnahme M4** | Recompute-Golden-Suite, Editor-Roundtrip, Screenshots vs. Screens 4/5, Leak-Test | 09 | Dominique-Review ✅ | ☐ |

## M5 — Wissen & Pairing (D-4 Klasse A + D-7-MVP-Anteil)

| ID | Paket | Inhalt | Ref | DoD | Status |
|---|---|---|---|---|---|
| M5-01 | Wissens-Migrations | `knowledge_*`-Tabellen (Klasse A: Cross_Cutting 33, Domains 36, Regelwerk-Snippets) | D4 in 08 | migrate grün | ☐ |
| M5-02 | knowledge-import | EINBAHN-Command Vault→DB (MD-Parser, Frontmatter, Alias-Tabelle 258) | D4; GL-13 | Import-Gates; Alias-Count 258 | ☐ |
| M5-03 | Pairing-Kanten-Import | 767 Pairing-MDs → Kanten-Tabelle (Parser-Kopplung wie `_oneshot_F_2`); Anker-Graph | D4/D-7 | Kanten-Count == Quell-Parser; Stichproben | ☐ |
| M5-04 | Kern-Anker am Rezept | Chips-Sektion (★, Autopilot via Anker-Extraktion), Verknüpfen-Flow | GL-10; Screen 4 | BBQ-Sauce zeigt 5 Anker wie Ist-App | ☐ |
| M5-05 | Pairing-Sektion | Pairing-Chips (27 bei BBQ-Sauce), Kohäsions-Anzeige, „verwandte Rezepte" | GL-10; D-6 §5.x | Werte gegen Ist-App-CLI (`232_query_pairing`) verprobt | ☐ |
| M5-06 | Generator-Grounding | GL-13-Wissenskontext in Prompts (7 Always-Load, Budgets 4000/6000) | **GL-13** | Kontext-Assembly-Test: Budget eingehalten | ☐ |

## M6 — VK-Rezepte (D-6)

| ID | Paket | Inhalt | Ref | DoD | Status |
|---|---|---|---|---|---|
| M6-00 | **GATE: D6+D7** | Deckungsbeitrags-Formel + Verlust-Formel mit Dominique entscheiden, in 08 dokumentieren | 08 D6/D7 | Entscheide schriftlich | ☐ |
| M6-01 | Verkaufslayer-Migrations | VK-Spalten (Skript-200-Schema), `speisen_klassen`, `recipe_regenerations` (V-19) | D-6 §2 | migrate grün | ☐ |
| M6-02 | MargeService | VK-Mathematik Single-Source (D6-Formel, Aufschlagsklassen, Portionsfaktoren, MwSt) | D-6 §3.2 | Pest-Kalkulations-Golden | ☐ |
| M6-03 | VK-Browser | VK-Scope-Liste (Marge-Spalten, Klasse, Status) auf master-detail | D-6 §4.1 | Liste + Panel mit Marge-KPIs | ☐ |
| M6-04 | VK-Editor | VK-Stammdaten/Klassifikation/Regeneration/Behälter im Modal; Zutaten im Komponenten-Modus (Basisrezepte) | D-6 §4.2-4.3 | VK anlegen aus Basisrezept manuell | ☐ |
| M6-05 | Rollen-Verteilung | `ai_verteile_rollen` (Komponenten-Rollen Hauptkomponente/Beilage/Sauce…) mit Proposal-UI | D-6; GL-07 | Fake-Roundtrip + Accept | ☐ |
| M6-06 | **VK-Generator v1** | Der Pain-Point: Basisrezept→VK automatisiert (Matching + Hüllen + GL-07-Proposal-Flow mit Accept/Reject) | §0 Produktvision; GL-04/06/07 | 3 echte Rezepte end-to-end generiert | ☐ |
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

## M8 — Querschnitt (laufend)

| ID | Paket | Inhalt | Ref | DoD | Status |
|---|---|---|---|---|---|
| M8-01 | MCP-Tools | `foodalchemist.gps.GET/SEARCH`, `recipes.GET` … (ToolContract, REST-Verben, Tools→Services) | 01 §Tools | Tools im Registry, Smoke via MCP | ☐ |
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
| Dark-Mode-Strategie Shell (`.dark`-Klasse) | Martin | kosmetisch |
| Core-Fixes (undeklarierte Deps, MySQL-only-Migrationen, Index-Kollision) | Martin | Sandbox-Komfort |
| Paritäts-Suite-Engine: Testkatalog §1 sagt Postgres, Prod-DB ist MySQL (gemeinsame Plattform-DB, Martin-Info 2026-06-11 → 07 §7) | Martin | GL-03-Tests (M3-06); bis dahin NULL-Sortierung engine-agnostisch |
