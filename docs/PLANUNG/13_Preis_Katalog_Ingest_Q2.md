# Preis-/Katalog-Ingest — die Daten-Eingangs-Schnittstelle (Q2, Ex-Necta)

> **ROADMAP-Bezug:** Q2 (Querschnitt, „laufend"), Größe M — **Kern-Infrastruktur**: ohne frische Lieferanten-Preise/Kataloge verliert die Wirtschaftlichkeits-Maschine (R2) ihre Grundlage; **R2.3 ist hart darauf gegated**.
> **Prinzip:** FA ist **Master** — reine EINGANGS-Schnittstelle, kein Rückweg nach draußen.
> **Reifegrad: Kanal B 🟢 · Sales-Ist 🟡 (gated auf echte Beispieldatei) · Kanal A ⚪ (spec-only, extern).** Vorher ⚪ Dossier. Code-Kartierung 2026-07-19.

---

## 0. Code-Kartierung (verifiziert 2026-07-19)

**Import-Basis (reuse):** `ImportSliceCommand` — der `importBulk($pdo,$dry,$srcTable,$targetTable,$pk, fn($row)=>[mapped], skipRow:)`-Transform mit `targetMap()` (legacy_id→new_id-Auflösung), `CHUNK=500`, Transaktion je Chunk, Helfer `nullIfBlank/triState/utcOrNull/mapQuelle` — **das ist die direkte Vorlage für den Datei-Importer** (Quelle = Datei statt SQLite). `ImportMasterCommand` liefert `bindCap()` (mysql 60k/sqlite 30k → dynamische Chunk-Größe), `withoutForeignKeyChecks()`, `rewriteTeamId()`, `copyTable()`-Streaming.

**Ziel-Tabellen existieren alle (1:1 je supplier_item, unique FK):** `foodalchemist_supplier_items` (`article_number`, `designation`, `qty`, units, EANs, +Detail-Felder `vat/origin_country/organic_control_number/ingredients_supplier` aus `..._000017`), `foodalchemist_prices` (`price` dec(12,4), `status`, `valid_to`), `foodalchemist_supplier_item_structures` (LA→GP), `foodalchemist_item_nutritionals` (44 BLS-Spalten + `raw_json`), `foodalchemist_item_allergens` (14 EU `allergen_{name}`), `foodalchemist_item_declarations` (18 LMIV Tri-State). → **Kanal B schreibt in vorhandene Tabellen, kein neues Schema für den Voll-Artikel.**

**Preis-Writer (reuse):** `PriceService::createFor(Team, item, preis, status='0')` `:123` — append-only: schließt Vorgänger (`activeFor()?->update(['valid_to'=>now()])`) + fügt neue Zeile `valid_to=null` (=aktiv) in einer Transaktion. `activePriceSubquery()` `:43`, `preisTrendBulk()` `:67` (Δ%, füttert R2.1).

**R2.1-Trigger (reuse):** `SignalDetektorService::preisSprungMargeImpact(Team,...)` `:51` → `SignalService::erzeuge(...)` mit dedup `'preis-sprung-gp-{id}-{preis}'`. **Nichts feuert heute automatisch bei Preis-Write** → der Importer muss Recompute + Detektor selbst anstoßen.

**Recompute-Kette (reuse):** `RecipeRecomputeService::recomputeAndPropagate(recipeId)` `:88` (Rezept + transitive Eltern) bzw. `recomputeAll()` `:149` (Kahn-topologisch).

**Lieferbedingungen: existieren NIRGENDS** (grep `mindest/frei_haus/zahlungsziel/kondition` = 0 relevante Treffer; `foodalchemist_suppliers` hat nur name/branch/gln/adr/email_order/is_inactive). → **NEU** (E3, geteilt mit [14](14_Lieferanten_Management_R9.md)/R9).

**Sales-Ist: existiert NICHT** (keine Fact-Tabelle; `sales_layer_tables` = Stammdaten-false-friend). → NEU (geteilt mit [12](12_Wirtschaftlichkeits_Intelligenz_R2-Rest.md)/R2.3).

**MCP:** kein `ingest.*`-Tool. Read-Tool-Muster `ArtikelListTool`; Registrierung `FoodAlchemistServiceProvider:246+`. → beide NEU.

**Vault-Muster (nur konzeptuell, NICHT ausführen):** Skript 92 (Excel→`supplier_priorities`: name-key-Auflösung + Full-Refresh + Rank/Override), Skript 250 (Bankettprofi-Export: Token-F1/Jaccard-Zeilen-Match + Gemini-Identität + Swap-Guard + REVIEW-Liste) — Vorlagen für Supplier-Resolution bzw. Sales-Ist-Zeilen-Matching.

---

## 1. Festgezurrte Entscheidungen (2026-07-19)

| # | Frage | Entscheid | Begründung |
|---|---|---|---|
| E1 | Kanal-B-Format | **Eigene dokumentierte Vorlage (xlsx/csv), eine Zeile je Artikel**, Spalten: Artikel-Nr + Bezeichnung + Preis + Nährwerte + Allergene + Zusatzstoffe + Lieferbedingungen. Reutzt `importBulk`-Transform (Quelle=Datei). | Ziel-Tabellen existieren; nur ein Datei-Reader + Mapping fehlt. |
| E2 | Upsert-Schlüssel | **`(supplier_id, article_number)` primär, EAN als Fallback** — NICHT `legacy_id` (=Necta-Erbe). Neue Dedup/Unique darauf. | FA ist Master; frische Importe kommen ohne Necta-legacy_id. |
| E3 | Lieferbedingungen-Ort | **Neue Spalten auf `foodalchemist_suppliers`** (Mindestbestellwert, Frei-Haus-Grenze, Zahlungsziel, Rückvergütung%) — EINE Migration, von R9 ([14](14_Lieferanten_Management_R9.md)) mitgenutzt. | Konditionen sind per-Lieferant; vermeidet Doppel-Schema mit R9. |
| E4 | Post-Import-Kette | **`createFor` je geänderten Artikel → `recomputeAndPropagate` je betroffenem GP → `preisSprungMargeImpact`.** Idempotent + resumefähig. | Keine stille Drift; Preis-Änderung propagiert bis Marge-Signal. |
| E5 | Frequenz/Verantwortung | **✅ manuell, quartalsweise** (Dominique 2026-07-18) — Datei laden, Command verarbeitet. Watchfolder/Mail später. | Bewusst einfach starten. |
| E6 | Kanal A (Hub) | **spec-only** bis extern spezifiziert; mündet dann in DIESELBE Strecke wie Kanal B. | Hub-Details offen; kein Parallel-Pfad bauen. |

## 2. Etappen

| # | Etappe | Größe | Inhalt |
|---|---|---|---|
| **S1** | Kanal B Datei-Import | L | `FileArticleImportService` + Command `foodalchemist:import-articles {--file --supplier --team --dry-run --apply}`: Datei-Reader (xlsx/csv) → Artikel-Upsert (`(supplier_id,article_number)`) → schreibt `item_nutritionals`/`item_allergens`/`item_declarations` → `PriceService::createFor` → `recomputeAndPropagate` betroffener GPs → `preisSprungMargeImpact`. Idempotent, resumefähig, Backup-Hinweis. |
| **S2** | Lieferbedingungen | S | Migration: Konditions-Spalten auf `foodalchemist_suppliers` (E3) + Import-Mapping; propagiert später in R9-Marge-Sicht. |
| **S3** | MCP | S | `ingest.STATUS` (read-only: letzte Läufe, Lücken-Liste, Preis-Deltas; Template `ArtikelListTool`) + expliziter Datei-Import-Trigger-Tool (menschlich angestoßen). |
| **S4** | Sales-Ist (R2.3-Gate) | M · 🟡 | Format-Spec aus echter Bankettprofi-Beispieldatei dokumentieren (Dominique-Aufgabe) → Fact-Tabelle `foodalchemist_sales_facts` (geteilt mit [12](12_Wirtschaftlichkeits_Intelligenz_R2-Rest.md)/R2.3) + Zeilen-Matcher (Skript-250-Muster, Unmatched→Review). |
| — | Kanal A Hub | ⚪ | spec-only bis extern spezifiziert; gleiche Strecke wie S1. |

## 3. DoD

- [ ] Bestehende Import-Pipeline als reine EINGANGS-Schnittstelle dokumentiert (kein VK-Rückweg — FA ist Master).
- [ ] Katalog-Import-Lücken als Liste geführt + abgearbeitet (z. B. Grønn → Petersilienöl 7900).
- [ ] Preis-Import triggert R2.1-Alarm (neuer EK → Marge-Impact-Signal, keine stille Drift, E4).
- [ ] **Kanal B Voll-Artikel-Import:** dokumentierte Vorlage deckt Preis + Nährwerte + Allergene + Zusatzstoffe + Lieferbedingungen → schreibt `item_nutritionals`/`item_allergens`/`item_declarations` + Konditions-Spalten; Allergen-/Nährwert-Änderungen propagieren via Live-Auflösung (GL-01).
- [ ] **Kanal A Hub:** auf DIESELBE Import-/Validierungs-Strecke, sobald spezifiziert (kein Parallel-Pfad, E6).
- [ ] *(R2.3)* Verkaufs-Ist-Import-Format-Spec dokumentiert + 1 echte Beispieldatei geladen (Skript-250-Muster, Unmatched→Review).
- [ ] *(Hygiene)* Import idempotent + resumefähig; nach Preis-Import Recompute der betroffenen Ketten; Signale statt stiller Änderungen.
- [ ] **MCP-Pflicht:** `ingest.STATUS` read-only + expliziter Import-Trigger; Bulk bleibt artisan.

## 4. Reuse-vs-Neu

| Reuse | Neu |
|---|---|
| `importBulk`-Transform + ID-Map + Helfer; `bindCap`/`copyTable`; 6 Ziel-Tabellen; `PriceService::createFor`/`activePriceSubquery`; `recomputeAndPropagate`/`recomputeAll`; `preisSprungMargeImpact`+`MargeImpactService`+`SignalService`; `ArtikelListTool`-Muster | `FileArticleImportService`+Command; Konditions-Spalten (Migration); `sales_facts`-Tabelle + Sales-Importer; `ingest.STATUS` + Trigger-Tool; Kanal-A-Adapter (später) |

## 5. Offene Vorfragen (vor Baustart)
1. **Quellen-Inventar:** welcher Stamm-Lieferant liefert wie (PDF/Excel/Portal/gar nicht) — Bestandsaufnahme. *(Aufgabe.)*
2. **Format-Spec Verkaufs-Ist** (Bankettprofi-Export) — hartes R2.3-Gate, echte Beispieldatei beschaffen. *(Dominique.)*
3. ~~Frequenz/Verantwortung~~ ✅ manuell quartalsweise (E5).

## 6. Bewusste Nicht-Ziele
- Kein Rückkanal (FA schreibt nichts zu Lieferanten zurück).
- Kein Bestell-/Wareneingangs-Prozess (N-Track).
- Kein Voll-EDI in v1 — Datei-Ingests reichen.

*Verzahnt: R2.1 (Alarm), [12](12_Wirtschaftlichkeits_Intelligenz_R2-Rest.md)/R2.3 (Gate + geteilte `sales_facts`), [14](14_Lieferanten_Management_R9.md)/R9 (geteilte Konditions-Spalten), Skripte 92/250. Dossier 2026-07-18, bau-reif (Kanal B) 2026-07-19.*
