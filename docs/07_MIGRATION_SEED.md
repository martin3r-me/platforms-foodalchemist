---
typ: Migrations-/Seed-Spec (Bestandsdaten-ETL)
stand: 2026-06-11
status: ausgearbeitet
quelle: wawi_1494.sqlite (227 MB, 91 Tabellen, Stand 2026-06-10)
---

# 07 — Migration & Seed (Bestandsdaten-ETL)

> **Auftrag:** Bestandsdaten der Tauri-Alt-App (SQLite, 227 MB, 91 Tabellen) in die Plattform-DB überführen. Disposition pro Tabelle (global / team-eigen / ersetzt / nicht migrieren) ist in [`02_DATENMODELL.md`](02_DATENMODELL.md) §A–§D festgelegt — **diese Spec definiert das WIE**: Reihenfolge, Hygiene, Transformation, Verifikation. Bei Konflikt mit Skript-Code gewinnt das Regelwerk (GP/LA/Basisrezepte).
>
> **Quell-DB ist read-only.** Der ETL liest ausschließlich (`SELECT`), schreibt nie zurück. Schreib-Operationen an der Quelle (Staging-Drop) passieren einmalig im Pre-Flight gegen eine **Kopie** mit Backup.

## 0. Zahlengrundlage (DB-verifiziert, Stand 2026-06-10)

| Tabelle | Zeilen | | Tabelle | Zeilen |
|---|---|---|---|---|
| `supplier_items` | 264.515 | | `wawi_la_structured` | 9.803 |
| `prices` | 221.591 | | `gp_anker_mapping` | 9.509 |
| `allergens` | 139.012 | | `embedding` | 9.017 (**nicht ETLen**) |
| `nutritional` | 127.644 | | `ai_call_log` | 8.594 |
| `declarations` | 112.605 | | `wawi_gp_v2` | 7.774 |
| `pairing_anker_edges` | 23.951 | | `recipes` | 1.407 |
| `recipe_ingredients` | 9.590 | | `suppliers` | 120 |

GP-Status: approved 6.930 · tentative 774 · rejected 58 · merged 12. Trigger: 20 (nicht portiert → Service-Validierung, M1).

---

## 1. ETL-Architektur

**Empfehlung: ein Artisan-Command** `foodalchemist:import-legacy --source=wawi.sqlite [--phase=N] [--fresh] [--dry-run]`, das pro Phase einen dedizierten Seeder/Importer-Service orchestriert. Quelle wird als zweite PDO-Connection (`sqlite`) registriert, gelesen wird **cursor-basiert** (`->cursor()` / `LazyCollection`), nie `->get()` (264k-Tabelle sprengt sonst den Speicher).

**Phasen-Reihenfolge (strikt nach FK-Abhängigkeit):**

| Phase | Inhalt | FK-Voraussetzung |
|---|---|---|
| **P0 Pre-Flight** | Hygiene-Gates (§2) als read-only Audit gegen Quell-Kopie; Staging/Archiv/Legacy droppen (mit Backup); Gate-Report erzeugen. **Blocker stoppen den Lauf.** | — |
| **P1 Vokabulare & Taxonomien** | 20× `vocab_*`, 7× `lookup_*`, `recipe_kategorien`/`_hauptgruppen`, `recipe_kategorie_v2`/`_klasse_v2`, `speisen_*`, `aufschlagsklassen`, `schreibstile` | keine |
| **P2 Lieferanten-Stammdaten** | `suppliers` → `supplier_items` → (`prices`, `allergens`, `nutritional`, `declarations`, `supplier_priorities`), `stamm_lieferant`(`_wg`), `import_meta` | P1 (Einheiten/Lookups) |
| **P3 GP-Welt** | `wawi_gp_v2` → `wawi_la_structured` (FK items+gps), `gp_count_unit_defaults`, `gp_secondary_food_domains`, `gp_anker_mapping` | P1, P2 |
| **P4 Rezepte** | `recipes` → `recipe_ingredients` (FK gp / `referenced_recipe_id`) | P3 |
| **P5 Satelliten** | 12× `recipe_*`-Satelliten, `foodbook*`, `kombination*` | P4 |
| **P6 Pairing** | `pairing_anker_edges`, `flavor_*` (Phase-2-Domäne D-7, Daten global) | P1 |
| **P7 KI-Log & Chat** | `ai_call_log`, `chat_*` (+ `user_id`/`team_id`) | P4 |
| **P8 Plattform-Seed** | `ai_layer*` → `core.semantic_layer` (⚠D3); Wissens-Import (⚠D4) | alle |

