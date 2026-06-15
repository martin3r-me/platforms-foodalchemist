---
typ: Domänen-Spec
domaene: D-1
stand: 2026-06-10
status: ausgearbeitet
mvp: MVP
---

# D-1 — Vokabulare & Lookups

> **Services (stateless):** `VocabularyService`
> **Hängt ab von:** — (Basis-Domäne, alle anderen lesen ihre Vokabeln) · **MVP-Status (⚠D5):** MVP
> **Kurzbeschreibung:** Pflege aller Vokabular-, Lookup- und Taxonomie-Tabellen (28 Stück) — CRUD, Sortierung, `is_inactive`-Lebenszyklus, GP-Sub-Kategorien, Produktions-Taxonomie. 29 Alt-Commands. Kern-Verbesserung: V-20 (CRUD-Lücken schließen), V-17 (Tab-Monster → eigene URLs).

Diese Spec **referenziert** die Grundlogiken, sie dupliziert sie nicht: KI-Lebenszyklus → GL-07, Hüllen-Resolver → GL-06, GP-Naming/Slug → GL-12. Datenmodell-Detail → `02_DATENMODELL.md` A.3.

## 1. Scope & Ressourcen

Filter `domain_id == D-1` aus `_tools/inventory.csv` (29 Commands, MVP). Die kollabierten Ressourcen-Gruppen sind hier zu vier funktionalen Clustern gebündelt; alle landen im einen `VocabularyService` (je Cluster eine Methoden-Familie). „Const" = Werte heute hartkodiert im Rust-Code → im Ziel **PHP-Enum**, keine Tabelle.

| Ressource (Stamm) | Alt-Commands | Ziel `VocabularyService::` | Livewire-View/Action | MVP |
|---|---|---|---|---|
| **vocab_* (generisch, 20 Tabellen)** repräsentiert durch `kochequipment` | `list_vocab_kochequipment`, `create_vocab_kochequipment`, `update_vocab_kochequipment`, `delete_vocab_kochequipment`, `set_vocab_kochequipment_inactive` | `listVocab(string $table, VocabFilter $f): Collection`, `createVocab(...)`, `updateVocab(...)`, `setVocabInactive(...)`, `deleteVocab(...)` | `Vocab/Index` (Tab je Tabelle) · Row-Inline-Edit, „Inaktiv"-Toggle, Sortier-Drag | ✅ |
| **Necta-Lookups** (`countries`, `languages`, `food_domains`, `einheiten`, `kuechen_typ(en)`, `niveaus`, `sektoren`) | `list_countries`, `list_languages`, `list_food_domains`, `list_einheiten`, `list_kuechen_typen`, `get/set_kuechen_typ`, `list_niveaus`, `list_sektoren` | `listLookup(string $table): Collection`, `setKuechenTyp(array $slugs): void` | `Vocab/Index` (Read-Tabs) · Dropdown-Quellen für andere Domänen | ✅ |
| **Const-Enums** (`zustaende`, `verarbeitungen`, `formen`, `einheiten_vpe`) | `list_zustaende_const`, `list_verarbeitungen_const`, `list_formen_const`, `list_einheiten_vpe_const` | je `enum`-Klasse: `Zustand::cases()`, `Verarbeitung::cases()`, `Form::cases()`, `EinheitVpe::cases()` | keine eigene View — Enum-Casts + Select-Optionen | ✅ |
| **Produktions-Taxonomie** (`recipe_kategorie(n)`, `merge`) | `list_recipe_kategorien`, `create/update/delete_recipe_kategorie`, `merge_recipe_kategorien`, `accept_recipe_kategorie`, `ai_classify_recipe_kategorie` | `listRecipeCategories(?int $hgId): Collection`, `createRecipeCategory(...)`, `updateRecipeCategory(...)`, `deleteRecipeCategory(int $id): void` (blockt bei `recipe_count>0`), `mergeRecipeCategories(int $src, int $dst): int`, `classifyRecipeCategory(int $recipeId): AiResult` (→ §5), `acceptRecipeCategory(AcceptDto $i): int` | `Vocab/RecipeCategories` · Merge-Modal, Delete-Guard, KI-Klassifikations-Modal | ✅ |
| **GP-Sub-Kategorien** (`sub_kategorie(n)`, `distinct`, `overview`, `rename`, `clear`) | `list_sub_kategorien_overview`, `list_distinct_sub_kategorien`, `rename_sub_kategorie`, `clear_sub_kategorie` | `listSubCategories(?string $wg): Collection`, `renameSubCategory(string $wg, string $old, string $new): int`, `clearSubCategory(string $wg, string $value): int` | `Vocab/SubCategories` · Inline-Rename/Merge, „auf NULL setzen" (cross-ref D-3, schreibt auf `foodalchemist_gps`) | ✅ |

