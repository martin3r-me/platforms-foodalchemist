# Produktionsaufträge (Ableger von Spec 17)

> **ROADMAP-Bezug:** N-Track-Geschwister von [Spec 17](17_Bestellwesen_MiniWaWi.md) — dieselbe bewusste Ausnahme vom 2026-07-04-Non-Goal „Produktion, Einkauf, Lager… nicht bauen" (`docs/GOALS.md:85`, `docs/ROADMAP.md:785`), diesmal für die Küchen-Ausführungsebene statt den Einkauf. Dominique-Wunsch 2026-07-22.
> **Ziel:** Aus einer geplanten Produktion (Datum + Ziele) einen **verbindlichen Produktionsauftrag** machen — mit Produktionsdatum, Status-Lebenszyklus und zwei Ausgängen: einem küchen-tauglichen **Produktionsschein** (Zubereitung/Ansätze/Zutaten) und einer **Einbahn-Übergabe** an die Bestellschiene (Spec 17). Die bisherigen Planungs-Blätter (R7.1, read-only) rechneten dasselbe nur — Spec 18 macht daraus einen dauerhaften Beleg (stateful), analog zu dem, was Spec 17 für den Einkauf getan hat.
> **UI-Entscheidung:** kein Tab-Anhängsel an eine bestehende Komponente, sondern ein **vollwertiges Modul-Interface** wie Concepter/Gerichte/Basisrezepte — Browser-Liste + Cockpit-DetailPanel + Editor-Modal (Stammdaten/Ziele/Vorschau-Karteien).
> **Reifegrad: ✅ S0–S3 KOMPLETT 2026-07-22.** S0 `eac1cd5` (Datenmodell + Service) · S1 `99e2393` (Browser/DetailPanel/Editor + Nav, absorbiert `/blaetter`) · S2 `228a398` (4 MCP-Tools) · S3 `c2c25c7` (Produktionsschein-Export). `ProductionOrderServiceTest` 14/14 (2 harness-bedingt geskippt, siehe Spec 17-Präzedenz). Browser-verifiziert gegen echte Rezept-/GP-Daten (Sandbox-MySQL) — Editor→Speichern→Cockpit→Bestellung übergeben→Status „in Arbeit"-Freeze, alles end-to-end geprüft. **Offen nur:** demo-Deploy.

---

## 0. Code-Kartierung (verifiziert 2026-07-22)

**Bedarfs-Explosion ist da (reuse, unverändert):** `PlanungsblattService::produktionsblatt()`/`explodiere()`/`topsAus()` — VK-Gerichte skalieren linear, Basisrezepte runden auf ganze Ansätze. **Einzige Ergänzung:** `produktionsblattFuerZiele(team, ziele[])` (~15 Zeilen) — ruft `topsAus()`/`explodiere()` mit einer Liste statt einem Ziel auf (beide waren bereits generisch über N Ziele, `einkaufsliste()` bewies das) und liefert die `production[]`-Form. Kein neuer Rechenpfad.

**Status-/Snapshot-/Guard-Muster ist da (reuse aus Spec 17):** `OrderStatus`-Enum (`darfWechselnZu`, `istOffen`) → 1:1 gespiegelt als `ProductionOrderStatus`. `OrderService`-Struktur (`draftForX` mit Lock-Guard, `recomputeOrder` mit Snapshot-Freeze, `ownedOrder`/`ownedOpenX`-Guards) → Vorlage für `ProductionOrderService`.

**Handover-Mechanismus ist da (reuse, unverändert):** `OrderService::addNeedFromTarget()` — DetailPanel ruft ihn je Ziel des Produktionsauftrags mit `source_ref = "produktion:{orderId}:{original_source_ref}"` auf (idempotent, E10-Prinzip).

**ALLES NEU (greenfield):** `foodalchemist_production_orders` + `foodalchemist_production_order_lines`, `ProductionOrderStatus`, `ProductionOrderService`, Livewire `Produktion\Browser`/`DetailPanel`/`Editor`, `production_orders.*` MCP-Tools, `dokumente/produktionsauftrag.blade.php`.

---

## 1. Festgezurrte Entscheidungen (2026-07-22)