**Idempotenz (Re-Run-Fähigkeit):** zentrale **ID-Mapping-Tabelle** `foodalchemist_legacy_id_map (source_table, source_id, target_id BIGINT, target_uuid)`. Jeder Importer schlägt vor dem Insert in der Map nach: existiert das Mapping → skip (oder Update bei `--fresh`). Das erlaubt FK-Umverdrahtung (Alt-`referenced_recipe_id` → Neu-`id`) **und** ist die spätere Referenz für Diff-Reports/Reklamationen. Natural-Key-Fallback wo sinnvoll (`suppliers.necta_id`, `vocab_*.slug`).

**Batch-Größen:** Bulk-`insert()` in Chunks à **2.000–5.000 Zeilen**, jeder Chunk in eigener Sub-Transaktion. Model-Events/Observer während des Bulk **deaktiviert** (`withoutEvents`) — Aggregationen laufen NICHT zeilenweise mit, sondern gesammelt in P-Post (§4). Richtwert: `supplier_items` (264k) ≈ 53–130 Chunks, `prices` (221k) ≈ 45–110, `allergens` (139k) ≈ 28–70.

---

## 2. Hygiene-Gates VOR Export (Blocker vs. Warnung)

Gates laufen in P0 als read-only Audit; Ergebnis → `_seed_gate_report.md`. **Blocker** brechen den Lauf ab, **Warnungen** werden mit Flag mitgenommen (Review-Queue im Ziel, V-10/V-22).

| Gate | Quelle | Befund (DB) | Klasse | Behandlung |
|---|---|---|---|---|
| **G1 needs_review-LAs** | `wawi_la_structured.needs_review=1` | **597** | **Warnung** | mitnehmen mit `needs_review=1` → First-Class-Review-Queue (V-10). KEIN Blocker — sonst gehen 597 LA-Strukturen verloren. |
| **G2 GP-Allergen-Lücke** | nur **16/7.774** GPs mit gepflegtem Allergen | 16 | **Blocker-Entscheid (V-08)** | Vor Export entscheiden: (a) Bulk-Befüllung ODER (b) GP-Ebene offiziell „nur Vererbung". **Empfehlung: (b)** — GP-Allergene bleiben Override-Layer (GL-01), Wahrheit ist die LA-Aggregation. Dann ist G2 nur Doku, kein Blocker. |
| **G3 Rezept-Namen (V-03)** | ALL-CAPS / kein `Typ:`-Syntax | 473 voll-Caps, 480 ohne `:` (Register: 1.399 normalisierungs-bedürftig) | **Warnung, separater Schritt** | Normalisierung **NACH** dem Roh-Import als eigener KI-Schritt (RAG-Reuse, V-04), nicht im ETL — Roh-Name bleibt als `name_legacy` erhalten. Triggert Re-Embed (V-24). |
| **G4a Rezepte ohne EK** | `ek_total_eur IS NULL` | **61** | **Warnung** | importieren, `needs_data_review` flaggen (V-22). Recompute (§4) füllt einen Teil. |
| **G4b Rezepte ohne Nährwert** | `nutri_kcal_per_100g IS NULL` | **92** | **Warnung** | dito |
| **G4c Rezepte ohne Zutaten** | kein `recipe_ingredients`-Eintrag | **18** | **Warnung** | importieren als Stub, flaggen (T4-NULL-Randfälle sind Review, nicht Normalzustand) |
| **G4d GPs ohne `zustand`** | `zustand IS NULL/''` | **594** | **Warnung** | importieren, `needs_review` (§9-Pflichtfeld) — kein stiller Default |
| **G5 Lead-LA §8-Verletzer** | Lead mit `qty IS NULL` o. §8-Filter verletzt (GL-03 A-2/V-22) | (nach P3 messbar) | **Warnung** | flaggen `needs_lead_la_review`; Diff-Report in §4 |
| **G6 Staging/Archiv/Legacy** | `_staging_recipes`, `_staging_gp_matches`(`_per_instance`), `wawi_gp_v2_migration_archive_2026_05`, `lookup_recipe_typ_hg_legacy`, `sqlite_sequence`, `sqlite_stat1` | — | **Ausschluss** | NICHT in Phasen-Whitelist; in Quell-Kopie gedroppt (Backup `*.PRE_SEED.bak`) |

