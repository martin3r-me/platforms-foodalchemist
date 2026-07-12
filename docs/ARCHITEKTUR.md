# Food Alchemist — Architektur

> **Was dieses Dokument ist:** die **System- & Datenarchitektur** des FA-Moduls — *wie* es gebaut ist.
> Stand 2026-07-12. Ergänzt: [`VISION.md`](VISION.md) (*Warum/Wohin*) · [`ROADMAP.md`](ROADMAP.md) (*Wie/Wann*).
> **Verbindliche Domänen-Regeln** (Naming/Struktur/Mapping) = die Regelwerke (GP v3.3.2, Basisrezepte v1.1,
> Lieferantenartikel v1.0) in `07_WISSEN` — bei Konflikt gewinnt das Regelwerk.
>
> Löst die alte `00_SYSTEM/00.07_App/_ARCHITEKTUR_SPEC.md` (Cooking-Jarvis/WaWi/Tauri-Ära, Mai 2026) ab.

---

## 1. Laufzeit-Architektur — EINE SQL-Wahrheit

- **FA ist ein Laravel-Modul** (`Platform\FoodAlchemist`) in der Platform-Shell, kein eigenständiges Deployment.
- **Eine MySQL = Wahrheit + Laufzeit + Rechenbasis.** Dev = lokales MySQL 8.4; demo/prod = Forge-MySQL. ~120 Tabellen mit Präfix `foodalchemist_`.
- **Kein Graph, kein Polyglot/3-DB.** Frühere Neo4j/SPARQL-Entwürfe sind verworfen. Mehr-Hop/Vererbung = MySQL `WITH RECURSIVE`; Aroma-Nähe = vorberechnete `pairing_computed`-Scores in SQL.
- **SQLite** nur noch als **In-Memory-Test-Harness** (Pest). ⚠️ Fängt MySQL-only-Bugs NICHT (Rename-Drift, `||`-Semantik, 64-Zeichen-Index-Limit, Strict-Mode) → solche Fixes per MySQL-Smoke prüfen.
- **WaWi-SQLite (`wawi_1494.sqlite`)** = eingefrorenes Read-only-Archiv (Daten-Herkunft), nicht mehr Master.
- **Cooking-Jarvis-Tauri-App = eingefroren.** Produkt ist das FA-Modul.

## 2. Code-Topologie

- **Code-Wahrheit (git):** `platform/modules/platforms-foodalchemist` — `src/` (`Models` 67, `Livewire` 56, `Services` 60, `Tools` 43 = MCP, `Enums`, `Jobs`, `Console`, `Policies`, `Support`), `database/migrations` (~100), `docs/`, `resources/views` (Blade).
- **Host/Test:** `sandbox-food-alchemist` bindet das Modul per **composer path-repo Symlink** ein → Edits sofort live. `.env` → lokales MySQL.
- **Deploy:** demo zieht `main`; Server-Schritte (`composer update` + `migrate --force` + `import-master`) = Martin (Roadmap R0.1).

## 3. Fachliche Produkt-Hierarchie

```
Konzept / Event            (Concepter: Anlass, Servierform, Facetten)
  └─ Paket                 (Baustein-Bündel)
       └─ VK-Gericht        (Verkaufsrezept, ist_sales_recipe=1)  ── Darreichungen (Servierformen, EK/VK je Form)
            └─ Basisrezept  (Eigenproduktion: Sauce/Fond/Püree)   ── max. 3 Sub-Ebenen
                 └─ Grundprodukt (GP, abstrakt)
                      └─ Lieferantenartikel (LA, konkret + Preis)
                           └─ Lieferant
```
- **Rezept-Zutat:** `gp_id` **XOR** `referenced_recipe_id` (Service-erzwungen). Jede Mutation triggert Recompute + Propagation.
- **Allergene/Zusatzstoffe** vererben 1→5 nach oben (ALL-MAXIMAL, „schwächstes Glied" rekursiv, kein false-confident — Regelwerk §7).

## 4. Zwei orthogonale Klassifikations-Achsen (beide gebaut)

- **Achse A — „Was ist die Zutat?" (GP-Klassifikation):** Warengruppe (§3, 15 WG) → Sub-Kategorie → GP. Steuert Suche/Filter/Disposition/Allergen-Vererbung. Tabellen `foodalchemist_gps` + Lookups. ✅
- **Achse B — „Wie verkauft sich das Gericht?" (VK-/Speisen-Klassifikation):** Hauptgruppe → Klasse (+ Aufschlagsklasse). Steuert Foodbook-Filter, Aufschlag, Preisbildung. `foodalchemist_dish_main_groups` + `dish_classes` + `markup_classes` (Migration 299, Modell A). ✅ (in der Alt-Spec noch „fehlt").

