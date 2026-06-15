---
typ: Datenmodell-Mapping
stand: 2026-06-10
status: E2 — vollständige Disposition aller 89 Quell-Tabellen
quelle: wawi_1494.sqlite (227 MB, Stand 2026-06-10)
---

# 02 — Datenmodell: SQLite → `foodalchemist_*`

> **Konventionen (Plattform-Pflicht):** Jede Modul-Tabelle bekommt Prefix `foodalchemist_`, `id` (BIGINT identity), `uuid` (UuidV7, creating-Hook), `team_id` (FK teams — **nullable bei globalen Stammdaten ⚠D1**, NOT NULL bei Team-Daten), `created_at`/`updated_at`, `deleted_at` (SoftDeletes), Models mit `LogsActivity`.
>
> **Typ-Port generell (gilt für alle Tabellen, aus Gesamt-Audit M1):** TEXT-Timestamps → `timestampTz` · JSON-in-TEXT → `jsonb` (betroffen u.a. `context_hooks_json`, `layers_used`, `tool_params_json`) · `CHECK(x IN (…))` → DB-Enum oder PHP-Enum-Cast · `COLLATE NOCASE` → Index auf `LOWER()` bzw. `citext` · AUTOINCREMENT → identity · 20 Trigger werden NICHT portiert — ihre Invarianten wandern in Service-Validierung + Model-Observer (Begründung: Geschäftsregeln gehören in die Service-Schicht, Plattform-Konvention).
>
> **Lineage-Pattern bleibt:** Die Spalten-Suffixe `_quelle` / `_ai_confidence` / `_ai_begruendung` / `_aggregiert_am` werden 1:1 übernommen (Kern-IP, GL-07).

## A — Globale Stammdaten (⚠D1: `team_id` nullable, NULL = global; Pflege durch BHG-Admin-Team)

### A.1 Necta-/Lieferanten-Stammdaten (Read-only für Teams, Seed: ja)

| Quelle | Ziel | Zeilen | Anmerkungen |
|---|---|---|---|
| `suppliers` | `foodalchemist_suppliers` | 120 | |
| `supplier_items` | `foodalchemist_supplier_items` | 264.515 | größte Tabelle; Index auf designation → LOWER()-Index |
| `prices` | `foodalchemist_prices` | 221.591 | Quell-`tenant_id` (Necta-Artefakt, alle NULL) **fällt weg** — nicht mit Plattform-`team_id` verwechseln. Sichtbarkeits-Filter pro Team = offene D1-Detailfrage (Rückvergütungs-Konditionen sensibel) |
| `allergens` | `foodalchemist_item_allergens` | 139.012 | |
| `nutritional` | `foodalchemist_item_nutritionals` | 127.644 | |
| `declarations` | `foodalchemist_item_declarations` | ~ | Zusatzstoff-Deklarationen |
| `supplier_priorities` | `foodalchemist_supplier_priorities` | ~ | Umsatz-Ranking |
| `stamm_lieferant` / `stamm_lieferant_wg` | `foodalchemist_core_suppliers` / `_core_supplier_wgs` | klein | Stamm-Lieferant×WG-Matrix (steuert GL-03/GL-05) |
| `import_meta` | `foodalchemist_import_meta` | 4 | Import-Herkunfts-Metadaten; minimal, mitnehmen |

### A.2 Kuratierte GP-/LA-Welt (Kern-IP, Seed: ja)

| Quelle | Ziel | Zeilen | Anmerkungen |
|---|---|---|---|
| `wawi_gp_v2` | `foodalchemist_gps` | 7.774 | `wawi_`/`_v2`-Cruft fällt weg. status-CHECK (approved/tentative/merged) → Enum. Allergen-Spalten-Block (14×) bleibt (V-08 beachten) |
| `wawi_la_structured` | `foodalchemist_supplier_item_structures` | 9.803 | FK auf supplier_items + gps; `needs_review` → Review-Queue (V-10) |
| `wawi_gp_count_unit_defaults` | `foodalchemist_gp_count_unit_defaults` | klein | vocab_stk_default-Lookup (GL-02) |
| `wawi_gp_secondary_food_domains` | `foodalchemist_gp_secondary_food_domains` | klein | M:N GP↔Food-Domain |
| `gp_anker_mapping` | `foodalchemist_gp_ankers` | 9.509 | rolle='kern'-CHECK → Enum; Cap 1–3 als Service-Regel |

### A.3 Vokabulare & Taxonomien (Seed: ja; CRUD nur Admin-Team; V-20)