> **Cross-Domänen-Hinweis:** Die Recipe-Taxonomie wird von D-5/D-6 konsumiert (Rezept-Kategorisierung), die GP-Sub-Kategorien schreiben auf die GP-Tabelle (D-3). Die **Pflege** sitzt hier; Schreibziele sind in §2 markiert.

**Die 20 generischen `vocab_*`-Tabellen** (ein Pflege-Muster für alle, V-20): `aroma_profil`, `aromakomponente`, `behaelter`, `diaet`, `einheit`, `food_domain`, `funktion`, `kochequipment`, `konzept`, `kuechen_typ`, `niveau`, `pairing_anker`, `position_im_menue`, `regen_geraet`, `saison`, `sektor`, `serviervehikel`, `sub_rezept_typ`, `temperatur`, `textur`. In der Alt-App hatten mehrere davon (u.a. `kochequipment`, `serviervehikel`) **kein** Pflege-UI — das ist der V-20-Auftrag.

## 2. Datenmodell-Ausschnitt

Aus `02_DATENMODELL.md` A.3 (alle global, `team_id` nullable, CRUD nur Admin-Team ⚠D1):

- **`foodalchemist_vocab_*`** (20 Tabellen, 1:1-Muster): `id`, `uuid`, `slug` UNIQUE, `name`, `sort_order`, `is_inactive`, Timestamps, SoftDeletes. `vocab_food_domain.md_path` → FK auf Knowledge-Tabelle ⚠D4 (kein Vault-Pfad mehr).
- **`foodalchemist_lookup_*`** (7 Necta-Lookups): `lookup_country/language/produkttyp/recipe_typ/supplier_type/unit/warengruppe`. `lookup_recipe_typ` = §1.2-Typ-Vokabular (157 Typen, GL-04/GL-12).
- **`foodalchemist_recipe_categories` / `_recipe_main_groups`**: Produktions-Taxonomie (23 HG + 139 Sub). `recipe_count` ist abgeleitet (Guard für Delete). **Offen (02_DATENMODELL E.1):** Verhältnis zur kanonischen v2-Taxonomie (`_recipe_categories_v2`/`_recipe_classes_v2`, funktions-basiert seit 2026-06) — ggf. entfällt die Alt-Taxonomie. Diese Spec pflegt beide, bis E5 entscheidet.
- **Schreibziele außerhalb der Domäne:** `mergeRecipeCategories`/`deleteRecipeCategory` hängen `foodalchemist_recipes.recipe_category_id` um (D-5). `renameSubCategory`/`clearSubCategory` schreiben `foodalchemist_gps.sub_kategorie` (D-3) — als Service-Aufruf, nie direkter Cross-Domain-Model-Zugriff.
- **Const-Enums:** keine Tabellen — die 20 Trigger/CHECKs der Quelle werden nach `02_DATENMODELL` zu PHP-Enums + Casts.

## 3. Services & Methoden (`VocabularyService`)

Signaturen-Niveau (keine Implementierung). Fehler typisiert (V-06), Multi-Step in Transaktion (V-07).

