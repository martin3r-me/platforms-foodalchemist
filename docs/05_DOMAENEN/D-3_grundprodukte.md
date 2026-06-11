---
typ: Domänen-Spec
domaene: D-3
stand: 2026-06-10
status: ausgearbeitet
mvp: MVP
---

# D-3 — Grundprodukte

> **Services (stateless):** `GpService`, `GpNamingService`, `GpAggregationService`
> **Hängt ab von:** D-1 (Vokabulare), D-2 (LA-Welt) · nutzt D-4 (`AiGatewayContract`, `AiProposalService`) · **MVP (⚠D5):** ja
> **Kurzbeschreibung:** Kuratierte GP-Welt (7.774 GPs, Kern-IP). Naming deterministisch (GL-12), Allergen-/Nährwert-/Zusatzstoff-Aggregation LA→GP (GL-01/08/09), Derivate (§11), Merge, Platzhalter, vier KI-Autopiloten.

## 1. Scope & Ressourcen

52 Alt-Commands in 33 Ressourcen-Gruppen (aus `03_FEATURE_INVENTAR.md`, Domänen-Filter D-3). Lebenszyklus-Anteile (propose/accept/reject/clear) laufen IMMER über den generischen `AiProposalService` (D-4, GL-07) — hier steht nur der Fach-Write.