| # | Frage | Entscheid | Begründung |
|---|---|---|---|
| P1 | Gruppierungs-Schlüssel | **Ein Auftrag je (team, production_date).** Mehrere Ziele desselben Tages aggregieren in EINEM Auftrag. | Notwendig für korrekte Rundung, nicht nur Komfort: Sub-Rezept-Ansätze runden auf (`ceil`), und `ceil(a)+ceil(b) ≠ ceil(a+b)` — zwei Ziele mit je <1 Ansatz derselben Zutat müssen GEMEINSAM gerundet werden. Flaggschiff-Test beweist das (zwei VK-Gerichte teilen sich eine Sauce, einzeln ceil=1+1=2, gemeinsam ceil=1). |
| P2 | Wie werden Ziele geändert? | **Volle Neu-Explosion bei jeder Änderung** — Zeilen komplett löschen+neu anlegen (nie additiv patchen), manuelle Notizen vor dem Löschen per `recipe_id` gesichert und wiederhergestellt. | Direkte Konsequenz aus P1: additives Patchen einzelner Zeilen kann die Nicht-Additivitäts-Korrektheit nicht erhalten. |
| P3 | Merge mit Bestellwesen? | **Explizit geprüft und verworfen.** Zwei getrennte, aber tief verlinkte Entitäten. | Unterschiedliche Gruppierungs-Schlüssel (Tag vs. Lieferant) und Lebenszyklen (Küchen-Ausführung vs. Lieferanten-Beleg); ein Merge hätte Spec 17 (bereits fertig+live) unnötig umgebaut statt wiederverwendet. |
| P4 | Richtung Produktion ↔ Bestellung | **Einbahn-Fluss, expliziter Klick.** Produktion ist der Planungs-Einstieg; „An Bestellung übergeben" ruft `OrderService::addNeedFromTarget()` je Ziel auf. Kein Auto-Sync, kein Rückkanal (Bestellung ändert nie die Produktion). Wiederholbar (idempotent über `source_ref`), da Produktionsdatum und Liefer-Vorlauf/Bestellschluss entkoppelte Zeitachsen sind. | Vermeidet Vermischung zweier unabhängiger Zeitpläne; Nutzer entscheidet bewusst, wann Bedarf bestellbar wird. |
| P5 | UI-Struktur | **Browser+DetailPanel+Editor-Modal** wie Concepter/Gerichte/Basisrezepte, kein Tab an Blaetter/Orders. Editor arbeitet lokal (Ziele als Livewire-Array, `PlanungsblattService::produktionsblattFuerZiele()` direkt für Live-Vorschau) — kein DB-Schreiben während der Eingabe; Speichern persistiert in einem Rutsch. | User-Vorgabe: „komplettes Interface". Lokale Vorschau vermeidet Draft-Zeilen-Leichen bei abgebrochener Eingabe. |
| P6 | Ablösung Planungs-Blätter | **`/blaetter` → Redirect auf `/produktion`**, `Blaetter\Index` + Blade gelöscht (keine tote UI neben ihrem Ersatz). Die 3 read-only MCP-Tools (`produktionsblatt`/`bestellvorschlag`/`einkaufsliste.GET`) bleiben unverändert — agentseitig weiter nützlich, UI-unabhängig. | „Keine toten Deep-Links" (Precedent `/kalkulator`); Agent-Tools sind ein separates, weiterhin gültiges Interface. |

## 2. Datenmodell

**`foodalchemist_production_orders`** — `team_id`, `production_date` (date, Pflicht), `status` (planned\|in_progress\|done\|cancelled), `reference` (Anlass), `targets` (JSON: `[{source_ref, concept_id|recipe_id, persons|portions, label}]`), `warnungen` (JSON-Cache), `note`, `started_at`/`finished_at`/`cancelled_at`, `created_by`, uuid, timestamps, soft-deletes. Kein partieller Unique-Index (SQLite-Portabilität, Präzedenz R0.2/Spec 17) — Guard läuft über Transaktion+Lock in `draftForDate()`.

**`foodalchemist_production_order_lines`** — eine Zeile PRO REZEPT (nicht pro Ziel): `recipe_id`, `is_basisrezept`, `tiefe`, `ansaetze`, `benoetigt_ansaetze` (Rohbedarf vor Rundung), `portionen`, `basis_yield_kg`, `produzierte_menge_kg`, `arbeitszeit_min`, `zubereitung`/`darreichung`/`zutaten` (Snapshots), `note` (manuell, übersteht Recompute), `position`.

Snapshot-Felder frieren beim Übergang `planned → in_progress` ein; solange `planned`, werden sie bei jedem Recompute aufgefrischt.

## 3. Service — `ProductionOrderService`

`draftForDate` (Lock-Guard) · `saveNew`/`replaceTargets` (Editor-Speichern, ein Rutsch) · `addTarget`/`removeTarget` (granular, MCP/Agent) · `recomputeOrder` (Kern: volle Neu-Explosion) · `updateLine` (nur Notiz) · `setStatus` (guarded, friert bei `in_progress`) · `listForTeam`/`detail`/`dokument`.

## 4. MCP-Tools (Lockstep mit S0)

`production_orders.GET` (read-only) · `.ADD_TARGET` (legt Auftrag für `production_date` an + fügt Ziel hinzu) · `.SET_STATUS` (Guard) · `.UPDATE_LINE` (nur Notiz — Ansätze sind abgeleitet, anders als Bestellzeilen-Mengen bewusst nicht manuell überschreibbar).

## 5. Reuse-vs-Neu

| Reuse | Neu |
|---|---|
| `PlanungsblattService` (`explodiere`/`topsAus`, nur um `produktionsblattFuerZiele()` ergänzt), `OrderService::addNeedFromTarget` (Handover), `OrderStatus`/`OrderService`-Struktur als Vorlage, `x-ui-page`/`section`/`panel-section`/`modal`/`modal-section`-Bausteine (`Ui.php`), Recipes/Concepter-Browser+DetailPanel+Editor-Struktur als UI-Vorlage | `foodalchemist_production_orders`+`_lines`, `ProductionOrderStatus`, `ProductionOrderService`, `Produktion\Browser`/`DetailPanel`/`Editor` + Blades, `production_orders.*` MCP, `dokumente/produktionsauftrag.blade.php` |

## 6. Bewusste Nicht-Ziele (v1)

- Stationen-/Geräte-/Personal-Zuweisung (nur in archiviertem Masterplan erwähnt, nicht Teil dieser Anfrage).
- Mehrtages-Produktionszeiträume (ein Auftrag = ein Tag).
- Ist-vs-Plan-Ausbeute-Tracking (kein Wareneingang/Bestand, konsistent mit Spec 17 E4).
- Automatischer Sync zu/von Bestellwesen (P4 — bewusst nur expliziter Klick).

*Verzahnt: [17](17_Bestellwesen_MiniWaWi.md) (Handover-Ziel), R7.1/Planungs-Blätter (abgelöst, PlanungsblattService bleibt Rechenkern). Dossier + Bau 2026-07-22 in einer Session. Nächster Schritt: demo-Deploy.*