| Quelle (Gruppe) | Ziel | Anmerkungen |
|---|---|---|
| `vocab_*` (20 Tabellen: aroma_profil, aromakomponente, behaelter, diaet, einheit, food_domain, funktion, kochequipment, konzept, kuechen_typ, niveau, pairing_anker, position_im_menue, regen_geraet, saison, sektor, serviervehikel, sub_rezept_typ, temperatur, textur) | `foodalchemist_vocab_*` (1:1) | einheitliches Muster: slug UNIQUE, name, sort_order, is_inactive; `vocab_food_domain.vault_file` + `vocab_pairing_anker.file_path` (Vault-Pfade!) → ersetzt durch FK `knowledge_document_id` (⚠D4 entschieden, GL-13 §4.3) |
| `lookup_country, lookup_language, lookup_produkttyp, lookup_recipe_typ, lookup_supplier_type, lookup_unit, lookup_warengruppe` | `foodalchemist_lookup_*` (1:1) | Necta-nahe Lookups; `lookup_recipe_typ` = §1.2-Typ-Vokabular (157 Typen, GL-04/GL-12) |
| `recipe_kategorien`, `recipe_hauptgruppen` | `foodalchemist_recipe_categories` / `_recipe_main_groups` | Produktions-Taxonomie (23 HG + 139 Sub) |
| `speisen_hauptgruppen`, `speisen_klassen` | `foodalchemist_dish_main_groups` / `_dish_classes` | VK-Taxonomie (16 HG × Diätform = 49 Klassen); HG-`code` (HG/VS/SUP/…) wird von Pipe-Naming genutzt (D-6) |
| `aufschlagsklassen` | `foodalchemist_markup_classes` | VK-Kalkulation |
| `schreibstile` | `foodalchemist_writing_styles` | 11 Stile; sprach_duktus + beispiele_md sind Prompt-Material (GL-07) |
| `recipe_kategorie_v2` (30), `recipe_klasse_v2` (185) | `foodalchemist_recipe_categories_v2` / `_recipe_classes_v2` | **Taxonomie v2 (funktions-basiert) ist seit 2026-06 KANONISCH** (Funktion = Primärachse, Sous-Vide = Tag). Migrieren; Verhältnis zu `recipe_kategorien` (Alt-Taxonomie) in E5 dokumentieren — ggf. wird nur v2 Ziel-Taxonomie und recipe_kategorien entfällt |
| `lookup_recipe_typ_hg_legacy` | **nicht migrieren** | explizite Legacy-Tabelle |

### A.4 Pairing-/Aroma-Wissen (Seed: ja — Phase-2-Domäne D-7, Daten aber global)

| Quelle | Ziel | Zeilen | Anmerkungen |
|---|---|---|---|
| `pairing_anker_edges` | `foodalchemist_pairing_anker_edges` | ~ | Anker-Graph (klassisch/modern/kontrast-Gewichte, GL-10) |
| `flavor_compound` / `flavor_ingr_comp` / `flavor_ingredient` | `foodalchemist_flavor_*` (1:1) | FlavorDB-artige Basis (1.529 Ingredients) |

## B — Team-eigene Daten (`team_id` NOT NULL; Seed: BHG-Bestand → BHG-Team, ⚠D2 Vorlagen-Kopie)

| Quelle | Ziel | Zeilen | Anmerkungen |
|---|---|---|---|
| `recipes` | `foodalchemist_recipes` | 1.407 | Herzstück. CHECKs (status, geschmacksrichtung, allergene_konfidenz, 14 Allergen-Spalten) → Enums/Casts. JSON-Spalten → jsonb. `ist_verkaufsrezept`-Flag trennt D-5/D-6 (bleibt EIN Modell, zwei Service-Sichten — wie heute) |
| `recipe_ingredients` | `foodalchemist_recipe_ingredients` | 9.590 | match_method-CHECK → Enum; FK gp/`referenced_recipe_id`; `rolle` + `ist_wertgebend` von Anfang an pflegen (V-21); Doppel-Spalte `prozent_garverlust`/`garverlust_pct` beim Port bereinigen (eine bleibt) |
| `recipe_tags`, `recipe_equipment`, `recipe_customer_names`, `recipe_pairings`, `recipe_anker_mapping`, `recipe_aromakomponenten`, `recipe_sektor_eignung`, `recipe_niveau_eignung`, `recipe_prozess_anker`, `recipe_plate_suggestion`, `recipe_culinary_coherence`, `recipe_processing_log` | `foodalchemist_recipe_*` (1:1) | Satelliten, team_id denormalisiert (Performance bei RLS-Filtern); ON DELETE CASCADE |
| `foodbook`, `foodbook_kapitel`, `foodbook_block`, `foodbook_block_staffel` | `foodalchemist_foodbook*` | klein | Phase 2 (⚠D5) — aktive Welt: foodbook → kapitel-Baum → block (polymorph, 8 Typen) → block_staffel. Schema jetzt mitdenken, da FK-Ziele |
| `foodbook_menu`, `foodbook_menu_block` | **nicht migrieren** | 0 | Legacy-Erstgeneration, leer (E4-Befund D-8) — Alt-Chat-Tools queryen dagegen ins Leere |
| `kombination`, `kombination_block`, `kombination_block_staffel` | `foodalchemist_combination*` | 1 | Phase 2 |
| `chat_conversations`, `chat_messages` | `foodalchemist_chat_*` | 9 | **+ `user_id`** (heute nicht vorhanden); Tool-Audit-Spalten (tool_*_json → jsonb) |
| `ai_call_log` | `foodalchemist_ai_call_log` | 7.903 | **+ `user_id` + `team_id`** — wird Kosten-/Audit-Dimension (V-09, V-16). Bestand: ans BHG-Team |