---

## 3. Transformations-Regeln (je Quell-Gruppe)

**Generisch (alle Tabellen, aus Gesamt-Audit M1):**

- **Timestamps → `timestampTz` (UTC):** TEXT-Zeitstempel werden als Europe/Berlin-Wanduhr interpretiert (App lief lokal) und nach UTC konvertiert. Nicht-parsbare/ambige Werte → NULL + Audit-Zeile. Annahme im Gate-Report dokumentieren.
- **JSON-in-TEXT → `jsonb`:** u.a. `ai_call_log.layers_used`, `chat_messages.tool_params_json`/`context_hooks_json`, alle `*_json`-Spalten. Parse-Fehler → Blocker (kein Silent-Drop).
- **`CHECK(x IN (…))` → DB-Enum / PHP-Enum-Cast:** GP-`status`, `recipes.status`/`geschmacksrichtung`/`allergene_konfidenz`, `recipe_ingredients.match_method`, `gp_anker_mapping.rolle`.
- **`COLLATE NOCASE` → `LOWER()`-Index / citext:** v.a. `supplier_items.designation` (Match-Pfad GL-05).
- **`AUTOINCREMENT` → identity; `UuidV7`** je Zeile im Creating-Hook, in die ID-Map geschrieben.
- **20 Trigger NICHT portiert** → Invarianten in Service-Validierung + Observer.

**`zusatz_*` Tri-State-Normalisierung (GL-09 §4.1):** Necta-Domäne ist `{0,1,3,NULL}` (DB: `with_dye` 0=63.141 / 1=45.829 / 3=3.635). Mapping beim Export: **`3 → true` (ja) · `1 → false` (nein) · `0 → NULL` (k.A.) · `NULL → NULL`**. Merge-Semantik im Ziel `ja > nein > unbekannt` (Golden-Tests gelten mit übersetzten Werten unverändert). Gilt für die 18 `declarations`-Spalten → `foodalchemist_item_declarations`.

**Lineage-Quellen-Mapping (GL-07 §2, DB-verifiziert):** Ist-Vokabular ist uneinheitlich → Ziel-Enum **`manual | ki | auto`**:

| Ist-Wert | Vorkommen | → Ziel |
|---|---|---|
| `ai_inferred` | recipes 744, GP-Allergene 15, gp_anker 7.838 | `ki` |
| `ki` | recipes geschmacksr. 915 | `ki` |
| `auto_slug_match` | gp_anker 1.648 | `auto` |
| `auto_neutral` | gp_anker 22 | `auto` |
| `manual` | überall | `manual` |
| `''` / NULL | recipes 663/462 | `NULL` (ungepflegt) |

`*_ai_confidence` → `clamp(0,1)`; `*_ai_begruendung` 1:1; `*_aggregiert_am` → timestampTz.

**`team_id`-Befüllung (⚠D1, Arbeits-Annahme a):** globale Stammdaten (§A: Lieferanten, GP-Welt, Vokabulare, Pairing) → **`team_id = NULL`**. Team-Daten (§B: recipes + Satelliten, foodbook, kombination, chat, ai_call_log) → **BHG-Team-ID** (aus Config `foodalchemist.bhg_team_id`, zur Laufzeit aufgelöst). Necta-`prices.tenant_id` (alle NULL) **fällt weg** — nicht mit Plattform-`team_id` verwechseln. Vorlagen-Kopie an Caterer-Teams = **Snapshot** (⚠D2 a), passiert NICHT im Seed, sondern als spätere App-Aktion.

