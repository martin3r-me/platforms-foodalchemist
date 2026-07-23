# 00 — Orchestrierung: was nach und nach zu tun ist (Einstieg nächste Session)

> **Lies mich zuerst.** Diese Datei ordnet die Specs im Ordner in eine logische Reihenfolge — nach Abhängigkeit + Blocker-Status. Jede Phase liefert **eigenständigen Wert** (Kaskaden-Prinzip: greifen ineinander, laufen aber je für sich).
> **Prinzip der Reihenfolge:** erst Fundament verifizieren, dann härten, dann Flächen, dann die kreative Front. Nie ein Feature auf ein unverifiziertes Fundament stapeln.

---

## Status-Snapshot (Stand 2026-07-18)

| | Stand |
|---|---|
| **#507 E0–E5 + E5-Harness** | ✅ gebaut + gepusht (`main` `9c1bae2`, `ebc1aa4`), Suite 731/732 grün |
| **#508 Revise-Grounding + Hard-Stop-Vorschau** | ✅ done, gepusht |
| **Wartet auf Martin (extern)** | OpenAI-Key via Core-Contract + `update.sh`-Deploy auf demo |
| **07 (LA-First-Mint)** | ✅ **KOMPLETT M1–M4 (2026-07-18)** — Keystone geschlagen; Generator+Revise+MCP minten LA-First |
| **05·P5 Prozessanker-Parser** | ✅ **GEBAUT 2026-07-19** (deterministisch; Command+Service+MCP+10 Pest; demo-Bulk-Apply = Deploy) |
| **06 Convenience-Highlights** | ✅ **KOMPLETT H1–H4 (2026-07-19)** — Migration+Service+Screen+Command+2 MCP+opt-in-Modus+Picker-Filter, 14 Pest |
| **09·S1 R6.8 Aroma-Substitution** | ✅ **GEBAUT+GEPUSHT 2026-07-19** (`63d538c`) — `aromaTrueSubstitutes`+`substitution.SUGGEST`, 3 Pest |
| **09 Pairing-Offense-Trio (R6.8/6.9/6.10)** | ✅ **KOMPLETT GEBAUT 2026-07-19** — `substitution.SUGGEST` + `dish.REVERSE` + `surplus.SUGGEST`, 7 Pest. R6.10 produktiv = Q1-Contract offen |
| **12·S1 R2.5 VK-Snapshot-Governance** | ✅ **GEBAUT 2026-07-19** — Snapshot-Layer+Signal+Settings+MCP `vk_snapshots.GET/RELEASE`, 3 Pest. Offen: Batch-Freigabe-UI + R3.2-Kundensicht (`publishedFor`) |
| **14 Lieferanten-Management (R9.1+R9.2)** | ✅ **KOMPLETT 2026-07-19 (Engine+MCP+UI)** — 5 Migr.+3 Models+Status-Enum+3 Services+2 Signale+**6 MCP-Tools** (`suppliers.GET/PUT/VOLUME`, `supplier_agreements.POST`, `gp_lead.GET/PUT`), 6 Pest. **UI-Slice:** getabtes `SupplierDetail`-Modal (Stammblatt/Konditionen/Absprachen/Dokumente/Bündelung) + Lead-Override-mit-Begründung im `Gps/DetailPanel`, 5 Pest |
| **Spec, ungebaut** | 03 (#512 L1–L8) · 08 (Planungs-/Kreativ-Ebene) · 10 · 11 (🟢 blocker-frei) · 12·S2/S3 · 13 |

---

## Phase 0 — #507 live schalten + verifizieren  · GATE, sobald Martin fertig

**Das ist der erste Handgriff der nächsten Session, sobald „Deploy ist durch".**
→ Runbook abarbeiten: **[02b_RAG_GoLive_Runbook.md](02b_RAG_GoLive_Runbook.md)**
Backfill (`embed --pool=all`) → `embed-eval` (Floor messen) → Floor + Flag setzen → Smoke-Test (`Beef`→Rindfleisch, Bries-Gegenprobe).

**⚠️ Derselbe Key + Deploy entblockt MEHR als #507 — Checkliste Phase 0:**
- [ ] #507 live (02b-Runbook, s.o.)
- [ ] **#511/#509 Live-Klickstrecke** im Browser (Deploy bringt `0eac4fe` mit) → Issues Review→Done ([01](01_Editor_Strecke_Bugs_511_509.md))
- [ ] **R6.1-Blindtest #492** (3 echte Kunden-Briefs → Konzept; brauchte nur den echten LLM-Provider) — Gate für L3/Phase 5
- [ ] **05-Etappe-2 startklar** (KI-Anreicherung auf demo: Anker-Erdung, Serving-Form 329, GP-Lücken-Match 398, gemini_proposed-Verify — [05](05_Datenqualitaets_Kaskade.md)) + demo-Daten-Heilung Etappe 1 (`lead-la-repick`/`gp-allergen-backfill`/`recompute` auf demo)

**Warum zuerst:** Alles Folgende baut auf dem semantischen Layer. Erst beweisen, dass er live wirkt, dann daraufstapeln. Ohne Deploy: Phase 1 ist trotzdem baubar (s.u.) — Phase 0 blockiert Phase 1 NICHT.

---

## Phase 1 — Fundament härten  · blocker-frei, höchster Hebel

Alles hier ist **ohne Key/Deploy** baubar (deterministisch bzw. Fake-Provider-testbar).

1. ✅ **07 · M1 — LA-First-Mint befreien** (`versucheLaZuGp` → `LaFirstGpService::mintFromLa`; Generator delegiert). **Keystone-Unblock erledigt 2026-07-18** (18 Tests grün, ungepusht). Nächster Schritt darauf: M2. **Keystone-Unblock:** killt die Sackgassen (Ruby-Fall) und ist Vorbedingung für L7 + 08.
2. ✅ **07 · M2 — Mint in `syncIngredients`** verdrahtet (E3-Re-Grounding-Block: Bestand-Miss + LA → LA-First-Mint) → **schließt die Revise-Lücke, erledigt 2026-07-18.** Nächster Schritt: M3 (MCP-Tool + `gps.MATCH` mint-if-missing).
3. ✅ **06 · H1–H4 — Convenience-Highlights KOMPLETT (2026-07-19)** (Datenmodell + Kuratierungs-Score/Screen + opt-in-Generierungs-Modus + UI). Echte Haus-Liste zum Pinnen steht.
4. ✅ **05 · P5 — Prozessanker-Parser GEBAUT (2026-07-19)** (deterministisch, 0 LLM: Röst/Grill/Rauch/Karamell/Ferment aus dem Zubereitungstext; kein Zwangs-Anker). Etappe-1-Rest der DQ-Kaskade geschlossen; demo-Bulk-Apply = Deploy.
5. **04 · #513 — `ProportionService`** (Bäckerprozent-Sicht + Extraprozent + Brining + Bloom): entschiedenes Dev-Issue, exakte Formeln als Code, Pest-getestet — sofortiger Küchen-Nutzen, null Abhängigkeit.

*Ergebnis:* Rezept-Flows dead-enden nicht mehr; Haus-Convenience-Liste steht. Doktrin bleibt: **kein GP ohne LA.**

---

## Phase 2 — Erstell-Flächen vervollständigen (#512)  · baut auf Phase 1

4. ✅ **07 · M3 — LA-First-Mint als MCP-Tool** (`gps.MINT_FROM_LA` + `gps.MATCH` mint-if-missing) → **erledigt 2026-07-18**, der Office-Assistent löst den Ruby-Fall selbst. (+M4 Proposal-Reframe ✅)
5. **#512 · L5 — `recipes.GENERATE`** (klein, MCP-Lockstep-Schuld aus #505).
6. **#512 · L1 + L6 — VK-Revise + Rezept-Copilot** (teilen die #508-/Matching-Strecke — zusammen bauen, nicht doppelt).
7. **#512 · L2 — Foodbook-Kapitel-Text** (klein, braucht LLM; #369).

---

## Phase 3 — One-Shot + Wirtschaftlichkeit (#512 L7 + L8)  · braucht Phase 1-Mint + #507 live

8. **L7 — One-Shot-Vollerstellung** (Beschreibung → fertiges, geerdetes, angereichertes Rezept in einem Durchlauf; nutzt 07-Mint + #507-Recall).
9. **L8 — Wirtschaftlichkeits-Glied** (Portion + AK + Darreichung → Auto-VK → W%-Ampel) — das KI-Gericht endet **bepreist + margen-geprüft**.
10. **06 · H3 — Convenience-Toggle** reitet hier am Generator mit (opt-in „bevorzugt aus Haus-Liste", Default aus).

*Ergebnis:* „Erstell mir ein Rezept/Gericht" endet real, bestellbar UND bepreist — der Investor-Beweis.

---

## Phase 4 — Kreative Front: Planungs-/Kreativ-Ebene (08 → abgelöst durch 19)  · das große Stück

> **Split 2026-07-23 (Spec [19](19_Foodbook_Leitstelle_A-Z.md) löst die Foodbook-Hälfte von 08 ab):** Phase 4 zerfällt in **zwei Bau-Blöcke mit unterschiedlichem Gate.**
> - **4a — deterministisch, blocker-frei (Spec 19 E1–E5):** recipe_ref-Schreibpfad, Kapitel-Baum + Coverage-Tiefe, Zielgruppen-Vokabular + Foodbook-Defaults, Kapitel-Ziele + WE-Ampel, Leitstelle-Rail/Checkliste. **Läuft VOR L7/L8** — braucht keinen LLM-Provider und baut das Gerüst, in das die Erdung später greift.
> - **4b — provider-gated (Spec 19 E6.4/E7.4 + 08-Doppel-Diamant):** KI-Divergenz (`foodbook.kapitel_ideen`), Freitext-Erdung am Kapitel-Go, Anpassungs-Schleife. Setzt L7/L8 (Phase 3) + LLM live voraus; ohne Provider „wartet auf KI" (Go scheitert nicht).

11. **08 · P6 / 19 · E6.4 — Concepting-Wissen** befüllen (Kategorie `concept`, Destillation) + `concept`/`konzept`-Dublette konsolidieren + Routings `concept.plan`/`foodbook.plan`. *Kann früher/parallel laufen — unabhängig.*
12. **19 · E1–E5 (4a) — deterministische Leitstelle:** Kapitel-Baum n-tief, Ziel-Vererbung, Zielgruppen-Vokabular, WE-Ampel, Checkliste/Rail. **Vor L7/L8 baubar.**
12b. **08 · Doppel-Diamant + 19 · E6/E7 (4b):** divergente Kreativ-Skizzen → Kapitel-Go → Konvergenz/Erdung (ConceptGenerator R6.1 + 07-Mint + L7/L8 für erfundene Gerichte).
13. **07 · M4 — Proposal-Reframe** (Staging = Beschaffungs-Wunsch, nicht GP-Staging) — passt hier oder in Phase 2.

*Ergebnis:* vorne frei denken (Themen/Gerichte/Pairings), hinten garantiert real. „Grounding-Engine" → „kreativer Co-Pilot".

---

## Phase 5 — Rest (#512)  · nach R6.1-Blindtest / niedrige Prio

14. **L4 — Concepter-Slot-Vorschlag** (deterministisch, jederzeit einschiebbar, klein).
15. **L3 — Foodbook aus Brief** (Gesamt-Flow) — erst nach #492-Blindtest + Phase 4.

---

## Abhängigkeits-Bild

```
Phase 0 (#507 live, Martin) ─── verifiziert das Fundament, blockiert Phase 1 NICHT
Phase 1: 07·M1 (Keystone) → 07·M2 ; 06·H1/H2        [blocker-frei]
   │
   ├─► Phase 2: 07·M3 ; L5 ; L1+L6 ; L2
   │
   └─► Phase 3: L7 + L8 (braucht 07·M1) ; 06·H3       [voller Wert erst mit #507 live]
          │
          └─► Phase 4: 08 (P6 parallel) ; 07·M4
                 │
                 └─► Phase 5: L4 ; L3 (nach #492)
```

---

## Quer-Invarianten (in JEDER Phase)

- **Kein GP ohne LA** (07-Doktrin) — Mint ist LA-belegt, tentative, ReviewQueue.
- **Draft + menschliches Go** — nichts wird autonom committet/aktiviert; „propose, never autonomously commit".
- **Kaskaden-Prinzip** — jede Stufe eigenständig lauffähig + ineinandergreifend.
- **MCP-Fähigkeit ist Pflicht-DoD für JEDES neue Feature** (Dominique 2026-07-18, verschärft R0.2): jede neue Fähigkeit muss auch **agentisch/headless über MCP nutzbar** sein — Tools entstehen MIT dem Feature (gleicher Commit), nie retrofitted. UI ist eine Oberfläche der Fähigkeit, nicht ihr einziger Zugang. Reads team-scoped (`visibleToTeam`), Writes `isOwnedBy` im Service, read-only bis expliziter Commit. Bewusste Ausnahme (reine UI-Kosmetik ohne neue Fähigkeit) wird im Spec **begründet**, nicht stillschweigend gelassen.
- **Verify before claiming** — nie „gefixt/wirkt" ohne echten Lauf gegen echte Daten; Pest + (wo nötig) MySQL-Smoke.
- **Keine Fremdmodul-Änderungen** — nur `platforms-foodalchemist` + Sandbox; Core-Wünsche an Martin.
- **Commit-Sync** — bei jedem Push ROADMAP + Dev-Modul (#-Issue) mitziehen.

---

## Wenn nur EINE Sache Zeit hat
→ **07 · M1** (LA-First-Mint befreien). Es ist der Keystone: killt die Sackgassen, ist blocker-frei, und ist Vorbedingung für die zwei größten Hebel (L7 One-Shot + 08 Kreativ-Ebene).

---

## Karte des Ordners

**Spec-Reifegrade** (vor Bau-Start prüfen — ein Dossier ist KEIN Bauplan):
- 🟢 **bau-reif** = code-kartiert (Datei:Zeile), Etappen+DoD verifiziert → Session kann direkt bauen
- 🟡 **entscheidungs-reif** = Entscheidungen fixiert, Design skizziert → vor Bau: Detail-Kartierung (kurze Planungs-Session)
- ⚪ **Dossier** = strukturierter ROADMAP-Extrakt (DoD+Kontext+Vorfragen) → vor Bau: volle Planungs-Session

- **01** 🟢 — Editor-Strecke #511/#509 (✅ gebaut/gepusht `0eac4fe`; offen: Live-Klickstrecke nach Deploy, F3=05-Etappe-2).
- **02 / 02b** 🟢 — #507 RAG-Layer (gebaut) + Go-Live-Runbook.
- **03** 🟢 — #512 KI-Erstell-Flächen L1–L8 (Update-Banner: #508 done, #507 gebaut).
- **04** ✅ — Modernist/Grammaturen #513 (ProportionService + Editor-UI + Referenztabellen C KOMPLETT, Dev #513 Done).
- **05** 🟢 — Datenqualitäts-Kaskade (Etappe 1 + **P5 Prozessanker-Parser ✅ 2026-07-19**; offen: demo-Heilung, Etappe 2 KI — Martin-Key).
- **06** ✅ — Convenience-Highlights (H1–H4 KOMPLETT 2026-07-19, Dev #528, ROADMAP R8.2).
- **07** 🟡 — LA-First-GP-Mint überall (Keystone-Fundament, M1–M4 gebaut).
- **08** 🟡 — Planungs-/Kreativ-Ebene (Doppel-Diamant + Wissens-Ebene).
- **09** 🟢 — Pairing-Offense-Trio (bau-reif 2026-07-19: R6.8 Aroma-Subst. · R6.9 Dish-Reverse · R6.10 Überschuss; alle graph-code-verankert, R6.10 Mock-Bestand).
- **10** 🟢 — Angebots-Funnel / Brief-Parser R6.2 (bau-reif 2026-07-19: `briefs.PARSE`-Prompt+Tool+Modal; scharf nach #492-Blindtest).
- **11** ✅ **S1–S4 gebaut 2026-07-19** — Hypothesen-/Widerspruchs-Modus R6.11 (Daten-Vorbedingung E5 an Dev-DB verifiziert; **S1 `hypothesizeFor` (Aroma-Harmonie), S2 `widerspruchWissenGraph`-Detektor+Signal, S3 `foodalchemist_lab_notes`+MCP `lab_notes.POST`, S4 `contrastHypothesesFor` (Geschmacks-Kontrast + kuratierte kontrast-Kanten offensiv, MCP `mode=harmonie\|kontrast`), 14 Pest**; einziger Rest = optionales KI-Narrativ). S4 kam aus der User-Frage „werden Kanten auch offensiv genutzt oder nur Aromen?".
- **12** 🟢/🟡 — Wirtschaftlichkeits-Intelligenz R2-Rest (bau-reif 2026-07-19: R2.5 + R2.4 🟢; R2.3 🟡 gated auf Q2-Format-Spec, v0-Matrix auf Feedback interim).
- **13** 🟢/🟡 — Preis-/Katalog-Ingest Q2 (bau-reif 2026-07-19: Kanal B Datei-Import 🟢, Sales-Ist 🟡 gated, Kanal A ⚪ extern).
- **14** ✅ — Lieferanten-Management R9 (KOMPLETT 2026-07-19: Engine+MCP+UI; `SupplierDetail`-Modal + GP-Lead-Override-Begründung; Volumen nur Nutzungs-Proxy, echtes Spend = Q2).
- *(Q4/Q5 Wissens-+Graph-Fundament → lebt in `_FoodBrain_Docs/`, kein eigenes Spec-File. GEPARKT/nicht-Kern: R5 Compliance · N Nachbar-Modul · A Academy.)*

- **15** ⚪ — Semantische Suche über Lieferantenartikel (Supplier-Item-Pool) — RAG-Nachzug (aus Frage „RAG auf Lieferanten/Sortiment?" 2026-07-19): neuer `supplier_item`-Pool + Observer + Retrieval; setzt #507-Go-Live voraus; Master-Backfill = größter Pool. **⚠️ Durch 16 entkoppelt/zurückgestellt** — der aktuelle Use Case braucht keinen Vektor-Pool.
- **16** ✅ **GEBAUT+GETESTET 2026-07-20** — WG-Lead-gescopter LA-Kandidaten-Finder + On-demand-Klassifikation (aus Use Case „Artikel unter den WG-Leads finden, wenn kein GP existiert"). Schärft Spec-07-`mintFromLa` (war `searchGlobal->items()[0]`, naiv). **Kern-Move: kein Qdrant/264k-Pool** — WG-Lead-Scope verengt, Namensmatch = Weg-2-Terminologie. S1 = Reuse (`stammSupplierIdsFor`); neu: `LaCandidateFinder` (S2) + `supplier_ids`-Filter in `baseQuery` + `wgHint` in `mintFromLa` (S3) + MCP-Hint (S5). **S4 `ClassifyLaJob`** async verdrahtet — LLM-Inhalt provider-gated (Key auf demo). **Nachzügler 2026-07-20 (`8bdc493`) erledigt:** Compound-Anti-Marker (S3 geschlossen: „Brie"↛„Kalbsbries") + WG-Hint im `recipe.generator`-KI-Schema (E1 scharf). Rest nur noch demo-Smoke (LLM-`commodity_group`-Emission + S4-Inhalt). Entscheide E1/E2/E4 von Dominique verarbeitet.

**Damit sind die Kern-Funktionen vollständig als Einzeldateien extrahiert (01–14, +15 RAG-Nachzug, +16 LA-Finder).** Die ROADMAP im Modul-Repo bleibt vorerst unverändert (team-facing); Eindampfen auf eine schlanke Spine = separater, abgesprochener Schritt.
- Memory: `project_fa_507_semantic_search`, `_la_first_gp_mint`, `_favorites`, `_planungs_kreativ_ebene`, `feedback_fa_composer_update_procedure` — alle in `MEMORY.md`.

**Planungs-Runde 2026-07-19:** alle sechs ⚪-Dossiers (09–14) via Code-Kartierung auf 🟢 bau-reif (bzw. 🟡 mit benanntem Blocker) gehoben — jede Spec trägt jetzt eine „Code-Kartierung"-Sektion (Datei:Zeile), fixierte Entscheidungen (E1…), Etappen mit Größe + geschärfte DoD + Reuse-vs-Neu-Tabelle. Damit ist der ganze Ordner 01–14 bau-fähig. **Rest-Blocker nur noch 08** (LLM-Provider live für die Konvergenz-Qualität; Bau gegen Fake-Provider trotzdem möglich). **11 ist seit 2026-07-19 blocker-frei** — der vermeintliche „Chem-Import"-Blocker war eine Fehlannahme, die Chem-/Pairing-Tabellen sind an der Dev-DB als voll befüllt verifiziert. **09 (R6.8/6.9/6.10) + 14 (R9 inkl. UI) ✅ komplett 2026-07-19.** Nächste blocker-freie Bau-Kandidaten: **11·S1 (Hypothesen-Modus)**, **12·S2 (R2.4-Solver)**, **13·S1 (Kanal-B-Import)**.

**Nachtrag 2026-07-20:** Spec **16** (WG-Lead-LA-Finder) code-verifiziert **und in derselben Session GEBAUT+GETESTET** (S1-Reuse→S2→S3→S5 + baseQuery-Filter; S4 async verdrahtet, LLM provider-gated; 14 neue + 29 Regression-Pest grün, gepusht). Erkenntnisse: S1-Scope-Resolver existierte bereits (`stammSupplierIdsFor`) → kein Neubau; der Alias-Prefilter musste die *Suche* mit-erweitern (nicht nur das Scoring) — der eine echte Bug. **Spec 15 (LA-Vektor-Pool) bleibt entkoppelt/zurückgestellt.** Offen an Spec 16: S3-Decompounding für Compound-Anti-Marker (Kalbsbries), WG-Hint ins KI-Rezept-Schema, S4-LLM-Verifikation (Provider live).

*Erstellt 2026-07-18. Einstiegspunkt für die Fortsetzung. Reihenfolge = Abhängigkeit + Blocker-Status, nicht in Stein — bei neuem Signal (z.B. Blindtest, Kundendruck) neu priorisieren.*