```php
// — Generische Vokabular-Pflege (deckt alle 20 vocab_* ab, V-20) —
public function listVocab(string $table, VocabFilter $filter): Collection;   // include_inactive, Suche, Sortierung
public function createVocab(string $table, VocabInput $input): Vocab;        // slug-Kollision → typisierter Fehler
public function updateVocab(string $table, int $id, VocabInput $input): Vocab;
public function setVocabInactive(string $table, int $id, bool $inactive): void; // Soft-Lebenszyklus statt Delete
public function deleteVocab(string $table, int $id): void;                    // nur wenn 0 Referenzen, sonst Fehler

// — Necta-Lookups (read; kuechen_typ als einzige Set-Operation) —
public function listLookup(string $table): Collection;
public function setKuechenTyp(array $slugs): void;

// — Produktions-Taxonomie —
public function listRecipeCategories(?int $hauptgruppeId): Collection;
public function createRecipeCategory(RecipeCategoryInput $i): int;
public function updateRecipeCategory(int $id, RecipeCategoryInput $i): void;
public function deleteRecipeCategory(int $id): void;                          // Guard: recipe_count == 0
public function mergeRecipeCategories(int $sourceId, int $targetId): int;     // Tx: Rezepte umhängen, Quelle löschen → moved-count

// — GP-Sub-Kategorien (Housekeeping der Freitext-Werte auf GPs) —
public function listSubCategories(?string $warengruppe): Collection;         // overview mit Zählern
public function renameSubCategory(string $wg, string $old, string $new): int;// Tx: alle GPs umbenennen → affected-count
public function clearSubCategory(string $wg, string $value): int;            // value → NULL auf allen GPs

// — KI (siehe §5) —
public function classifyRecipeCategory(int $recipeId): AiResult;             // Tier B, GL-06/GL-07
public function acceptRecipeCategory(AcceptRecipeCategoryDto $i): int;
```

**Design-Entscheid (für den Port):** Die 20 `vocab_*`-Tabellen sind strukturgleich (`slug`/`name`/`sort_order`/`is_inactive`). Statt 20 nahezu identischer Services/Controller bekommt `VocabularyService` einen **`$table`-Parameter gegen eine Whitelist** (Enum `VocabTable`) — eine getestete CRUD-Familie deckt alle ab (DRY, V-20). Pro Tabelle existiert trotzdem ein eigenes Eloquent-Model (für `LogsActivity`/Relations); der Service löst `VocabTable → Model-Klasse` auf. Recipe-Taxonomie und GP-Sub-Kategorien sind **nicht** strukturgleich (eigene Spalten, Cross-Domain-Schreibziele) → eigene Methoden-Familien, kein generisches CRUD.

## 4. Livewire-Komponenten & UI-Fluss

**Ausgangslage Alt-App:** Die Vokabel-Pflege steckte im React-Modul `Klasse.tsx` als **Tab-Monster** (`hauptgruppen | klassen | aufschlag | subkat | stamm_lieferanten | recipe_hg | recipe_kat | behaelter | regen_geraet | schreibstile`) — ein Tab-Wechsel verlor Filter- und Editor-State. Der Rewrite **splittet** das nach Domäne: D-1 erhält die echten Vokabel-/Taxonomie-Tabs; `klassen`/`aufschlag` → D-6, `stamm_lieferanten` → D-2.