## 5. Mandanten-Architektur — Master-Vererbung (2026-07-12)

- **BHG.DIGITAL (Root) = Master.** Globaler Seed (`team_id NULL`) + Master-Katalog **kaskadieren** zu den Kind-Teams (alle Caterer sind direkte Kinder). Jedes Team verwaltet Eigenes; Master/Seed sind **read-only** für Kinder.
- **Regel:** sichtbar = `team_id NULL ∪ Ancestry`; editierbar = eigenes Team.
- **Mechanik:** Trait `Models\Concerns\BelongsToTeamHierarchy` (`scopeVisibleToTeam` / `isOwnedBy`), Helper `Support\TeamScope` (für rohe `DB::table`-Queries). Kein globaler Auto-Scope (Opt-in, damit CLI/Import ohne Auth-Team laufen).
- (Ersetzt die Alt-Spec-Aussage „Multi-Tenancy = Single-User-Tool" — überholt.)

## 6. Rechen-Kern & Geld-Pfad

- **`RecipeRecomputeService`** — Yield · Allergene · Zusatzstoffe · EK, **+ topologische Propagation** (Kahn, Kinder vor Eltern, Diamond-sicher). `recomputeAll` (Bulk) + `recomputeAndPropagate` (Inkrement).
- **Geld:** `DarreichungService` (ek_portion je Form + Delta-Mischpreis, Auto-VK), `MargeService` (VK/Marge-Formel), `KalkulationService`, `SimulationService` (Was-wäre-wenn, read-only, R2.2), `SignalService`/Detektor (Preis-Alarm R2.1), `BenchmarkService` (R2.7), `FeedbackService` (R2.6).
- **Wording:** `WordingResolver`-Kette (interner Name → Kunden-Rechnungstext).
- **Sync-Richtung: EINBAHN SQL → MD** (Vault-Spiegel). Manuelle Edits an gespiegelten Feldern werden überschrieben; freies Feld `notizen_manual` (Regelwerke §9).

## 7. Chemie / Foodpairing — SQL-nativ

- Tabellen `molecules` (~74k) · `ingredient_molecule` (~97k) · `molecule_descriptors` · `ingredient_aroma_vector` · `anchor_*` · **`pairing_computed` (~341k vorberechnete Match-Scores)**.
- `PairingService` (panelRecipe/cohesion/suggest/bridge) rechnet über diese Tabellen — **kein** Graph.
- Gemessene Realität: Coverage ~76 % (FooDB-Datenlimit), Kalibrierung ρ ≈ 0,54, Teller-Kohärenz ~0,2 % = das eigentliche Loch (R6). Detail-Modell → [`VISION.md`](VISION.md).

## 8. Wissens-DB in FA

- `knowledge_documents` / `_aliases` / `_routings` — pflegbar im Browser (#469), deterministisch on-demand in ~48 KI-Prompts injiziert (`AiGatewayService` + `KnowledgeContextService`). Enthält Regelwerke, Domänen, Cross-Cutting, **Muttersaucen**. Kein separates Modul, kein Graph.

## 9. MCP-Ebene (43 Tools, `src/Tools/`)

- Läuft mit `ToolContext(user, team)`. **Reads** über `visibleToTeam` (erbt die Mandanten-Regel), **Writes** über die Services (own-only/`isOwnedBy`, D1).
- **Regel:** MCP wird bei JEDEM Feature/Datenmodell-Change im Lockstep mitgezogen — kein Retrofit (Präzedenz R0.2).

## 10. USPs vs. Necta (bleiben gültig)

Feinere GP-Klassifikation (Warengruppen + Convenience-Subtypen 13.1–13.7) · **GP-Derivate** (`is_derivat`, §11, LIVE-Allergen-Vererbung) · **Flavor-Pairing** (Necta hat null — FA-USP) · KI-Rezept-Beschreibung · `match_confidence` je Zutat · erweiterbare Saison-/Diät-/Pairing-Vokabulare · **Kreativ-Ökonomie live beim Schreiben** (VISION §4.5).

## 11. Bewusst NICHT (Scope-Grenzen)

Kein Lager/Bestellwesen/Touren/Buchhaltung · keine Kostformen/Mensa-Zeitfenster/Subscription-Menüpläne · keine Necta-Cryptic-Varianten · kein Combi-Steamer-HACCP/Standardgarprogramme (nicht MVP) · kein Graph/Neo4j/3-DB · keine 27 GB MISKG-Bilddaten.
*(Korrektur ggü. Alt-Spec: **Multi-Tenancy ist jetzt IN** — siehe §5.)*