| Ressource | Alt-Commands | Ziel `Service::methode` | Livewire | MVP |
|---|---|---|---|---|
| gps / gps_for_picker | `list_gps`, `search_gps_for_picker` | `GpService::list(filter)`, `::searchForPicker(term)` | `Grundprodukte\Index` | MVP |
| warengruppen(+_const) | `list_warengruppen`, `list_warengruppen_const` | `GpService::listWarengruppen()` (Const → Enum/Config) | `Grundprodukte\Index` (Filter) | MVP |
| distinct_formen / distinct_verarbeitungen | `list_distinct_*` | `GpService::distinctFormen()`, `::distinctVerarbeitungen()` | Editor-Selects | MVP |
| gp (CRUD) | `create_gp`, `get_gp`, `update_gp`, `delete_gp` | `GpService::create(dto)`, `::find(id)`, `::update(id, dto)`, `::delete(id)` — create/update validieren via `GpNamingService` (GL-12 I1–I4) | `Grundprodukte\Show`, `\Edit` | MVP |
| gp (KI-Vorschlag) | `ai_suggest_gp` | `AiProposalService::propose('gp_suggest', …)` → §5.1 | `SuggestGpModal` | MVP |
| gp_suggestion | `accept_gp_suggestion`, `reject_gp_suggestion` | `GpService::createFromProposal(dto)` + Stempel via `AiProposalService` | `SuggestGpModal` | MVP |
| derivat_gp | `create_derivat_gp` | `GpService::createDerivat(mutterGpId, dto)` — erzwingt `is_derivat=1`, `derivat_von_gp_id`, `requires_la=0`; Derivat-von-Derivat verboten | `Grundprodukte\Show` | MVP |
| platzhalter_gp | `create_platzhalter_gp`, `delete_platzhalter_gp`, `rename_platzhalter_gp` | `GpService::createPlatzhalter(name)`, `::deletePlatzhalter(id)`, `::renamePlatzhalter(id, name)` | `Grundprodukte\Index` | MVP |
| merge_gps | `merge_gps` | `GpService::merge(leadId, dupId)` — Rezept-/LA-Refs umhängen, dup `status='merged'`, Recompute-Kaskade (GL-02) — in EINER TX (V-07) | `MergeGpModal` | MVP |
| gp_status | `set_gp_status` | `GpService::setStatus(id, GpStatus)` | `Grundprodukte\Show` | MVP |
| gp_aggregated_allergens | `get_gp_aggregated_allergens` | `GpAggregationService::allergens(gpId)` (GL-01 LA→GP inkl. Derivat-Mutter-Pfad, §6) | `Grundprodukte\Show` | MVP |
| gp_aggregated_nutritional | `get_gp_aggregated_nutritional` | `GpAggregationService::nutritionals(gpId)` (GL-08: AVG aktive LAs) | `Grundprodukte\Show` | MVP |
| gp_aggregated_zusatzstoffe | `get_gp_aggregated_zusatzstoffe` | `GpAggregationService::additives(gpId)` (GL-09: MAX alle LAs) | `Grundprodukte\Show` | MVP |
| gp_allergens (Lebenszyklus) | `ai_infer_gp_allergens`, `accept_`, `reject_`, `clear_gp_allergens` | §5.2 — Fach-Write `GpService::setAllergenOverride(id, werte)` + Propagate-Down | `AllergenAutopilotModal` | MVP |
| gp_tags (Lebenszyklus) | `ai_infer_gp_tags`, `accept_`, `reject_`, `clear_gp_tags` | §5.3 — `GpService::setTags(id, tags)` + Rezept-Recompute | `TagAutopilotModal` | MVP |
| gp_domain (Lebenszyklus) | `ai_infer_gp_domain`, `accept_`, `reject_`, `clear_gp_domain` | §5.4 — `GpService::setFoodDomain(id, primary, secondaries)` | `DomainAutopilotModal` | MVP |
| stk_default_g | `ai_suggest_stk_default_g`, `accept_`, `clear_stk_default_g`, `set_stk_default_g_manual` (+ `reject_stk_default_g`, im Inventar nach D-4 gerutscht → Heimat HIER) | `GpService::setStkDefault(id, g, quelle)` — manual-Pfad setzt `quelle='manual'` | `Grundprodukte\Show` | MVP |
| gp_count_unit(s) | `set_/delete_gp_count_unit`, `list_gp_count_units`, `ai_suggest_gp_count_units` | `GpService::setCountUnit(id, einheitId, g)`, `::listCountUnits(id)` + KI-Suggest (§5.6) | `Grundprodukte\Show` | MVP |
| gp_linked_las / las_for_gp_link | `get_gp_linked_las`, `search_las_for_gp_link` | `GpService::linkedLas(id)`, `::searchLasForLink(id, term)` (Schreibseite Mapping/Lead-LA = D-2) | `Grundprodukte\Show` + `LaPickerModal` | MVP |
| las_for_gp (KI) | `ai_suggest_las_for_gp` | `AiProposalService::propose('gp_la_suggest', …)` — Kandidaten-Ranking mit `stamm_lieferant_wg` + Preisen; Accept (`accept_gp_la_suggestions`) liegt in D-2 | `LaPickerModal` (✨) | MVP |
| suggest_sub_kategorie_normalisations | `suggest_sub_kategorie_normalisations` | `GpService::suggestSubKategorieNormalisations()` — mechanische Taxonomie-Hygiene (kein LLM) | Admin-Aktion in `Grundprodukte\Index` | MVP |
| templates / fill_template / instantiate_template | `list_templates`, `ai_fill_template`, `instantiate_template` | **→ D-5** (`RecipeService` — Rezept-Templates; im Inventar nur wegen `wawi_gp_v2`-Reads hier) | D-5 | MVP |
| fertigungstiefe / geschmacksrichtung / rollen | `ai_infer_fertigungstiefe` (+ `accept_fertigungstiefe` aus D-4), `ai_suggest_geschmacksrichtung`, `ai_verteile_rollen` | **→ D-5** (Zielfelder liegen auf `recipes` / `recipe_ingredients`) — dort spezifiziert | D-5 | MVP |

> **Re-Homing-Hinweis:** Der Inventar-Generator hat 6 rezept-zielende Commands D-3 zugeordnet (⚠-Review-Flag) und 2 Lebenszyklus-Splitter nach D-4 verschoben. Verbindlich: **Zielfeld bestimmt die Service-Heimat** — `stk_default_g`-Lebenszyklus komplett hier, Rezept-Features komplett in D-5. Das Inventar bleibt unverändert (generiert), diese Tabelle ist die Korrektur-Schicht.

## 2. Datenmodell-Ausschnitt

