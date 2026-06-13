---
typ: Korpus-Index
stand: 2026-06-11
status: E1–E5 abgeschlossen — Spec-Korpus VOLLSTÄNDIG; nächster Schritt = Implementierung (Vertical Slice D-1 + GP-Browser)
---

# Food Alchemist — Spec-Korpus (Index)

**Was ist das hier?** Die vollständige Spezifikation für den Rewrite der Desktop-App „Cooking Jarvis" als office.bhg-Modul `platform-foodalchemist`. Geschrieben so, dass ein Laravel-Dev **ohne Rust-Kenntnisse und ohne Vault-Zugriff** implementieren kann.

**Zielbild:** Verbesserte Version der bestehenden App. **Parität der Kernlogiken ist Mindestlatte** (maschinell prüfbar über Golden-Tests), **Verbesserung ist Programm** (`10_VERBESSERUNGS_REGISTER.md`).

## Lesereihenfolge (Onboarding)

1. `01_ARCHITEKTUR.md` — Zielbild, Schichten, Domänen-Schnitt, Reihenfolge
2. `02_DATENMODELL.md` — alle 89 Quell-Tabellen → `foodalchemist_*`-Disposition
3. `08_ENTSCHEIDUNGEN.md` — offene Weichen D1–D5 + Arbeits-Annahmen (**vor dem Codieren lesen!**)
4. `03_FEATURE_INVENTAR.md` — 323 Commands der Alt-App, klassifiziert (generiert)
5. Beim Implementieren: jeweilige `05_DOMAENEN/D-x` + referenzierte `04_GRUNDLOGIKEN/GL-xx`

## Status-Board