**Spalten-Bereinigungen beim Port:** `recipe_ingredients`: nur `putzverlust_pct` + `garverlust_pct` übernehmen — `prozent_garverlust` (konstant 0.0), `prozent_in_produkt` (konstant 100.0), `menge_in_g_computed` (NULL) **nicht migrieren** (GL-02 A-6); Doppel-Paar `prozent_garverlust`/`garverlust_pct` → eines bleibt. GP: `wawi_`/`_v2`-Cruft fällt aus Spaltennamen. Plural-Bestand wie GP 29 „Aepfel: …" (150 Treffer) bleibt unangetastet — Matching läuft über Stemmer (GL-12 I7/A-4); Singular-Backfill ist ein separater Hygiene-Task, kein ETL-Schritt.

**Ersetzt statt migriert:** `embedding` (9.017 Blobs) → **neu berechnen** (§4, V-24); `ai_layer*` → `core.semantic_layer`-Seed (⚠D3); `app_settings` → Plattform-Settings + Secret-Store (Gemini-Key NIE als Klartext-Zeile, H1).

---

## 4. Nach-Import-Schritte (P-Post, Reihenfolge verbindlich)

1. **Lead-LA-Neuwahl + Diff-Report (GL-03 A-2 — kritisch).** GPs werden mit ihrem Quell-`lead_la_supplier_item_id` importiert. Dann `recompute_all_lead_las()` im Ziel (PostgreSQL sortiert **NULLS LAST** statt SQLites NULLS FIRST) → erzeugt teils **andere Leads** (GT-1: 31141191 → 29344887). **Vor Übernahme** `_lead_la_diff_report.md` erzeugen (Alt-Lead vs. Soll-Lead je GP über die ID-Map) und reviewen. Erst danach Schritt 2 — sonst rechnen Rezepte mit dem alten Lead.
2. **Recompute-Kaskade komplett (GL-02).** Bulk-Recompute aller 1.407 Rezepte in **topologischer Ordnung (Kahn, §3.4)** — nicht in ID-Reihenfolge (A-4: der Python-Spiegel 206 ist hier falsch). Pipeline pro Rezept: Yield → Allergene (GL-01) → Zusatzstoffe (GL-09) → Kosten → Nährwerte (GL-08) → Spec-Flags, jedes Rezept in **einer Transaktion** (V-07). Liefert EK/Allergene/Zusatzstoffe/Nährwerte frisch.
3. **Kanten-Symmetrie-Reparatur (V-23).** `pairing_anker_edges` hat **175 asymmetrische Kanten** (A→B ohne B→A, DB-verifiziert). Seed-Gate: fehlende Gegenkante spiegeln (gleicher `typ`/`evidenz`). Verifikations-Query muss danach **0** asymmetrische Kanten liefern (Suggest-Queries GL-10 setzen Symmetrie voraus).
4. **Re-Embed-Job (V-24).** Embeddings im Ziel neu rechnen (Modell/Dim wechselt ggf.) — nach V-03-Namens-Normalisierung und Anker-Mapping; Staleness via `text_hash`. Ziel-Speicher (pgvector vs. Tabelle) = Port-Detailfrage.
5. **Wissens-Import (⚠D4 — ENTSCHIEDEN, Drei-Klassen-Modell).** Eigenes, **wiederholbares** Kommando `foodalchemist:knowledge-import --source=<vault-export>` (nicht Teil von import-legacy — Wissen wird auch NACH dem Seed regelmäßig aktualisiert). Scope = **nur Klasse A**: 07.01 Cross_Cutting (33) + Domains (36) + 07.02 `pairings/` (767) + Regelwerk-Snippets; Aliasse (258 aus `vault_context.rs:39-322`) + Routings (GL-13 §4.1) als Seed-Daten. Upsert per slug + content_hash (unverändert → skip). **Pairing-Kopplung:** beim Einspielen einer Pairing-MD werden die Kanten geparst → `pairing_anker_edges` aktualisiert (inkl. Symmetrie, V-23) → Re-Embed der betroffenen Anker (V-24). FK-Umverdrahtung: `vocab_food_domain.vault_file` + `vocab_pairing_anker.file_path` → `knowledge_document_id`. **Niveau_System geht NICHT hierher**, sondern als Hüllen in den semantic layer (P8/⚠D3). 07.03–07.06 werden NICHT importiert (Klasse B Vault-only / Klasse C Phase 2).

