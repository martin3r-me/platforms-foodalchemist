---
typ: Verbesserungs-Register
stand: 2026-06-10
status: vorbefüllt aus Audits + Backlogs — wird in E4 pro Domäne verfeinert
---

# 10 — Verbesserungs-Register („besser, nicht gleich")

> **Zielbild (Dominique, 2026-06-10):** Der Rewrite muss die erarbeiteten Kernlogiken **mindestens erreichen** (→ Golden-Tests in `04_GRUNDLOGIKEN/` + `09_TESTKATALOG.md`) — **und soll besser werden**. Dieses Register sammelt alle bekannten Schwächen/Chancen mit ID. Domänen-Specs (`05_DOMAENEN/`) referenzieren per `V-xx`. Nichts hiervon ist Parität — alles ist bewusste Verbesserung gegenüber der Tauri-App.

## A — Aus den Audits (bekannte Schwächen NICHT mitnehmen)

| ID | Verbesserung | Quelle | Domäne | Prio |
|---|---|---|---|---|
| V-01 | **Per-Feature-Modell-Tiering** statt Blanket-Modell: Tier A (Qualität) / B (Mechanik-Labels, billig) / C (Vision) je KI-Feature konfigurierbar | _Audit_KI_Layer_2026-06-04 §4 | D-4 | hoch |
| V-02 | **Degenerations-Schutz generalisieren**: steigende-Temp-Retry für ALLE langen Einzeltext-Felder (heute nur `zubereitung`) | _Audit_KI_Layer §2.2 | D-4 | mittel |
| V-03 | **Rezept-Pool-Normalisierung** (1.399 Legacy-ALL-CAPS-Namen → §1-Syntax `Typ: Bezeichnung`) als Teil des Seed-ETL — der Reuse-Unlock | _Audit_VK_Matching Hebel 2 | D-5 / Seed | **hoch** |
| V-04 | **Reuse-at-Generation**: Retrieval bestehender Rezepte/Sub-Rezepte VOR der KI-Benennung (RAG), statt teurem Nach-Matching | _Audit_VK_Matching Hebel 3 | D-5/D-6 | hoch |
| V-05 | **Matcher-Decompounding** (Kompositum↔Split: „Kürbispüree" ↔ „Püree: Kürbis") | _Audit_VK_Matching Hebel 4 | D-5 | mittel |
| V-06 | **Typisierte Fehler mit Codes** statt `Result<T, String>` — Laravel-Exceptions + einheitliches Fehler-Envelope für Livewire/Tools | Gesamt-Audit H3 | alle | hoch |
| V-07 | **DB-Transaktionen für Multi-Step-Writes** (Rezept+Zutaten, Accept-Flows) — heute Einzel-Executes | Gesamt-Audit M | alle W-Commands | hoch |
| V-08 | **GP-Allergen-Lücke schließen**: Bulk-Befüllung (16/7.774 gepflegt) ODER GP-Ebene offiziell „nur Vererbung" + so dokumentieren | Gesamt-Audit H5 | D-3 / Seed | hoch (Compliance) |
| V-09 | **Observability**: strukturiertes Logging, Korrelations-IDs, KI-Kosten-Dashboard pro Team (ai_call_log wird auswertbar) | Gesamt-Audit M4 | D-4 | mittel |
| V-10 | **needs_review als First-Class-Workflow**: Review-Queue-UI mit Zählern statt verstecktem Flag (597 offene LAs zeigen das Problem) | Gesamt-Audit H6 | D-2 | mittel |
| V-11 | **Schema-Migrationen versioniert** (Laravel-Migrations statt `ensure_*`-Heuristiken) — kommt mit dem Plattform-Pattern gratis | Gesamt-Audit H7 | alle | erledigt sich |

## B — Plattform-Gewinne (gratis oder günstig durch office.bhg)

| ID | Verbesserung | Mechanismus | Domäne |
|---|---|---|---|
| V-12 | **Multi-User + Rollen/Permissions** (Koch pflegt Rezepte, Einkauf pflegt Preise, Viewer liest) | Plattform-Auth + Policies + `check.module.permission` | alle |
| V-13 | **Aktivitäts-Historie** auf jedem Objekt (wer hat wann was geändert) | `LogsActivity`-Trait statt Eigenbau | alle |
| V-14 | **Chat/KI-Aktionen über MCP-Tools** mit Plattform-Approval statt Eigenbau-Tool-Router; erledigt Roadmap-Backlog „Phase 4.8 Schreib-Tools mit Approval-Flow" | `Tools/` rufen Services; Tool-Registry | D-8 |
| V-15 | **Bulk-KI-Läufe als Queue-Jobs** mit Fortschritt + Resume (statt UI-blockierender Loops und launchd-Skripten) | Laravel Queues | D-4 |
| V-16 | **Nutzungsbasierte Abrechnung der KI-Kosten pro Team** möglich | `billables`-Config (Vorbild planner.php) | D-4 |
| V-17 | **Kein Tab-State-Verlust** mehr: jede Entität hat eine URL (Livewire-Routing), Editor-Persistenz inklusive | Routing statt Tab-Unmount | UI |
| V-18 | **Echte Parallelität**: Connection-Pool + Queue ersetzt Single-Writer-Mutex — ein KI-Lauf blockiert niemanden | Plattform-DB-Layer | alle |