| Dokument | Status | Etappe |
|---|---|---|
| 00_INDEX, 01_ARCHITEKTUR, 02_DATENMODELL | ✅ fertig | E1+E2 (2026-06-10) |
| 03_FEATURE_INVENTAR (+ `_tools/extract_inventory.py`) | ✅ generiert (323 Commands, 71 Review-Flags, inkl. E4-Override-Korrekturen) | E1, regeneriert E5 |
| 08_ENTSCHEIDUNGEN (D1–D7) | ✅ Arbeits-Annahmen aktiv, finale Entscheide offen | E1 + E4-Nachträge |
| 10_VERBESSERUNGS_REGISTER (V-01…V-26) | ✅ | E1 + E4-Nachträge |
| regelwerke/ (4 eingefrorene Kopien) | ✅ Stand 2026-06-10 | E1 |
| 04_GRUNDLOGIKEN/GL-01…GL-13 | ✅ ausgearbeitet, **206 Golden-Tests**, Abweichungs-Listen Ist↔Regelwerk | E3 (2026-06-10/11) |
| 05_DOMAENEN/D-1…D-8 | ✅ ausgearbeitet (Scope, Services, UI-Fluss, Verbesserungen) | E4 (2026-06-11) |
| 06_KI_SPEZIFIKATION (42 Prompts inventarisiert, Tiering) | ✅ | E4 |
| 07_MIGRATION_SEED (9 Phasen, Hygiene-Gates, 7 Risiken) | ✅ | E5 (2026-06-11) |
| 09_TESTKATALOG (Paritäts-Abnahme: 206 GL-Cases + 60 Szenarien) | ✅ | E5 |
| 11_UI_PATTERNS (P-1…P-8, aus 5 Ist-App-Referenz-Screens; gewinnt bei Widerspruch ggü. D-Spec-§4-UI) | ✅ | Post-Slice (2026-06-11) |
| **12_ROADMAP (fein-granulare Arbeits-Pakete M0–M8 — DER Abarbeitungs-Fahrplan)** | ✅ lebt — **M0–M6 KOMPLETT ☑, M7 bis auf M7-09 (Martin-blockiert) ☑, M8 komplett ☑ (Stand 2026-06-12, Suite 365/365)**; Details + Belege in den Status-Notizen der Roadmap selbst | Post-Slice (2026-06-12) |
| **14_ROADMAP_PHASE2 (M9+ Arbeits-Roadmap)** | ✅ lebt — **M9 KOMPLETT** (VK-Editor-Vollparität + UI-Runden, Suite 397/397) | 2026-06-12 |
| **15_MASTERPLAN_VISION (Concepter→Vollausbau, M10–M16)** | ✅ lebt — **Concepter komplett** (M10/M10p/M10c, Begriff „Paket", person-unabh., Kategorie-Baum) + **M11 Foodbook Schema+Service** gebaut; Suite 423/423; §9 Feature-Katalog; offen M11-03 Editor-UI, dann M12+ | 2026-06-13 |

**Stand der offenen Punkte (aktualisiert 2026-06-11 nach Code-Eingang):**
1. ~~⚠D3 KI-Konvention~~ → **✅ aus platforms-core beantwortet**: zentraler `LLMProviderContract` + `core.semantic_layer` existieren; Gateway = Fassade, Hüllen-Hybrid (08/D3, GL-06 §6). **Restfragen an Martin:** Embedding-Support (kritisch für Matching-RAG!), Vision, Team-Rate-Limits.
2. ~~⚠D1 Stammdaten-Scope~~ → **✅ durch Core-Code-Präzedenz belegt** (team_id nullable = global, document_templates-Pattern).
3. ~~Referenz-Module~~ → **✅ eingetroffen**: platforms-core (`platform/platforms-core`), planner (`platform/modules/platforms-planner`, 43 Tools/28 Livewire als Vorbild), ui-tailwind (`styles/platforms-ui-tailwind`, ~59 Komponenten — Inventar in 01 §5).
4. **V-08 / D6 / D7**: fachliche Entscheide → Dominique (unverändert offen).

→ **Slice-Erweiterung Lieferanten-Welt (2026-06-11 abends, Sandbox-getestet):** +5 Migrations (`suppliers`, `supplier_items`, `prices`, `supplier_item_structures`, Lead-LA-FK) · +4 Models · Bulk-Import via `legacy_id` + set-basierter ID-Map (264.515 Artikel + 221.591 Preise + 9.803 Strukturen in ~10 min, alle Gates ✅) · **6.093 Lead-LAs real verdrahtet** · LA-Panel im GP-Detail (Lieferant, Gebinde, aktiver Preis, Lead-/Review-/Bio-Badges). Golden-Checks exakt: GT-1 (14 LAs, Lead 31141191 inkl. qty-NULL-Falle sichtbar), GT-2 (Edna), 597 needs_review, 1.383 LA-lose.

→ **Vertical Slice GEBAUT (2026-06-11):** 4 Migrations (`gps`, `lookup_warengruppen`, `vocab_einheiten`, `legacy_id_map`) · 3 Models (UuidV7/SoftDeletes/LogsActivity/TeamOrGlobal-Scope) · Enums (GpStatus, AllergenValue mit GL-01-Rangordnung) · `GpService` · `foodalchemist:import-slice` (idempotent, ID-Map, Lineage-Mapping, UTC-Normalisierung, Row-Count-Verifikation) · Livewire `Gps/Index`+`Show` mit x-ui-Komponenten · Routes/Sidebar/Provider verdrahtet. **21 Dateien php -l grün.** Noch nicht: in Host-App eingebunden (composer) + `migrate` + Import gelaufen — Anleitung siehe Projekt-Memory/Chat.

## Pflege-Regeln

- `03_FEATURE_INVENTAR.md` ist **generiert** — nie von Hand editieren; Korrekturen in die Regeln von `_tools/extract_inventory.py`, dann regenerieren.
- `regelwerke/` sind **eingefrorene Kopien** — Änderungen nur in der Vault-Quelle (Cooking-Jarvis-Vault `07_WISSEN/07.01`), dann neu einfrieren + Datum im Header.
- **Normativität:** Regelwerke > Grundlogiken > Domänen. Innerhalb einer GL: Golden-Testfall > Entscheidungstabelle > Pseudocode.
- Offene Weichen nur als `⚠Dx`-Anker referenzieren — Optionen/Annahmen leben zentral in `08_ENTSCHEIDUNGEN.md`.
- Verbesserungen nur als `V-xx` referenzieren — zentral in `10_VERBESSERUNGS_REGISTER.md`.

## Mapping auf die Dev-Modul-Doc-Seiten (Package `platforms-foodalchemisten`, ID 23)

| dev.docs-Seite | Quelle hier |
|---|---|
| overview | 00_INDEX (gekürzt) |
| architecture | 01_ARCHITEKTUR (+ 04/05 als Unterdokumente) |
| data_model | 02_DATENMODELL |
| api | 03_FEATURE_INVENTAR |
| setup | Template-LLM_GUIDE + 01 §2 |
| testing | 09_TESTKATALOG |
| deployment | 07_MIGRATION_SEED |
| changelog / contributing / troubleshooting | fortlaufend / GIT.HUB-CLAUDE.md / wächst |

## Glossar (Minimal)

| Begriff | Bedeutung |
|---|---|
| **GP** | Grundprodukt — abstrakte Zutat (z.B. „Zanderfilet"), kuratiert, mit Allergen-/Nährwert-Aggregation |
| **LA** | Lieferantenartikel — konkreter Artikel mit Preis; 1 GP ↔ n LAs; „Lead-LA" = kalkulationsführend |
| **Basisrezept** | Produktionsrezept (`ist_verkaufsrezept=0`), kann Sub-Rezepte referenzieren (max 3 Ebenen) |
| **VK / Verkaufsrezept** | verkaufsfähige Speise mit Preis/Marge/Speisen-Klasse |
| **Anker** | Aroma-Kern-Identität (GP 1–3, Rezept 1–5) im Pairing-Graphen |
| **Hülle** | versionierter, scope-gestaffelter System-Prompt-Baustein (global→modul→team→feld) |
| **Lineage** | `_quelle`/`_ai_confidence`/`_ai_begruendung`-Spalten-Trio: wer hat den Wert gesetzt (manual/ki), wie sicher, warum |

## Quellen

- Alt-App: Cooking-Jarvis-Vault `00_SYSTEM/00.07_App/` (Rust/React/SQLite + Audits `_Audit_Architektur_Daten_SaaS_2026-06-10` u.a.)
- Plattform-Rahmen: `GIT.HUB/CLAUDE.md`, `platform/modules/module-template/LLM_GUIDE.md`, Referenz-Configs `demo.bhgdigital.de/config/{ui,planner}.php`