---

## 5. Verifikation

**Row-Count-Matrix Quelle ↔ Ziel** (automatisch aus der ID-Map, mit *erwarteten* Deltas):

| Erwartete Abweichung | Begründung |
|---|---|
| GP: Ziel < 7.774 oder = 7.774 mit Status-Filter | `merged`(12)/`rejected`(58) ggf. ausgefiltert → dokumentieren |
| `embedding`: Ziel 0 (Modul-Tabelle) | neu berechnet, nicht ETLt |
| `foodbook_menu`/`_menu_block`: 0 | leer, nicht migriert (D-8 Befund) |
| Staging/Legacy: 0 | per §2 G6 ausgeschlossen |
| `ai_layer*`: 0 Modul-Zeilen | → `core.semantic_layer` |
| alle übrigen: **exakt gleich** | sonst Blocker |

**Stichproben-Vergleiche — 10 Referenz-Rezepte** (EK + Allergene + Zusatzstoffe gegen persistierte Quell-Werte): die Golden-Anker 1612 (ROTE-BETE-FOND), 1340 (ROTE BETE GEL, 2-Ebenen-Sub), 195 (Sambal Ketjang), 175 (Frischkäse-Dip), 313 (Gel Hühnerfett, Sub-Vererbung), 1115 (F7.1-Guard), 727 (Leer-Rezept) + 3 frei gewählte. **Erwartung: identische Werte — Abweichungen NUR dort, wo der Lead-LA-Diff-Report (§4.1) sie erklärt** (geänderter Lead → geänderter EK). Jede unerklärte Abweichung ist ein Blocker.

**Abnahme-Kriterien:**
- [ ] Row-Count-Matrix grün (nur erwartete Deltas)
- [ ] 10/10 Referenz-Rezepte stimmen überein bzw. sind durch Diff-Report erklärt
- [ ] 0 asymmetrische Pairing-Kanten (V-23)
- [ ] Alle Warnungs-Gates (§2) als Flags in der Review-Queue sichtbar, kein stiller Default
- [ ] Lead-LA-Diff-Report reviewed & abgenommen
- [ ] Keine Klartext-Secrets in der Ziel-DB (H1)
- [ ] `--dry-run` eines Vollaufs ohne Fehler

---

## 6. Rollback / Wiederholbarkeit

- **Transaktion pro Phase, nicht pro Gesamtlauf.** Jede Phase ist eine logische Einheit; große Tabellen (P2/P3) werden in Chunk-Sub-Transaktionen geschrieben (§1) — ein 264k-Insert in EINER TX sprengt Locks/WAL. Phase bricht ab → Phase-Rollback + ID-Map-Einträge der Phase verwerfen, vorherige Phasen bleiben stehen.
- **Truncate-and-Reload für Dev-Iteration:** `--fresh` truncatet alle `foodalchemist_*` + `legacy_id_map` (FK-Reihenfolge umgekehrt) und seedet komplett neu — der schnelle Weg beim Iterieren am Mapping. **Niemals gegen Prod.**
- **Prod: idempotenter Re-Run** über die ID-Map (Upsert by source_id) — ein zweiter Lauf darf keine Dubletten erzeugen und ist die Wiederholbarkeits-Garantie.
- **Quell-Backup vor jedem Eingriff:** Staging-Drop (G6) nur gegen `wawi_1494.sqlite.PRE_SEED.bak`; die Original-Quelle bleibt unberührt read-only.
- **Reproduzierbarkeit:** Gate-Report, Lead-LA-Diff-Report und Row-Count-Matrix werden je Lauf mit Zeitstempel archiviert — der Seed ist damit auditierbar nachvollziehbar.