## C — Fachliche Backlogs (aus Roadmap/Memory, beim Rewrite gleich richtig bauen)

| ID | Verbesserung | Quelle | Domäne |
|---|---|---|---|
| V-19 | **Multi-Komponenten-Regeneration** (pro Komponente eigenes Regen-Programm, eigene Tabelle) statt Ein-Programm-Modell | Roadmap-Backlog #51 | D-6 |
| V-20 | **Vokabel-CRUD vollständig** (Equipment + Servier-Vehikel hatten in der App kein Pflege-UI) | Roadmap-Backlog #52 | D-1 |
| V-21 | **`ist_wertgebend` / Rollen-Modell für Zutaten** von Anfang an im Datenmodell + Pflege-UI (war in der App tot: 0/1.380 gepflegt) | Projekt-Memory Phase H | D-5 |
| V-22 | **Datenqualitäts-Gates im Seed-ETL**: Rezepte ohne Zutaten/EK/Nährwert, GPs ohne `zustand` werden beim Import geflaggt statt still übernommen | Gesamt-Audit M3 | Seed |

## D — Nachträge aus E3/E4 (2026-06-11)

| ID | Verbesserung | Quelle | Domäne |
|---|---|---|---|
| V-23 | **Anker-Kanten-Symmetrie-Reparatur**: ~175 asymmetrische Kanten in `pairing_anker_edges` beim Seed reparieren (Suggest-Queries nehmen Symmetrie an) — als Seed-Gate mit Verifikations-Query | GL-10 / D-7 | D-7 / Seed |
| V-24 | **Embedding-Re-Compute-Hook**: nach Anker-Re-Mapping oder Namens-Normalisierung (V-03) Embeddings des Objekts neu rechnen (Staleness via text_hash) | GL-10 / D-7 | D-4/D-7 |
| V-25 | **Foodbook-Snapshot/Versionierung aktivieren**: Versand mit fixierten Preisen (Alt-Roadmap 3.4-Konzept) von Anfang an im Ziel-Schema | D-8 | D-8 |
| V-26 | **PDF-Export server-seitig** (Alt-Roadmap 3.4, in Tauri nie gebaut) — im Web-Kontext deutlich einfacher (Browser-Rendering/Headless) | D-8 | D-8 |
| V-27 | **Lead-LA-Strategie + Substitutionskette + Einkäufer-Workflow** (User-Anforderung Dominique 2026-06-11): (1) Einstellung `lead_strategie` = `stamm_lieferant` (Default, wie Ist) \| `guenstigster_preis` — team-weit, optional pro Warengruppe. (2) Pro GP eine **geordnete LA-Kette** (Rang 1 = Lead, Rang 2…n = Ausweich; auto nach GL-03-Kaskade, manuell umsortierbar). (3) Einkäufer-Aktionen: **LA sperren** (mit Grund, optional befristet) → nächster nicht-gesperrter Rang wird wirksamer Lead · **LA pinnen** (= `bevorzugt_lock` aus LA-Regelwerk §8, überlebt Bulk-Neuwahl). (4) Sperren/Pins sind **team-scoped Overlay** über der globalen Kette (löst zugleich die ⚠D1-Frage in GL-03: globaler Default-Lead + Team-Overlay) — Caterer A sperrt einen LA, ohne Caterer B zu beeinflussen. Effektiver Lead-Wechsel triggert GL-02-Recompute (Job) + LogsActivity. | Dominique / GL-03 A-4 | D-2 |
| V-28 | **Team-eigene Preise/Konditionen auf Eltern-LAs** (Overlay-Muster wie V-27, schema-seitig vorgesehen, gebaut erst bei Bedarf) — Nachregistrierung: war in 08/D1 referenziert, fehlte im Register | 08 D1 | D-2 |
| V-29 | **Vorbestellzeiten-Logik** (User-Anforderung Dominique 2026-06-11 — „fehlte auch in Cooking Jarvis"): Quelle liefert `is_preorder` + `preorder_days` (5.340 Artikel real). (1) Felder importieren + im LA-Modal pflegen + Pill in der Artikel-Tabelle [✅ M2-16]. (2) **Logik offen:** Vorlauf-Prüfung beim Einplanen (Produktions-/Bestellkontext: „Artikel braucht n Tage Vorlauf — Bestelldatum vor Produktionsdatum?"), Anzeige im Rezept-Editor bei betroffenen Zutaten, ggf. Lead-Wahl-Hinweis. Verortung (M4-Editor-Warnung vs. eigenes Bestell-Modul) = offener Entscheid Dominique | Dominique 2026-06-11 | D-2/D-5 |

---

**Pflege-Regel:** Neue Erkenntnisse aus E3/E4-Sessions hier mit neuer V-Nummer ergänzen; Domänen-Specs referenzieren nur IDs. Status-Tracking (geplant/in MVP/Phase 2/verworfen) passiert pro Domänen-Spec.