Alle Tabellen aus `02_DATENMODELL.md` A.2/A.3 — **globale Stammdaten, `team_id` nullable (⚠D1: NULL = global, Pflege BHG-Admin-Team)**. Plattform-Pflichtspalten (id/uuid/team_id/timestamps/SoftDeletes/LogsActivity) überall, hier nicht wiederholt.

| Tabelle | Fachliche Kern-Spalten | Bemerkung |
|---|---|---|
| `foodalchemist_gps` (7.774) | `gp_key` (UNIQUE über aktive GPs, GL-12 I1), `gp_name`, `hauptzutat_slug`, `warengruppe`, `sub_kategorie`, `zustand` (Enum §9), `verarbeitung`, `form`, `bio`, `status` (Enum `approved\|tentative\|merged`), `is_derivat`, `derivat_von_gp_id` (Self-FK), `requires_la`, `lead_la_id` (GL-03), `n_las_total`, 14× `allergen_<X>` (Override-Block, nullable, V-08) + `allergene_quelle/_ai_confidence`, 11× `tag_*` + 1 Lineage-Satz, `food_domain_id` + Lineage, `stk_default_g` + Lineage | Lineage-Pattern GL-07 §2; `wawi_`/`_v2`-Cruft fällt weg |
| `foodalchemist_supplier_item_structures` (9.803) | `supplier_item_id` FK, `gp_v2_id` FK, `needs_review`, `match_method` | LA↔GP-Brücke; Schreibseite D-2 |
| `foodalchemist_gp_count_unit_defaults` | `gp_id`, `einheit_id`, `gramm` | Stück→Gramm-Brücke (GL-02 / GL-08 §6) |
| `foodalchemist_gp_secondary_food_domains` | `gp_id`, `food_domain_id` | M:N Sekundär-Domains |
| Read-only-Quellen | `item_allergens` (GL-01), `item_nutritionals` (GL-08), `item_declarations` (GL-09), `supplier_items`, aktive Preise via GL-11-Scopes | aus D-2; werden von D-3 NIE beschrieben |

**Beziehungs-Skizze:**

```
foodalchemist_gps ──┬── hasMany ──► supplier_item_structures ──► supplier_items ──► item_allergens /
                     │                                                               item_nutritionals /
                     │                                                               item_declarations (alle global, D-2)
                     ├── belongsTo (mutter) / hasMany (derivate) ──► foodalchemist_gps   (§11.2, max. 1 Ebene)
                     ├── belongsTo ──► vocab_food_domain  (+ belongsToMany via gp_secondary_food_domains)
                     ├── hasMany  ──► gp_count_unit_defaults ──► vocab_einheit (D-1)
                     └── referenziert von ──► recipe_ingredients.gp_v2_id (D-5, team-eigen — Aggregation liest
                                              global, schreibt team-eigene recipes; GL-01 §6 ⚠D1)
```

Casts/Enums: `GpStatus` (`approved|tentative|merged`), `Zustand` (§9-Vokabular), Allergen-Werte-Enum (4-Wert-Modell GL-01 §4.1), Lineage-Enum `manual|ki|auto` (Migration mappt `ai_inferred→ki`, GL-07 §4.3). Indizes: `gp_key` (UNIQUE partial auf status≠merged), `hauptzutat_slug`, `warengruppe`+`sub_kategorie`.

## 3. Services & Methoden (Signaturen-Niveau)