## C — Ersetzt durch Plattform-Mechanismen (nicht als Modul-Tabellen migrieren)

| Quelle | Ersatz | Anmerkungen |
|---|---|---|
| `ai_layer`, `ai_layer_version` (14/21) | **`core.semantic_layer`** der Plattform ⚠D3 | 1:1-Pattern-Match (war danach gebaut); Inhalte (Hüllen-Texte) werden als Seed in den Plattform-Layer übernommen |
| `app_settings` | Team-/Modul-Settings der Plattform + Secret-Store für KI-Key | Gemini-Key NIE wieder als Klartext-Zeile (Gesamt-Audit H1) |
| `embedding` (9.017) | **neu berechnen** im Ziel (Re-Embed-Job, D-4/V-15) | Vektor-Blobs nicht ETLen — Modell/Dim wechselt ggf.; Ziel-Speicher (pgvector vs. Tabelle) = Detailfrage beim Port |

## D — Nicht migrieren (Arbeits-Artefakte, Gesamt-Audit M2)

`_staging_recipes` (1.351), `_staging_gp_matches`, `_staging_gp_matches_per_instance`, `wawi_gp_v2_migration_archive_2026_05`, `sqlite_sequence`, `sqlite_stat1`, `lookup_recipe_typ_hg_legacy`.

> Vor dem Seed-Export werden diese in der Quelle archiviert/gedroppt (mit Backup) — siehe `07_MIGRATION_SEED.md`.

## E — Offene Punkte für E5 (Seed-Spec)

1. `recipe_kategorie_v2` / `recipe_klasse_v2`: aktiv oder Altlast? (klären, dann A.3 finalisieren)
2. D1-Detailfrage Preise/Konditionen: Sichtbarkeits-Modell pro Team (alle sehen alles vs. Freischaltung pro Lieferant)
3. Views (`v_active_prices`, `v_current_items`, `v_supplier_full`, `v_allergen_named`): als DB-Views nachbauen oder Eloquent-Scopes — Empfehlung: Eloquent-Scopes/Query-Builder (Plattform-üblich), `v_active_prices`-Logik wird GL-11-Spec
4. Hygiene-Gates vor Export: 597 `needs_review`-LAs (V-10), GP-Allergen-Entscheid (V-08), Rezept-Namens-Normalisierung (V-03), Datenlücken (V-22)

## F — Neue Ziel-Tabellen ohne Quell-Pendant

| Ziel-Tabelle | Zweck | Spec |
|---|---|---|
| `foodalchemist_knowledge_documents` / `_knowledge_aliases` / `_knowledge_routings` | KI-Runtime-Wissen (⚠D4 entschieden: Klasse A = Cross_Cutting 33 + Domains 36 + Pairing-MDs 767 + Regelwerk-Snippets; Aliasse ersetzen 258 hartkodierte Slug→Domain-Paare; Routings = Feature→Wissen als Daten) | GL-13 §4.3 |
| `foodalchemist_legacy_id_map` | ID-Mapping Alt→Neu für idempotenten Seed-Import + Diff-Reports | 07_MIGRATION_SEED §1 |
| `foodalchemist_recipe_regenerations` | Multi-Komponenten-Regeneration (eigene Zeile pro Komponente statt Ein-Programm-Modell) | V-19, D-6-Spec |
| `foodalchemist_gp_la_rankings` + `_gp_la_preferences` | Lead-LA-Substitutionskette: globale Rangliste pro GP (aus GL-03-Kaskade) + team-scoped Overlay (Sperren/Pins des Einkäufers) | V-27, GL-03 §6, D-2 |