- **`Vocab/Index`** (`/foodalchemist/vokabulare`): linke Tab-/Gruppen-Navigation (Lookups · vocab_* · Taxonomie · GP-Sub-Kategorien), rechts Tabelle. **Jeder Tab eine eigene Route** (V-17) — kein State-Verlust mehr. Tabelle mit `x-ui-table`; Inline-Edit, „Inaktiv"-Toggle, Sortier-`sort_order` per Drag.
- **`Vocab/RecipeCategories`**: HG-Filter-Select + Kategorie-Tabelle mit `recipe_count`-Spalte. **Merge-Modal** (Quelle→Ziel, zeigt „N Rezepte werden umgehängt, Quelle gelöscht", Bestätigung als `danger`). **Delete-Guard**: Button disabled + Hinweis „erst mergen/umhängen" wenn `recipe_count > 0` (Alt-App-Verhalten übernehmen).
- **`Vocab/SubCategories`**: WG-Filter, Übersicht mit Zählern; Inline-Rename (= Umbenennen ODER in bestehenden Wert mergen) und „auf NULL setzen". Section-Header-Pattern: KI-Normalisierungs-Vorschläge rechts im Header (Alt-App: `suggest_sub_kategorie_normalisations`).
- **KI-Klassifikations-Modal**: Konfidenz-Anzeige, editierbar vor Übernahme, Begründung sichtbar (GL-07-UX). Modals immer in `<x-ui-page>` (Template-Regel).

**Wiederkehrende UI-Bausteine** (aus der Alt-App als Konzept übernehmen): `include_inactive`-Toggle pro Tabelle (inaktive Vokabeln ein-/ausblenden statt löschen), Inline-Edit mit Enter/Escape-Bestätigung (wie `SubkatRow` in `Klasse.tsx`), `danger`-Bestätigungsdialog vor destruktiven Aktionen (Merge/Delete), Toast-Feedback nach jeder Mutation. Design-Tokens aus `config/ui.php` — keine eigenen Farben.

## 5. KI-Features dieser Domäne

D-1 ist **KI-arm**: genau ein generierendes Feature plus die Accept/Clear-Enden des Lebenszyklus.

| Feature | Alt-Command | Hülle (GL-06) | Ziel-Schreibfeld | Lebenszyklus | Modell-Tier (V-01) |
|---|---|---|---|---|---|
| **Rezept→Kategorie klassifizieren** | `ai_classify_recipe_kategorie` | Hülle „recipe_kategorie" (Zutaten + HG-Kontext) | `recipes.recipe_category_id` + Lineage `_quelle`/`_ai_confidence`/`_ai_begruendung` | propose → review → **GL-07** | **Tier B** (Mechanik-Label, billig — Klassifikation gegen feste Taxonomie, keine Texterzeugung) |

- **Accept/Clear** (`accept_recipe_kategorie`, `clear_sub_kategorie`) sind keine eigenen KI-Calls, sondern die Lebenszyklus-Endpunkte aus **GL-07** (accept setzt Lineage + `needs_review=0`; clear leert das KI-Feld zurück auf NULL). Override-First gilt zentral (GL-07): eine manuelle Kategorie schlägt jeden KI-Vorschlag.
- Confidence-Cap-Regel (GL-07): unsichere Klassifikation < 0.7 → Review-Queue statt Auto-Übernahme.

## 6. Verbesserungen gegenüber Ist

| V-Ref | Was wird hier konkret besser |
|---|---|
| **V-20** | **Vollständiges CRUD für ALLE Vokabel-Tabellen.** In der Alt-App hatten `kochequipment`, `servier-vehikel` u.a. kein Pflege-UI (nur Read). Die generische `VocabularyService`-Familie + eine parametrierte `Vocab/Index`-View decken alle 20 `vocab_*` einheitlich ab. |
| **V-17** | **Kein Tab-State-Verlust.** Das `Klasse.tsx`-Tab-Monster wird in geroutete Livewire-Views aufgelöst — jede Vokabel-Gruppe und jede Entität hat eine URL. |
| **V-11** | **Versionierte Migrationen** statt `ensure_*`-Heuristiken — kommt mit dem Plattform-Pattern gratis; die 20 SQLite-Trigger werden zu Service-Validierung + Enum-Casts (02_DATENMODELL). |
| **V-12 / V-13** | **Rollen + Audit:** Vokabel-Pflege ist Admin-Team-Recht (⚠D1, Policy); jede Änderung an einer global sichtbaren Vokabel ist über `LogsActivity` nachvollziehbar (wer hat `Niveau X` inaktiv gesetzt). |
| **V-06 / V-07** | Slug-Kollisionen, Delete-mit-Referenzen, Merge → **typisierte Fehler**; Merge & Rename laufen **transaktional** (Rezepte/GPs umhängen + Quelle entfernen als eine Einheit — kein halb-migrierter Zustand). |
| **Const→Enum** | `zustaende`/`verarbeitungen`/`formen`/`einheiten_vpe` werden **PHP-Enums** statt Magic-Strings im Code — typsicher, IDE-prüfbar, eine Quelle der Wahrheit für Selects und Casts. |
| **Taxonomie-Konsolidierung** | Die Alt-App führte **zwei** Rezept-Taxonomien parallel: `recipe_kategorien` (alt, 23 HG + 139 Sub) und das seit 2026-06 kanonische, funktions-basierte `recipe_kategorie_v2`/`recipe_klasse_v2`. Der Rewrite löst die Doppelung auf (Empfehlung: nur v2 als Ziel-Taxonomie). **Offene Weiche** — Entscheid in E5 (02_DATENMODELL E.1); bis dahin pflegt diese Domäne beide. |

## 7. Akzeptanzkriterien & Golden-Tests

**Verweis auf GL-Tests:** Der KI-Pfad folgt **GL-07** (Lebenszyklus-Golden-Tests: propose/accept/reject/clear, Lineage-Felder, Override-First) und **GL-06** (Hüllen-Komposition). Diese werden nicht dupliziert.

Domänen-spezifische Abnahme-Szenarien:

1. **AT-D1-01 — Merge hängt um & löscht:** `mergeRecipeCategories(src, dst)` bei `src.recipe_count = 12` → alle 12 Rezepte zeigen danach auf `dst`, `src` ist gelöscht, Rückgabe `12`; läuft in einer Transaktion (Abbruch → kein Rezept umgehängt).
2. **AT-D1-02 — Delete-Guard:** `deleteRecipeCategory(id)` mit `recipe_count > 0` → typisierter Fehler, kein Schreibvorgang; UI-Button disabled mit Hinweistext.
3. **AT-D1-03 — Sub-Kategorie-Rename propagiert:** `renameSubCategory('10','Saft','Säfte')` benennt den Wert auf allen betroffenen GPs um (Rückgabe = affected-count); `clearSubCategory` setzt ihn auf NULL. Schreibt via D-3-Service, nicht direkt.
4. **AT-D1-04 — Inaktiv statt Löschen:** `setVocabInactive` blendet die Vokabel aus allen Auswahl-Dropdowns aus, lässt aber bestehende Referenzen (z.B. Rezepte mit diesem Equipment) intakt; reaktivierbar.
5. **AT-D1-05 — CRUD für jede Vokabel-Tabelle (V-20):** Für jede der 20 `vocab_*`-Tabellen lässt sich über dieselbe View ein Eintrag anlegen/ändern/inaktivieren — inklusive der in der Alt-App ungepflegten (Equipment, Servier-Vehikel).
6. **AT-D1-06 — Klassifikation in Review-Queue (GL-07):** `classifyRecipeCategory` mit Confidence < 0.7 erzeugt einen Vorschlag mit `needs_review`, übernimmt **nicht** automatisch; eine bereits manuell gesetzte Kategorie wird nicht überschrieben (Override-First).
7. **AT-D1-07 — Lookups speisen Dropdowns:** `listLookup`/`listVocab` liefern für jede andere Domäne die Auswahl-Optionen; inaktive Einträge (`is_inactive=1`) erscheinen in neuen Selects nicht mehr, bleiben aber an Bestandsdaten erhalten (keine kaputten Referenzen).
8. **AT-D1-08 — Const-Enums typsicher:** Ein ungültiger Wert für `zustand`/`verarbeitung`/`form` wird beim Cast als typisierter Fehler abgewiesen (kein Magic-String-Drift wie im Rust-Ist).