```php
class GpService {
    public function list(GpFilter $f): LengthAwarePaginator;          // Warengruppe, Sub-Kat, Status, Volltext
    public function searchForPicker(string $term, int $limit = 20): Collection;
    public function find(int $id): GpDetailDto;                       // inkl. Domains, Tags, Lineage
    public function create(GpWriteDto $dto, bool $force = false): Gp; // GL-12-Validierung + gp_key-Guard (GT-12-10)
    public function update(int $id, GpWriteDto $dto): Gp;             // Re-Render gp_name, Drift-Warning (I4)
    public function delete(int $id): void;                            // blockt bei Rezept-/LA-Referenzen
    public function createFromProposal(GpProposalDto $dto): Gp;       // Accept-Pfad von §5.1, status='tentative'
    public function createDerivat(int $mutterGpId, DerivatDto $dto): Gp;
    public function createPlatzhalter(string $name): Gp;              // requires_la=0, status='tentative'
    public function merge(int $leadId, int $dupId): MergeResult;      // TX: Refs umhängen + Recompute (GL-02)
    public function setStatus(int $id, GpStatus $status): void;
    public function setAllergenOverride(int $id, array $werte14, LineageDto $l): int; // Rückgabe: recomputete Rezepte
    public function setTags(int $id, array $tags, LineageDto $l): int;
    public function setFoodDomain(int $id, int $primaryId, array $secondaryIds, LineageDto $l): void;
    public function setStkDefault(int $id, ?float $gramm, Lineage $quelle): void;
    public function setCountUnit(int $id, int $einheitId, float $gramm): void;
    public function linkedLas(int $id): Collection;                   // inkl. Lead-Markierung, Preis (GL-11)
    public function suggestSubKategorieNormalisations(): Collection;  // mechanisch, Review-Liste
}

class GpNamingService {                                               // = GL-12, deterministisch, KEIN LLM
    public function render(GpNameParts $p): string;                   // §6-Schema
    public function validate(string $name, GpNameParts $p): NamingResult; // errors[] (Hard) + warnings[]
    public function slugify(string $s): string;                       // byte-identisch zur Rust-Funktion (I6!) — NICHT Str::slug()
    public function buildGpKey(string $hauptzutatSlug, ?string $verarbeitung, ?string $form): string; // immer 3 Slots
    public function normalizeZustand(string $z): string;              // 'tiefgekuehlt' → 'TK' (GL-12 A2)
    public function stem(string $slug): string;                       // stem_german-Port (I7, nur Matcher-Pfad)
}

class GpAggregationService {                                          // Read-Modelle für die Show-Ansicht
    public function allergens(int $gpId): GpAllergenAggregate;        // GL-01: Override > Derivat-Mutter (LIVE) > MAX(LAs)
    public function allergenConfidence(int $gpId): GpAllergenConfidence; // GL-01 §4.5 — NEU (Ist: fehlt, ⚠A1)
    public function nutritionals(int $gpId): GpNutriAggregate;        // GL-08: AVG aktive LAs, Salz = sodium×0.0025
    public function additives(int $gpId): GpAdditiveAggregate;        // GL-09: MAX über alle LAs, 18 Flags
}
```

Regeln: alle Write-Methoden in DB-Transaktionen (V-07); typisierte Exceptions mit Fehler-Code (V-06, z. B. `GpKeyConflictException`, `ManualOverrideException`). Propagate-Down (Allergen-/Tag-Write → Rezept-Recompute) läuft synchron in der TX beim Einzel-GP, als Queue-Job bei Bulk (V-15).

## 4. Livewire-Komponenten & UI-Fluss

UI-Vorlage: `Grundprodukte.tsx` der Alt-App (Master-Detail, Section-Header-Pattern: KI-/Hilfs-Aktionen rechts im Sektions-Kopf). Ziel: jede Entität mit URL (V-17), Modals in `<x-ui-page>`, Design-Tokens aus `config/ui.php`.

