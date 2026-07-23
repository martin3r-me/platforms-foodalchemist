# Spec-Status-Matrix (01–19)

> Schnell-Sicht: Status + Blocker je Kern-Spec. Detail je Spec in der jeweiligen Datei; Reihenfolge/Phasen in [00_Orchestrierung_Naechste_Schritte.md](00_Orchestrierung_Naechste_Schritte.md).
> Stand **2026-07-23**. Legende: 🟢 bau-reif · 🟡 entscheidungs-reif (benannter Blocker) · ⚪ Dossier · ✅ fertig.

| # | Spec | Status | Blocker / Offen |
|---|---|---|---|
| **01** | Editor-Strecke #511/#509 | ✅ gebaut+gepusht (`0eac4fe`) | Live-Klickstrecke → **demo-Deploy (Martin)** |
| **02/02b** | RAG-Layer #507 (semantische Suche) | ✅ gebaut, Go-Live-Runbook liegt | **Martin: OpenAI-Key (Core-Contract) + `update.sh`-Deploy** → dann Backfill/Floor/Flag |
| **03** | KI-Erstell-Flächen #512 (L1–L8) | ⚪ Spec, ungebaut | Bau gegen **Fake-Provider jetzt möglich**; Qualitäts-Gate braucht Key live. L4/L5 rein deterministisch |
| **04** | Grammaturen-Rechner #513 | ✅ **KOMPLETT** | nur Browser-Klickstrecke Bäcker-%-UI (Deploy) |
| **05** | Datenqualitäts-Kaskade | 🟢 Etappe 1 + P5 ✅ | Etappe-2-Bau (KI-Commands) jetzt möglich; echtes Daten-Heilen → **Key live + demo-Daten** |
| **06** | Convenience-Highlights | ✅ **KOMPLETT** (`4de1ca2`) | — |
| **07** | LA-First-GP-Mint überall | ✅ **KOMPLETT** M1–M4 | — |
| **08** | Planungs-/Kreativ-Ebene (Doppel-Diamant) | ⚪ ungebaut | Bau gegen Fake möglich; **Konvergenz-Qualität braucht LLM-Provider live** (einziger echter Rest-Blocker im Ordner) |
| **09** | Pairing-Offense-Trio (R6.8/6.9/6.10) | ✅ **KOMPLETT gebaut** | R6.10 *produktiv* = Q1-Contract (extern/N-Track) |
| **10** | Angebots-Funnel / Brief-Parser R6.2 | 🟢 bau-reif | Bau gegen Fake möglich; scharf erst **nach #492-Blindtest** (Key live) |
| **11** | Hypothesen-/Widerspruchs-Modus R6.11 | ✅ **S1–S4 gebaut 2026-07-19** | E5 verifiziert. S1 Hypothesen (Aroma-Harmonie) · S2 Widerspruchs-Detektor · S3 Lab-Notes-Senke · S4 Kontrast-Hypothesen (Geschmacks-Gegensatz + kuratierte kontrast-Kanten); 3 MCP-Tools, 14 Pest. Einziger Rest = optionales KI-Narrativ (Provider) |
| **12** | Wirtschaftlichkeits-Intelligenz R2-Rest | R2.5 ✅ · R2.4 🟢 · R2.3 🟡 | **R2.4-Solver blocker-frei baubar**; R2.3 gated auf **Q2-Format-Spec** |
| **13** | Preis-/Katalog-Ingest Q2 | Kanal-B 🟢 · Sales-Ist 🟡 · Kanal-A ⚪ | **Kanal-B-Import blocker-frei baubar** (bewusst hinten angestellt); Sales-Ist gated auf echte Beispieldatei; Kanal A extern |
| **14** | Lieferanten-Management R9 | ✅ **KOMPLETT** (Engine+MCP+UI, `1382fc2`) | echtes Spend = Q2 (Nutzungs-Proxy ist v1) |
| **15** | Semantische Suche über Lieferantenartikel (Supplier-Item-Pool) | ⚪ Dossier — **entkoppelt/zurückgestellt via 16** | RAG-Nachzug (Vektor-Pool + Observer + Retrieval); vom WG-Lead-Use-Case (16) NICHT gebraucht → wartet auf echte Freitext-Katalog-Discovery + 50k-Store-/Qdrant-Frage |
| **16** | WG-Lead-gescopter LA-Kandidaten-Finder + On-demand-Klassifikation | ✅ **GEBAUT+GETESTET 2026-07-20** (S1–S5 + Nachzügler, 16+ Pest) | Finder deterministisch live (schärft Spec-07-`mintFromLa`); WG-Scope + Terminologie + **Compound-Anti-Marker (S3 geschlossen)** + Fallback; **WG-Hint im KI-Schema scharf (E1)**. Rest nur noch provider-gated (demo): LLM-`commodity_group`-Emission + S4-ClassifyLaJob-Inhalt |
| **17** | Bestellwesen — mini-WaWi Bestellassistent (N-Track) | ✅ **S0–S3 KOMPLETT & ★ LIVE auf demo** 2026-07-21 (S0 `0d78bd2` · S1 `3daf87d` · S2 `bbc73e3`+`49f6c16` · S3 `ef9a5fc`; FA `dc31c6d`, Migrationen gefahren; E1–E11) · nur S4=Bestand (Nicht-Ziel) offen | Der von [R9 §7](14_Lieferanten_Management_R9.md) ausgeklammerte N-Track, **OHNE Bestand**. Gebinde-Bestellzeile + Bestellschiene je Lieferant (`orders`/`order_lines`, Snapshot+source_contributions, MOQ-Ampel, Status-Guard) + „Bedarf übernehmen" + Bestellungen-Seite + PDF/CSV/mailto-Export; **4 MCP-Tools** (GET/ADD_NEED/SET_STATUS/UPDATE_LINE); `OrderServiceTest` 14/14. Offen: demo-Deploy (Martin). Dev #549 |
| **18** | Produktionsaufträge (Ableger von Spec 17) | ✅ **S0–S3 KOMPLETT** 2026-07-22 (`eac1cd5`·`99e2393`·`228a398`·`c2c25c7`) | Zweite bewusste Ausnahme vom 2026-07-04-Non-Goal (Präzedenz Spec 17), diesmal als vollwertiges Modul-Interface (Browser/DetailPanel/Editor wie Concepter/Gerichte/Basisrezepte statt Tab). `production_orders`/`production_order_lines` je (team, production_date), aggregiert mehrere Ziele/Tag (Nicht-Additivitäts-Rundung), `PlanungsblattService::produktionsblattFuerZiele()` als einzige Erweiterung des Rechenkerns. Absorbiert die alten Planungs-Blätter (`/blaetter` → Redirect). „An Bestellung übergeben" = Einbahn-Handover an Spec 17. **4 MCP-Tools** (GET/ADD_TARGET/SET_STATUS/UPDATE_LINE); `ProductionOrderServiceTest` 14/14 (2 harness-bedingt geskippt). Offen: demo-Deploy |
| **19** | Foodbook-Leitstelle A–Z (Phasen-Cockpit + Kaskaden-Verdrahtung) | 🟢 **bau-reif, freigegeben 2026-07-23** — Umsetzung via autonome Routine (E0.2 läuft) | Nachfolger der Foodbook-Hälfte von Spec 08. 7-Phasen-Checkliste (soft, EIN hartes Gate = Kapitel-Go), Kapitel-Baum n-tief mit Ziel-Vererbung, eigenes Zielgruppen-Vokabular, Duality Paket-Konzept ↔ Einzel-`recipe_ref`, Skizzen-Ebene (`dish_ideas`), 5 additive Migrationen. Erdung via L7/L8 (Spec 03, provider-gated). Detail + Checkliste: [19_Foodbook_Leitstelle_A-Z.md](19_Foodbook_Leitstelle_A-Z.md) |