| Komponente | Route | Inhalt |
|---|---|---|
| `Grundprodukte\Index` | `/foodalchemist/grundprodukte` | Filterleiste (Warengruppe → abhängige Sub-Kategorie, Status default `approved`, Volltext), Tabelle mit Allergen-Kurzspalte + Derivat-Badge („§11 — erbt Allergene vom Mutter-GP"); Aktionen: Neu (KI/manuell), Platzhalter anlegen |
| `Grundprodukte\Show` | `/foodalchemist/grundprodukte/{gp}` | Sektionen wie Ist-Detail-Pane: Stammdaten · Vault-Domain (✨ Autopilot) · Stück-Einheiten / `stk_default_g` · Tags (✨) · Allergene (✨ Override) · Zusatzstoffe (LMIV, aggregiert aus LAs) · Nährwerte (Ø aus LAs, je 100 g) · Verknüpfte LAs (Lead setzen → D-2, LA-Picker, ✨ LA-Vorschlag). Flavor-Pairing/Kern-Anker-Sektion: Phase 2 (D-7) — Layout-Platz vorsehen |
| `Grundprodukte\Edit` | `/foodalchemist/grundprodukte/{gp}/edit` | Strukturierte Felder → Live-Render des `gp_name` (Render-First, I4); Hard-Errors (§7.1/§9) blocken Save, Drift-/Plural-Warnings inline |
| Modals | — | `SuggestGpModal` (§5.1: LA-Text → Vorschlag, editierbar), `AllergenAutopilotModal`, `TagAutopilotModal`, `DomainAutopilotModal`, `MergeGpModal`, `LaPickerModal` |

KI-Modal-Leitplanken (01_ARCHITEKTUR §5): Konfidenz-Anzeige, Werte vor Übernahme editierbar, Begründung sichtbar, Gap-Surfacing (unbekannte Vokabeln melden statt erzwingen, GL-07 Inv. 6). Accept-Knopf disabled solange `quelle='manual'` — Override-First sichtbar machen statt Fehler kassieren lassen.

**UI-Fluss A — GP-Anlage aus LA-Text (KI-Pfad):**

1. Einstieg aus D-2 (unmapped-LA-Review) oder `Index` → „Neu (KI)": LA-Designation(en) vorbefüllt.
2. `SuggestGpModal` ruft propose (§5.1) → zeigt Struktur-Felder + gerenderten Namen + Konfidenz + Begründung + ggf. Dubletten-Kandidaten (gp_key-/Stemmer-Treffer prominent VOR dem Accept).
3. User editiert Felder → Live-Re-Render des Namens → Accept legt GP `tentative` an, verlinkt den LA, stempelt `accepted_at`; Reject stempelt nur.
4. Folge-Aktionen im neuen `Show`: Autopiloten (Tags/Domain/Allergene) einzeln oder als Kette.

**UI-Fluss B — manueller Editor-Pfad:**

1. `Edit`: strukturierte Felder, `gp_name` read-only-Vorschau (Render-First, I4); „Name übersteuern" als bewusste Aktion mit Drift-Warning.
2. Save → `GpNamingService::validate`: Hard-Errors (§7.1 Verpackungswort, §9 Zustand) blocken; Warnings (Drift, Plural-Verdacht) erscheinen inline, blocken nicht.
3. `gp_key`-Konflikt → Konflikt-Dialog mit Link zum Bestands-GP + „trotzdem anlegen" (`force`, GT-12-10).

## 5. KI-Features dieser Domäne

Alle Features laufen über `AiGatewayContract::callJson` (D-4) + den generischen GL-07-Lebenszyklus des `AiProposalService`. Hüllen aus GL-06 §4.3, Modul-Key kanonisch `grundprodukte`. Tier nach V-01: A = Qualität/Compliance, B = Mechanik-Labels.

**Gemeinsame Regeln (gelten für 5.1–5.6, nicht pro Zeile wiederholt):** JSON-Mode mit Schema, Temperatur ≈ 0.0–0.2; Wissens-Routing nach GL-13 §4.1 = „none" (Label-Features bekommen NUR Hüllen + strukturierte DB-Daten + Vokabular-Listen, keinen Vault-Wissensblock); jede Antwort wird gegen das Ziel-Vokabular validiert (Unbekanntes → `unknown_slugs`, nie schreiben); jeder Call schreibt `ai_call_log` mit `target_table='foodalchemist_gps'` + `target_id` + `team_id`/`user_id`.

| # | Feature (`feature`-Key) | Hüllen (GL-06) | Kontext | Zielfelder | Lebenszyklus (GL-07) | Tier |
|---|---|---|---|---|---|---|
| 5.1 | **GP-Vorschlag aus LA-Text** (`gp_suggest`) | global + module `gp-klassifikation` + field `field-gp-name` (+ `field-ai-begruendung`) | LA-Designation(en), Warengruppen-/§9-Vokabular, Dubletten-Kandidaten via `gp_key`/Stemmer (GL-12) | neues GP: alle Struktur-Felder + gerenderter `gp_name`; Accept legt GP an (`status='tentative'`) + verlinkt den Quell-LA | propose → Review-Modal → accept (`createFromProposal`) / reject; kein clear (kein Bestandsfeld) | A |
| 5.2 | **Allergen-Autopilot** (`gp_allergen_infer`) | global + module `allergen-inferenz` | GP-Name/Struktur + LA-Allergen-Rohdaten aller verknüpften LAs | 14× `allergen_<X>` als **GP-Override** (GL-01 §4.3 Prio 1 — absolut, ersetzt LA-Aggregation) + Lineage | voll (propose/accept/reject/clear); Accept → Rezept-Recompute transaktional (GT-07-2) | A (Compliance) |
| 5.3 | **Tag-Autopilot** (`gp_tags_infer`) | global + module `gp-klassifikation` + field `field-gp-tag-ai-begruendung` | GP-Name/Struktur/Warengruppe | 11× `tag_*` (vegan/halal/bio/…) + EIN Lineage-Satz | voll; Accept → Rezept-Recompute (Tags fließen in Eigenschafts-Aggregate) | B |
| 5.4 | **Domain-Autopilot** (`gp_domain_infer`) | global + module `gp-klassifikation` | GP-Name + Food-Domain-Vokabular (D-1) | `food_domain_id` (primary) + Sekundär-M:N + Lineage | voll; unbekannte Domain → Gap-Surfacing (`unknown_slugs`), nie anlegen | B |
| 5.5 | **Naming-KI** (Teil von 5.1 + Editor-Assist) | field `field-gp-name` (Regelwerk §6 als Hülle) | Struktur-Felder des Entwurfs | nur Vorschlags-Text — Render + Validierung bleiben **deterministisch** in `GpNamingService` (GL-12); die KI kann Hard-Errors (§7.1/§9) nie umgehen | propose-only; Persistenz immer über `create/update` mit Validierung | A |
| 5.6 | Klein-Features: `gp_stk_default_infer`, `gp_count_units_suggest`, `gp_la_suggest` | global (+ field-Begründungs-Hülle) | GP-Struktur bzw. LA-Kandidatenliste (Stamm-Lieferanten-Matrix) | s. §1 | voll bzw. propose+accept | B |

## 6. Verbesserungen gegenüber Ist

| Ref | Bau-Auftrag |
|---|---|
| **V-08 — GP-Allergen-Entscheid (Compliance, vor Seed)** | Ist: nur 16/7.774 GPs mit Override; GPs ohne LA-Allergen-Daten liefern still keinen Beitrag → ein Rezept kann `nicht_enthalten` zeigen, obwohl eine Zutat faktisch unbewertet ist (GL-01 GT-05). **Bau-Auftrag:** (1) Entscheid Dominique: Bulk-Befüllung via Allergen-Autopilot als Queue-Job (V-15) ODER GP-Ebene offiziell „nur Vererbung" deklarieren + dokumentieren; (2) unabhängig vom Entscheid `GpAggregationService::allergenConfidence` nach GL-01 §4.5 bauen (HIGH/MED/LOW/NONE) und LOW/NONE-GPs in die Review-Queue (V-10) geben; (3) Show-Ansicht zeigt pro Allergen die Quelle (Override vs. LA-Aggregat vs. Mutter-GP). |
| **GL-01 ⚠A2 — Derivat-LIVE-Vererbung im Ziel RICHTIG bauen** | Die Ist-App lässt Derivat-Zutaten ohne Override ausfallen (kein Mutter-Join) — Regelwerk §16/§11.2 fordert LIVE-Auflösung über `derivat_von_gp_id`. Ziel implementiert GL-01 §4.3 Prio 2 (eine Ebene; Derivat-von-Derivat bleibt geblockt) — Golden-Test GT-01-06 fixiert das SOLL, nicht das Ist. Dieselbe Auflösungs-Schicht versorgt GL-09 (Zusatzstoffe — gleiche fachliche Lücke): EIN Derivat-Resolver für beide Aggregationen. |
| GL-01 ⚠A1 — GP-Konfidenz | s. V-08 Punkt (2); Konflikt `enthalten`↔`nicht_enthalten` setzt `needs_allergen_review` (Review-Queue V-10). |
| GL-12 A2/A4 | Eingangs-Normalisierung `tiefgekuehlt→TK` im Service; Plural-Namens-Backfill als Seed-Hygiene (mit V-03 bündeln) — Matching bleibt via Stemmer tolerant. |
| GL-12 I6 | `slugify` byte-identisch portieren + Seed-Verifikation: Re-Berechnung aller `gp_key`s erzeugt null neue Kollisionen. |
| V-06 / V-07 | Typisierte Exceptions + TX für Merge, Accept-Flows, Delete-Kaskaden (Accept-Bug-Katalog der Alt-App ist die Mahnung). |
| V-10 / V-17 / V-20 | Review-Queue für `needs_allergen_review` + tentative GPs; URLs statt Tab-State (GP-Sprung aus Rezept-Zutat wird Deep-Link); §6.1-Sammelware-Grenzfälle (GL-12 Tab. C) als pflegbarer Lookup mit Flag-Workflow statt Hardcode. |
| GL-07-Vereinheitlichung | Lineage-Enum `manual\|ki\|auto` auf allen GP-Feldern (Migration `ai_inferred→ki`); `accepted_at`-Stempel-Pflicht via generischer Accept-Action (D-4 §6). |

## 7. Akzeptanzkriterien & Golden-Tests

**GL-Test-Basis (PHPUnit-Datasets aus den GL-Specs):** GL-01 GT-01…08 (insb. GT-04 LA→GP-Merge, GT-06 Derivat-SOLL, GT-07 Override absolut) · GL-08 GT-01…06 · GL-09 GT-01…06 · GL-12 GT-12-01…15 (Naming, Slugs, Anlage-Guard) · GL-07 GT-07-1/2/3/8 (Lebenszyklus am Beispiel GP-Tags).

**Abnahme-Szenarien:**

1. **Dubletten-Guard:** Anlage eines GP mit existierendem `gp_key` (z. B. zweites `tomate|pulverfoermig|`) → `GpKeyConflictException` mit Verweis aufs Bestands-GP; nur `force=true` legt trotzdem an (GT-12-10).
2. **Allergen-Autopilot Ende-zu-Ende:** GP mit `allergene_quelle=NULL` → propose liefert 14 Werte + Konfidenz + Begründung + `call_log_id`; accept schreibt Override + Lineage, stempelt `accepted_at`, recomputed alle betroffenen Rezepte — in EINER TX. Zweiter Accept auf `quelle='manual'` → Fehler, Daten unverändert (GT-07-1/2).
3. **Derivat LIVE:** Rezept mit Zutat „Huehnerfett: frisch" (Derivat, 0 LAs, kein Override); Mutter-GP löst `soja` zu `spuren` → Rezept zeigt `spuren`. Mutter-Wert ändern + Recompute → Rezept folgt (GT-01-06, SOLL-Fixierung).
4. **Merge:** `merge(lead, dup)` hängt alle `recipe_ingredients`- und LA-Struktur-Refs um, setzt dup auf `status='merged'`, stößt Recompute der betroffenen Rezepte an; simulierter Fehler in der Mitte hinterlässt KEINE halben Zustände (TX-Rollback, V-07).
5. **Aggregat-Parität:** `Show` von GP 3672 „Cornflakes: trocken" zeigt `gluten=enthalten` (GL-01 GT-04); Nährwerte = AVG der aktiven LAs (GL-08 §4.1); Zusatzstoffe = MAX über alle LAs (GL-09) — identisch zu den Golden-Werten aus `wawi_1494.sqlite`.