## Verdichtet

- **Sofort baubar, null Blocker:** 12·S2 (R2.4-Solver) · 13·S1 (Kanal-B) · 03·L4/L5. **(16 = gebaut 2026-07-20; 17·S0–S3 = KOMPLETT 2026-07-21; 18·S0–S3 = KOMPLETT 2026-07-22.)**
- **Baubar gegen Fake-Provider (Qualitäts-Gate später via Key):** 03·L1/L2/L3/L6/L7 · 08 · 10 · 05·Etappe-2 · **19 (deterministische Teile E1–E5 blocker-frei; nur E6.4/E7.4-Erdung provider-gated).**
- **Einziger echter Rest-Blocker im Ordner:** 08 (Konvergenz-Qualität = LLM live). Alle übrigen „Blocker" sind Deploy-/Key-Gates fürs *Live-Feuern*, nicht fürs Bauen.
- **Fertig:** 04 · 06 · 07 · 09 · 11 (S1–S3; nur optionales KI-Narrativ offen) · 14 · **17 (S0–S3, nur demo-Deploy offen)** · **18 (S0–S3, nur demo-Deploy offen)** (+ 01 nur Deploy-Verifikation).

### Korrektur-Log
- **2026-07-19:** „Chem-Import fehlt" (Spec 11) war eine **Fehlannahme aus veraltetem Memory** — an der Dev-DB verifiziert, dass `foodalchemist:import-master` durch ist und die 26 Chemie-/Pairing-Tabellen voll befüllt sind. Spec 11 damit von 🟡 auf 🟢.
